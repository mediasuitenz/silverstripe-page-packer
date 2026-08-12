<?php

namespace MadeCurious\SiteTreeImportExport\Extensions;

use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeExportJob;
use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Adds the "Export" button (which opens a modal — include-assets checkbox + a free-text
 * description, see updateCMSActions()'s doc comment for how the modal itself works) to a page's
 * edit-screen action bar. The export history GridField this extension used to also render (as a
 * sub-tab nested inside the Content screen) now lives on its own top-level "Content Export" tab
 * instead — see {@see \MadeCurious\SiteTreeImportExport\Controllers\CMSPageContentExportController}
 * — so it's a peer of Content/Settings/History, not buried under Content.
 */
class SiteTreeExportExtension extends Extension
{
    /**
     * A real has_many (not just a filtered DataList) so CMSPageContentExportController's
     * GridField is backed by a genuine RelationList — GridFieldDeleteAction works either way,
     * but this is the more idiomatic wiring. IMPORTANT: this class is registered in
     * RelationSchema's excluded_relation_classes — without that exclusion, the exporter would
     * treat this as ordinary owned content and try to walk into it (recursing into
     * ExportRequest's own Member/ResultFile relations), which is exactly backwards: this is
     * operational metadata ABOUT the page, never itself page content to export.
     */
    private static $has_many = [
        'ExportRequests' => ExportRequest::class,
    ];

    /**
     * SiteTree::getCMSFields() ends by calling parent::getCMSFields() (DataObject's generic
     * FormScaffolder), which auto-generates a tab for every has_many relation it doesn't already
     * know to skip — declaring the has_many above got us a free "Export requests" tab we never
     * asked for, sitting directly under Root alongside "Main", duplicating
     * CMSPageContentExportController's own dedicated top-level tab. Removed here rather than
     * prevented at declaration time — there's no equivalent to userforms'
     * $scaffold_cms_fields_settings['ignoreRelations'] on SiteTree itself to hook into instead.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('ExportRequests');
    }

    /**
     * Adds a plain (non-FormAction) trigger button carrying the whole modal — form and all — as
     * a `data-modal` HTML string, exactly mirroring
     * `SilverStripe\Forms\GridField\GridFieldImportButton`'s own CSV-import dialog: the JS (see
     * requireModalScript()) appends that HTML to `<body>` on click, so the modal's own `<form>`
     * is never nested inside the CMS's own edit-form `<form>` tag — sidestepping both the
     * HTML-invalid nested-form problem and the record-dirty-tracking concern a field added
     * directly to the page's edit form would raise (confirmed necessary: the CMS's unsaved-
     * changes tracking watches for ANY input change within `.cms-edit-form`, not just changes to
     * fields backed by a real db column, so nesting fields inside the SAME form would still
     * flicker a "you have unsaved changes" warning even for a non-persisted field).
     *
     * A plain LiteralField-rendered `<button type="button">`, not a FormAction, deliberately:
     * every FormAction unconditionally renders with the CSS class `action`
     * (`FormAction::Type()`), and the CMS's own shipped JS binds a submit-hijacking handler to
     * `.cms-edit-form .btn-toolbar button.action` — any FormAction added here would have its
     * click intercepted and submit the page's main edit form, not open a modal.
     *
     * Hides the button while an export for this page is already in flight, so an editor can't
     * queue a second concurrent export of the same page.
     *
     * Pushed into the "More options" popup (the drop-up behind the three-dot button next to
     * Save/Publish) rather than the top-level action bar — the same TabSet/Tab
     * (`ActionMenus`/`MoreOptions`) SiteTree::getCMSActions() itself uses for Unpublish/Rollback/
     * etc, found by name on the $actions list core has already built by the time
     * updateCMSActions() extension hooks run. A LiteralField renders fine inside that popup
     * alongside FormActions — SiteTree's own "Information" panel in the same tab is a LiteralField
     * too.
     */
    public function updateCMSActions(FieldList $actions): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        // Registered before the locked check, deliberately: this script also contains the
        // toast-on-redirect detection (see requireModalScript()'s doc comment), which needs to
        // run on exactly the page load right after a submission — i.e. precisely while the page
        // is (correctly) locked from just having queued a job. Gating script registration behind
        // "not locked" would silently drop the one toast that actually matters.
        $this->requireModalScript();

        $locked = $this->owner->hasExtension(SiteTreeLockExtension::class)
            && $this->owner->pendingJobExists([SiteTreeExportJob::class]);

        if ($locked) {
            return;
        }

        $controller = Controller::curr();

        if (!$controller || !$controller->hasMethod('ExportModalForm')) {
            return;
        }

        $modalId = 'SiteTreeExportModal';
        $form = $controller->ExportModalForm();
        $form->Fields()->dataFieldByName('PageID')->setValue($this->owner->ID);

        $modalHtml = '<div id="' . $modalId . '" class="modal fade" tabindex="-1" role="dialog">'
            . '<div class="modal-dialog" role="document"><div class="modal-content">'
            . '<div class="modal-header"><h2 class="modal-title">'
            . htmlspecialchars((string) _t(self::class . '.MODAL_TITLE', 'Export page'))
            . '</h2><button type="button" class="btn btn-close btn--icon-xl btn--no-text modal__close-button" '
            . 'data-dismiss="modal" aria-label="Close" title="Close">'
            . '<span class="btn__icon font-icon-cancel" aria-hidden="true"></span></button></div>'
            . '<div class="modal-body">' . $form->forTemplate() . '</div>'
            . '</div></div></div>';

        $triggerHtml = '<button type="button" class="btn btn-secondary font-icon-share" '
            . 'data-toggle="modal" data-target="#' . $modalId . '" '
            . 'data-modal="' . htmlspecialchars($modalHtml, ENT_QUOTES) . '">'
            . htmlspecialchars((string) _t(self::class . '.EXPORT_BUTTON', 'Export')) . '</button>';

        $trigger = LiteralField::create('SiteTreeExportModalTrigger', $triggerHtml);

        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        if ($moreOptions) {
            $moreOptions->push($trigger);
        } else {
            // Fallback for any theme/version that doesn't build the usual ActionMenus/MoreOptions
            // structure — better a top-level button than a silently vanished one.
            $actions->push($trigger);
        }
    }

    /**
     * Two things, one small script: generalizes GridFieldImportButton's own shipped
     * modal-open/close JS (entwine-scoped to `.grid-field .action.action_import:button`, so it
     * never fires for this module's trigger) to work for any `[data-toggle="modal"][data-modal]`
     * element, anywhere in the CMS; and, on load, checks for a `sitetree-export-toast` query
     * param (set by CMSMainExportActionExtension::doExport()'s post-submission redirect) and
     * renders it as a toast using the CMS's own `.toasts`/`.toast` markup/CSS (already loaded;
     * there's just no way to dispatch into React's own toast system from outside it). All via
     * Requirements::customScript(), no build pipeline, idempotent (guarded both by a JS-side flag
     * and by Requirements' own de-duplication-by-key).
     */
    private function requireModalScript(): void
    {
        Requirements::customScript(<<<'JS'
(function () {
    if (window.__siteTreeImportExportModalReady) { return; }
    window.__siteTreeImportExportModalReady = true;

    function closeModal(modalEl) {
        if (!modalEl) { return; }
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.remove();
        document.body.classList.remove('modal-open');
        document.querySelectorAll('[data-sitetree-import-export-backdrop]').forEach(function (el) {
            el.remove();
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-toggle="modal"][data-modal]');

        if (trigger) {
            e.preventDefault();
            e.stopPropagation();

            var existing = document.querySelector(trigger.getAttribute('data-target'));
            if (existing) { closeModal(existing); }

            var wrapper = document.createElement('div');
            wrapper.innerHTML = trigger.getAttribute('data-modal');
            var modalEl = wrapper.firstElementChild;
            document.body.appendChild(modalEl);

            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.setAttribute('data-sitetree-import-export-backdrop', '1');
            document.body.appendChild(backdrop);

            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');

            return;
        }

        var dismiss = e.target.closest('[data-dismiss="modal"]');

        if (dismiss) {
            e.preventDefault();
            closeModal(dismiss.closest('.modal'));

            return;
        }

        if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
            closeModal(e.target);
        }
    });

    // The modal's own form submits as a plain (non-AJAX) browser POST — see
    // CMSMainExportActionExtension::doExport()'s doc comment for why — so there's no PJAX
    // response for the CMS's own X-Status/toast handling to react to. Instead, doExport()
    // redirects here with the confirmation message in the query string; show it as a toast
    // using the CMS's own .toasts/.toast markup and CSS (already loaded, just never assembled
    // outside React's own toast system, which nothing outside React can dispatch into), then
    // strip the param so refreshing the page doesn't show it again.
    var params = new URLSearchParams(window.location.search);
    var toastMessage = params.get('sitetree-export-toast');

    if (toastMessage) {
        var container = document.querySelector('.toasts');

        if (!container) {
            container = document.createElement('div');
            container.className = 'toasts';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast toast--good';
        toast.innerHTML = '<div class="toast-header"><strong>Export</strong></div>'
            + '<div class="toast-body"></div>';
        toast.querySelector('.toast-body').textContent = toastMessage;
        container.appendChild(toast);

        setTimeout(function () {
            toast.remove();
        }, 6000);

        params.delete('sitetree-export-toast');

        var newSearch = params.toString();
        var newUrl = window.location.pathname + (newSearch ? '?' + newSearch : '') + window.location.hash;
        window.history.replaceState(null, '', newUrl);
    }
})();
JS, 'sitetree-import-export-modal');
    }
}
