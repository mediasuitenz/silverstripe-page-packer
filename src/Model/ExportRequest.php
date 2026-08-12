<?php

namespace MadeCurious\SiteTreeImportExport\Model;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
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
 * Shown as a history list on the page's Settings tab (newest first) by
 * {@see \MadeCurious\SiteTreeImportExport\Extensions\SiteTreeExportExtension}, each with a
 * download link once Status=Complete and a "stale" badge once the page has been published again
 * since this entry's SourceLiveVersion was captured (see {@see isStale()}).
 */
class ExportRequest extends DataObject
{
    private static $table_name = 'SiteTreeImportExport_ExportRequest';

    const STATUS_QUEUED = 'Queued';

    const STATUS_COMPLETE = 'Complete';

    const STATUS_FAILED = 'Failed';

    const ORIGIN_EXPORT = 'Export';

    const ORIGIN_IMPORT = 'Import';

    private static $db = [
        'Status' => "Enum('Queued,Complete,Failed','Queued')",
        'Origin' => "Enum('Export,Import','Export')",
        // The live Version number captured at export time (see SiteTreeExporter caller). Left
        // null for Origin=Import, since an imported page has no live version yet — see
        // isStale() for how that's reconciled against a never-published page.
        'SourceLiveVersion' => 'Int',
        'StatusMessage' => 'Text',
    ];

    private static $has_one = [
        'Page' => SiteTree::class,
        'Member' => Member::class,
        'ResultFile' => File::class,
    ];

    private static $default_sort = 'Created DESC';

    private static $summary_fields = [
        'Created' => 'Date',
        'Origin' => 'Origin',
        'Status' => 'Status',
        'Member.Title' => 'Requested by',
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
     * @see the "Staleness" section of the module's implementation plan for the full rationale —
     * this deliberately uses a version-NUMBER comparison (via the same cheap, cached primitive
     * that backs Versioned::isLiveVersion()) rather than a timestamp, so it works uniformly for
     * an Origin=Export entry (always has a SourceLiveVersion) and an Origin=Import entry (starts
     * with none, because the page has never been published yet).
     *
     * SourceLiveVersion uses 0 as its "no live version at capture time" sentinel, not null —
     * SilverStripe's Int db field is always created NOT NULL DEFAULT 0 (SilverStripe's DBInt
     * hardcodes this; there's no per-field nullable override), and real published version
     * numbers always start at 1, so the comparison below degrades correctly either way.
     */
    public function isStale(): bool
    {
        if (!$this->PageID) {
            return false;
        }

        $pageClass = SiteTree::class;
        $currentLive = Versioned::get_versionnumber_by_stage($pageClass, Versioned::LIVE, $this->PageID);

        if ($currentLive === null) {
            // Never published (or since unpublished) — nothing newer has gone live, so nothing
            // about this entry is out of date yet, regardless of origin.
            return false;
        }

        if ((int) $this->SourceLiveVersion === 0) {
            // Origin=Import: had no live version at creation time; any live version existing
            // now means a publish has happened since — exactly "changes published after it was
            // created".
            return true;
        }

        return $currentLive > $this->SourceLiveVersion;
    }

    public function getDownloadLink(): ?string
    {
        if ($this->Status !== self::STATUS_COMPLETE || !$this->ResultFileID) {
            return null;
        }

        $file = $this->ResultFile();

        return $file && $file->exists() ? $file->getAbsoluteURL() : null;
    }
}
