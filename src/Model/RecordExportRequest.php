<?php

namespace MadeCurious\PagePacker\Model;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\ContentTimestampWalker;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * The generic, any-DataObject equivalent of {@see ExportRequest} — tracks one export bundle for
 * a record packed via {@see \MadeCurious\PagePacker\Extensions\PackableExtension} applied
 * directly to a project DataObject (typically edited through an ordinary GridField), rather than
 * through the SiteTree page tree.
 *
 * Deliberately a separate table/class from ExportRequest rather than widening that class's
 * `Page` has_one to a polymorphic owner, so the existing SiteTree/CMSMain export-history flow
 * {@see ExportRequest} already serves is completely unaffected by this generalisation.
 *
 * `Record` is declared against the bare `DataObject::class`, which SilverStripe treats as a
 * polymorphic has_one — it adds a companion `RecordClass` column alongside `RecordID` — so this
 * one table can serve every project DataObject that applies PackableExtension, the same way
 * ExportRequest's fixed `Page` has_one serves every SiteTree subclass.
 */
class RecordExportRequest extends DataObject
{
    private static $table_name = 'PagePacker_RecordExportRequest';

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
    ];

    private static $owns = [
        'ResultFile',
    ];

    private static $default_sort = 'Created DESC';

    private static $summary_fields = [
        'Created' => 'Date',
        'Description' => 'Description',
        'Origin' => 'Origin',
        'Status' => 'Status',
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
    ];

    public function canView($member = null)
    {
        return Permission::checkMember($member, ImportExportPermissions::RECORD_IMPORT_EXPORT);
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
     * Compares SourceContentTimestamp against a fresh walk of the record's current content —
     * mirrors ExportRequest::isStale() exactly, generalised to resolve its owner polymorphically
     * and to only bother with LIVE-stage reading when the owning class is actually versioned (an
     * ordinary, unversioned project DataObject has no draft/live distinction at all — its current
     * content simply IS what would be exported).
     */
    public function isStale(): bool
    {
        if (!$this->RecordID || !$this->RecordClass) {
            return false;
        }

        $currentTimestamp = $this->latestOwnerTimestamp();

        if ($currentTimestamp === null) {
            // Never published/created (or since deleted)
            return false;
        }

        if ($this->SourceContentTimestamp === '' || $this->SourceContentTimestamp === null) {
            // Origin=Import: no content captured at creation time; anything existing now means
            // the record has been touched since
            return true;
        }

        return $currentTimestamp > $this->SourceContentTimestamp;
    }

    private function latestOwnerTimestamp(): ?string
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
