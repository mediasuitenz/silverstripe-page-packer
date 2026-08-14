<?php

namespace MadeCurious\PagePacker\Model;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\ContentTimestampWalker;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * Tracks one export bundle for a page — either an actual export job's output (Origin=Export) or
 * the file originally uploaded to create the page via import (Origin=Import, registered as that
 * page's first history entry so an author has an immediate downloadable snapshot of what was
 * imported without needing to trigger a fresh export first).
 *
 * Shown as a history list on the page's Content Export tab (newest first) by
 * {@see \MadeCurious\PagePacker\Extensions\SiteTreeExportExtension}, each with a
 * download link once Status=Complete and a badge indicating staleness
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
        // The most recent LastEdited found across the page and everything it owns (see
        // ContentTimestampWalker) at capture time
        'SourceContentTimestamp' => 'Varchar(32)',
        'StatusMessage' => 'Text',
        'Description' => 'Varchar(255)',
        'IncludeAssets' => 'Boolean',
    ];

    private static $has_one = [
        'Page' => SiteTree::class,
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
        'IncludeAssets.Nice' => 'Assets included',
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
        return Permission::checkMember($member, ImportExportPermissions::SITETREE_IMPORT_EXPORT);
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
     * Compares SourceContentTimestamp against a fresh walk of the page's current live content
     */
    public function isStale(): bool
    {
        if (!$this->PageID) {
            return false;
        }

        $currentTimestamp = Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::LIVE);
            $livePage = SiteTree::get()->byID($this->PageID);

            return $livePage ? (new ContentTimestampWalker())->latestTimestamp($livePage) : null;
        });

        if ($currentTimestamp === null) {
            // Never published (or since unpublished)
            return false;
        }

        if ($this->SourceContentTimestamp === '' || $this->SourceContentTimestamp === null) {
            // Origin=Import: no live content at creation time; anything live existing now
            // means a publish has happened since
            return true;
        }

        return $currentTimestamp > $this->SourceContentTimestamp;
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
            : '<span class="badge badge-good">' . _t(self::class . '.FRESH', 'Fresh') . '</span>';
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
