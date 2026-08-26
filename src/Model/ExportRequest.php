<?php

namespace MadeCurious\PagePacker\Model;

use MadeCurious\PagePacker\Extensions\PackableExtension;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\ContentTimestampWalker;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Tracks one export bundle for a record — either an actual export job's output (Origin=Export)
 * or the file originally uploaded to create the record via import (Origin=Import, registered as
 * that record's first history entry so an author has an immediate downloadable snapshot of what
 * was imported without needing to trigger a fresh export first).
 *
 * One shared table serves both the SiteTree/CMSMain flow and the generic DataObject/GridField
 * flow — `Record` is declared against the bare `DataObject::class`, which SilverStripe treats as
 * a polymorphic has_one (it adds a companion `RecordClass` column alongside `RecordID`), so this
 * one model works for a page just as well as it does for any other project DataObject with
 * {@see \MadeCurious\PagePacker\Extensions\PackableExtension} applied.
 *
 * Shown as a history list — the page's Content Export tab for a SiteTree page (via
 * {@see \MadeCurious\PagePacker\Extensions\PackableExtension}/`CMSPageContentExportController`),
 * or the record's own edit form for anything else (via
 * {@see \MadeCurious\PagePacker\Extensions\PackableExtension}) — newest first, each with a
 * download link once Status=Complete and a badge indicating staleness.
 */
class ExportRequest extends DataObject
{
    private static $table_name = 'PagePacker_ExportRequest';

    public const STATUS_QUEUED = 'Queued';
    public const STATUS_COMPLETE = 'Complete';
    public const STATUS_FAILED = 'Failed';

    public const ORIGIN_EXPORT = 'Export';
    public const ORIGIN_IMPORT = 'Import';

    private static $db = [
        'Status' => "Enum('Queued,Complete,Failed','Queued')",
        'Origin' => "Enum('Export,Import','Export')",
        // The most recent LastEdited found across the record and everything it owns (see
        // ContentTimestampWalker) at capture time
        'SourceContentTimestamp' => 'Varchar(32)',
        'StatusMessage' => 'Text',
        'Description' => 'Varchar(255)',
        'IncludeAssets' => 'Boolean',
    ];

    private static $has_one = [
        'Record' => DataObject::class,
        'Member' => Member::class,
        'ResultFile' => File::class,
        // Set at queue time (export) or on completion/failure (import) so the Status column can
        // link straight through to the job's own admin/queuedjobs record — its live progress
        // while Queued/Running, or its log/error detail once finished. Left null once the
        // descriptor itself is gone (e.g. purged by symbiote's own cleanup), in which case the
        // Status column just falls back to plain text — see getStatusLinkHtml().
        'QueuedJobDescriptor' => QueuedJobDescriptor::class,
    ];

    private static $owns = [
        'ResultFile',
    ];

    private static $default_sort = 'Created DESC';

    private static $summary_fields = [
        'Created' => 'Date',
        'Description' => 'Description',
        'Origin' => 'Origin',
        'StatusLinkHtml' => 'Status',
        'Member.Title' => 'Requested by',
        'IncludeAssets' => 'Assets included',
        'StaleBadge' => 'Stale',
        'DownloadLinkHtml' => 'File',
    ];

    /**
     * Cast so history GridField renders them unescaped
     */
    private static $casting = [
        'StaleBadge' => 'HTMLFragment',
        'DownloadLinkHtml' => 'HTMLFragment',
        'StatusLinkHtml' => 'HTMLFragment',
    ];

    /**
     * SITETREE_IMPORT_EXPORT for a SiteTree page's history, RECORD_IMPORT_EXPORT for anything
     * else — the two permissions stay independently grantable (see
     * ImportExportPermissions::RECORD_IMPORT_EXPORT's own doc comment) even though they now share
     * one model/table.
     */
    public function canView($member = null)
    {
        return Permission::checkMember($member, $this->permissionCode());
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canView($member);
    }

    public function canEdit($member = null)
    {
        return $this->canView($member);
    }

    public function canDelete($member = null)
    {
        return $this->canView($member);
    }

    /**
     * Resolved from the record's own {@see PackableExtension} — whichever {@see PackingPolicy}
     * variant it was configured with (the default, or e.g. SiteTree's) is what actually decides
     * this, so this class has no need to (and, for a clean core/SiteTree-integration split,
     * mustn't) re-derive that decision itself by checking the record's class directly. Falls back
     * to the default policy's own code if the class no longer has PackableExtension applied at
     * all — e.g. a still-installed but no-longer-packable class, for an old ExportRequest row.
     */
    private function permissionCode(): string
    {
        $class = $this->RecordClass;

        if ($class && class_exists($class) && is_a($class, DataObject::class, true)) {
            $extension = DataObject::singleton($class)->getExtensionInstance(PackableExtension::class);

            if ($extension) {
                return $extension->policy()->permissionCode();
            }
        }

        return ImportExportPermissions::RECORD_IMPORT_EXPORT;
    }

    /**
     * Compares SourceContentTimestamp against a fresh walk of the record's current content.
     * Only reads through the LIVE stage when the record's class is actually versioned — an
     * ordinary, unversioned DataObject (e.g. a catalogue/config record edited via a plain
     * GridField) has no draft/live distinction at all, so its current content simply IS what
     * would be exported.
     */
    public function isStale(): bool
    {
        if (!$this->RecordID || !$this->RecordClass) {
            return false;
        }

        $currentTimestamp = $this->latestRecordTimestamp();

        if ($currentTimestamp === null) {
            // Never published/created (or since removed)
            return false;
        }

        if ($this->SourceContentTimestamp === '' || $this->SourceContentTimestamp === null) {
            // Origin=Import: no content captured at creation time; anything existing now means
            // a publish/edit has happened since
            return true;
        }

        return $currentTimestamp > $this->SourceContentTimestamp;
    }

    private function latestRecordTimestamp(): ?string
    {
        $class = $this->RecordClass;

        if (!$class || !class_exists($class) || !is_a($class, DataObject::class, true)) {
            return null;
        }

        $recordID = (int) $this->RecordID;
        $walk = function () use ($class, $recordID): ?string {
            $record = $class::get()->byID($recordID);

            return $record ? (new ContentTimestampWalker())->latestTimestamp($record) : null;
        };

        if (!DataObject::singleton($class)->hasExtension(Versioned::class)) {
            return $walk();
        }

        return Versioned::withVersionedMode(function () use ($walk) {
            Versioned::set_stage(Versioned::LIVE);

            return $walk();
        });
    }

    public function getDownloadLink(): ?string
    {
        if ($this->Status !== self::STATUS_COMPLETE || !$this->ResultFileID) {
            return null;
        }

        // Guard explicitly against calling via CLI or tests or whatever
        if (!Controller::curr()) {
            return null;
        }

        $file = $this->ResultFile();

        return $file && $file->exists() ? $file->getAbsoluteURL() : null;
    }

    public function getStaleBadge(): string
    {
        return $this->isStale()
            ? '<span class="badge badge-warning">' . _t(self::class . '.STALE', 'Stale') . '</span>'
            : '<span class="badge badge-success">' . _t(self::class . '.FRESH', 'Fresh') . '</span>';
    }

    /**
     * The Status column's value, linked through to this request's own QueuedJobDescriptor in
     * admin/queuedjobs — its live progress while Queued/Running, or its log/error detail once
     * finished. Falls back to plain (escaped) status text once there's nothing to link to: no
     * descriptor was ever recorded (older rows, from before this existed), the descriptor's since
     * been purged, or the current member can't access the Jobs admin section anyway.
     */
    public function getStatusLinkHtml(): string
    {
        $status = htmlspecialchars((string) $this->Status);

        if (!$this->QueuedJobDescriptorID) {
            return $status;
        }

        if (!Permission::check(QueuedJobsAdmin::getRequiredPermissions())) {
            return $status;
        }

        $descriptor = $this->QueuedJobDescriptor();

        if (!$descriptor || !$descriptor->exists()) {
            return $status;
        }

        $link = QueuedJobsAdmin::singleton()->getCMSEditLinkForManagedDataObject($descriptor);

        return '<a href="' . htmlspecialchars($link) . '">' . $status . '</a>';
    }

    public function getDownloadLinkHtml(): string
    {
        $link = $this->getDownloadLink();

        if (!$link) {
            return '';
        }

        $label = _t(self::class . '.DOWNLOAD', 'Download');
        $size = $this->getFormattedFileSize();

        if ($size !== null) {
            $label .= ' (' . $size . ')';
        }

        return '<a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($label) . '</a>';
    }

    private function getFormattedFileSize(): ?string
    {
        $file = $this->ResultFileID ? $this->ResultFile() : null;

        if (!$file || !$file->exists()) {
            return null;
        }

        return $file->getSize() ?: null;
    }
}
