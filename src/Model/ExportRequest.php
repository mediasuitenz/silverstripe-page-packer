<?php

namespace MadeCurious\SiteTreeImportExport\Model;

use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use MadeCurious\SiteTreeImportExport\Serialization\ContentTimestampWalker;
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
 * {@see \MadeCurious\SiteTreeImportExport\Extensions\SiteTreeExportExtension}, each with a
 * download link once Status=Complete and a "stale" badge once anything in the page's owned
 * content graph has been published again since this entry's SourceContentTimestamp was
 * captured (see {@see isStale()}).
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
        // The most recent LastEdited found across the page and everything it owns (see
        // ContentTimestampWalker) at capture time — deliberately NOT just the page's own Version
        // number: publishing a nested Elemental block bumps that block's own independent version
        // history, not the page's, so a page whose own Version never changed can still have
        // materially different published content. Left '' for Origin=Import, since an imported
        // page has no live content yet at all — see isStale() for how that's reconciled against
        // a never-published page.
        'SourceContentTimestamp' => 'Varchar(32)',
        'StatusMessage' => 'Text',
        // Free-text note an author can attach when triggering an export, e.g. "before redesign"
        // — shown in the history list so past exports are distinguishable at a glance.
        'Description' => 'Varchar(255)',
        // Whether referenced files/images were bundled into this specific file. For
        // Origin=Export this is exactly the modal's checkbox value; for Origin=Import there's no
        // checkbox to read (nobody chose anything when the file was uploaded to create the
        // page), so SiteTreeImportJob sets it by checking whether the uploaded zip actually
        // contains embedded asset bytes (see AssetBundler::hasEmbeddedAssets()) — giving a
        // consistent, meaningful signal across both origins.
        'IncludeAssets' => 'Boolean',
    ];

    private static $has_one = [
        'Page' => SiteTree::class,
        'Member' => Member::class,
        'ResultFile' => File::class,
    ];

    private static $default_sort = 'Created DESC';

    private static $summary_fields = [
        'Created' => 'Date',
        'Description' => 'Description',
        'Origin' => 'Origin',
        'Status' => 'Status',
        'Member.Title' => 'Requested by',
        'IncludeAssetsLabel' => 'Assets included',
        'StaleBadge' => 'Stale',
        'DownloadLinkHtml' => 'File',
    ];

    /**
     * StaleBadge/DownloadLinkHtml are rendered HTML fragments (a badge span, a download link),
     * not plain text — cast so GridFieldDataColumns (used by
     * SiteTreeExportExtension::updateCMSFields()'s history GridField) renders them unescaped.
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
     * Compares SourceContentTimestamp against a FRESH walk of the page's current live content
     * graph (not a cached/stored value on the page itself — there's nowhere to cheaply store
     * "latest timestamp across everything this page owns" the way Versioned::Version is cheap
     * to look up for a single record), so this is a real query each time it's called — bounded
     * by how much a page actually owns (a handful of blocks/fields in the typical case), not
     * something to call in a tight loop.
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
            // Never published (or since unpublished) — nothing newer has gone live, so nothing
            // about this entry is out of date yet, regardless of origin.
            return false;
        }

        if ($this->SourceContentTimestamp === '' || $this->SourceContentTimestamp === null) {
            // Origin=Import: had no live content at creation time; anything live existing now
            // means a publish has happened since — exactly "changes published after it was
            // created".
            return true;
        }

        return $currentTimestamp > $this->SourceContentTimestamp;
    }

    public function getDownloadLink(): ?string
    {
        if ($this->Status !== self::STATUS_COMPLETE || !$this->ResultFileID) {
            return null;
        }

        // A protected (non-public) file's getAbsoluteURL() grants temporary access via the
        // CURRENT REQUEST'S session (FlysystemAssetStore::grant(), which reads
        // Controller::curr()->getRequest()->getSession()) — there's no meaningful download link
        // to produce outside a real HTTP request at all (dev/build, CLI tasks, tests), and
        // calling it in that context throws rather than returning null, so guard explicitly.
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
            : '';
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

    public function getIncludeAssetsLabel(): string
    {
        return $this->IncludeAssets
            ? _t(self::class . '.ASSETS_YES', 'Yes')
            : _t(self::class . '.ASSETS_NO', 'No');
    }

    private function getFormattedFileSize(): ?string
    {
        $file = $this->ResultFileID ? $this->ResultFile() : null;

        if (!$file || !$file->exists()) {
            return null;
        }

        // File::getSize() (-> File::format_size()) is core's own existing formatter — reused
        // as-is rather than hand-rolling one, at the cost of its "12 MB" (space included) style
        // rather than "12MB".
        $size = $file->getSize();

        return $size !== false ? $size : null;
    }
}
