<?php

namespace MadeCurious\SiteTreeImportExport\Extensions;

use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeExportJob;
use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;

/**
 * Adds the "Content Export" section to a page's Settings tab: two export buttons (with/without
 * assets — see updateCMSActions()'s doc comment for why this is two actions and not one action
 * plus a checkbox field) and a history list of this page's past {@see ExportRequest}s (both
 * real exports and the file originally uploaded to import this page, if it was created that
 * way) — newest first, each with a stale badge and a download link once complete.
 */
class SiteTreeExportExtension extends Extension
{
    public function updateSettingsFields(FieldList $fields): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        if (!$this->owner->exists()) {
            // Nothing to export until the page itself has been saved at least once.
            return;
        }

        // getSettingsFields() builds a FieldList whose only top-level tab is "Root.Settings" —
        // unlike getCMSFields()'s "Root.Main", there is no "Main" tab here to target.
        $fields->addFieldToTab('Root.Settings', HeaderField::create(
            'SiteTreeExportHeader',
            _t(self::class . '.SECTION_TITLE', 'Content Export')
        ));

        $fields->addFieldToTab('Root.Settings', LiteralField::create(
            'SiteTreeExportHistory',
            $this->renderHistory()
        ));
    }

    /**
     * Two distinct actions — "Export" and "Export with Assets" — rather than one "Export" action
     * plus an include-assets checkbox field: a checkbox on this same edit form would be a field
     * on the SiteTree record itself, tied to the record's own save/publish lifecycle (the CMS
     * would treat toggling it as an unsaved change to the page, and its value would only take
     * effect once actually saved) even though it has nothing to do with the page's own content.
     * Making the choice part of which button is clicked keeps it atomic with the export action
     * itself, with nothing to save first.
     *
     * Hides both actions while an export for this page is already in flight, so an editor can't
     * queue a second concurrent export of the same page.
     */
    public function updateCMSActions(FieldList $actions): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        $locked = $this->owner->hasExtension(SiteTreeLockExtension::class)
            && $this->owner->pendingJobExists([SiteTreeExportJob::class]);

        if ($locked) {
            return;
        }

        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');
        $exportAction = FormAction::create(
            'doExport',
            _t(self::class . '.EXPORT_BUTTON', 'Export')
        )->setUseButtonTag(true);
        $exportWithAssetsAction = FormAction::create(
            'doExportWithAssets',
            _t(self::class . '.EXPORT_WITH_ASSETS_BUTTON', 'Export with Assets')
        )->setUseButtonTag(true);

        if ($moreOptions) {
            $moreOptions->push($exportAction);
            $moreOptions->push($exportWithAssetsAction);
        } else {
            $actions->push($exportAction);
            $actions->push($exportWithAssetsAction);
        }
    }

    private function renderHistory(): string
    {
        $requests = ExportRequest::get()->filter('PageID', $this->owner->ID);

        if (!$requests->count()) {
            return '<p>' . _t(self::class . '.NO_EXPORTS', 'No exports yet.') . '</p>';
        }

        $rows = '';

        foreach ($requests as $request) {
            $stale = $request->isStale()
                ? '<span class="badge badge-warning">' . _t(self::class . '.STALE', 'Stale') . '</span>'
                : '';
            $status = htmlspecialchars((string) $request->Status);
            $origin = htmlspecialchars((string) $request->Origin);
            $created = htmlspecialchars((string) $request->Created);
            $link = $request->getDownloadLink();
            $download = $link
                ? '<a href="' . htmlspecialchars($link) . '">' . _t(self::class . '.DOWNLOAD', 'Download') . '</a>'
                : '';

            $rows .= "<tr><td>{$created}</td><td>{$origin}</td><td>{$status} {$stale}</td><td>{$download}</td></tr>";
        }

        return '<table class="table"><thead><tr>'
            . '<th>' . _t(self::class . '.COL_DATE', 'Date') . '</th>'
            . '<th>' . _t(self::class . '.COL_ORIGIN', 'Origin') . '</th>'
            . '<th>' . _t(self::class . '.COL_STATUS', 'Status') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }
}
