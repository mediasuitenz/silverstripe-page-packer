<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\SiteTreeImportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\SiteTreeExporter;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * Integrates importing into the native "Add new page" screen, as an alternative to picking a
 * page type, rather than a separate tree-tool button: 1) choose top-level or under another page
 * (native, unchanged) → 2) either pick a page type and create it (native, unchanged) OR upload a
 * previously exported file (this extension).
 *
 * This same extension class is attached to two different owners (see _config/extensions.yml),
 * because the two things it needs to hook into live on two different classes:
 * - `updateFields()` fires on `CMSMainAddForm` (`createFields()`) — adds the upload field, an
 *   empty preview container, and requires the JS that fills it in (see requirePreviewScript()).
 * - `updateDoAdd()` fires on `CMSMain` (`CMSMainAddForm::doAdd()`, via `$controller->extend(...)`)
 *   — takes over record creation when a file was uploaded.
 * `importPreview()` (a third action, reachable because $allowed_actions on an Extension merges
 * into its owner's, same as CMSMainExportActionExtension's doExport()/ExportModalForm()) is
 * called by that JS once a file finishes uploading, and answers "what page is actually in this
 * zip" — class, title, URL segment, and whether that class exists on this site — entirely
 * read-only, before the editor has committed to anything by clicking Create.
 *
 * `updateDoAdd(&$record, $form)` receives $record BY REFERENCE (Extensible::extend()'s variadic
 * arguments are all by-ref) — reassigning it here changes what `doAdd()` goes on to write, even
 * though `getNewItem()` already instantiated it as whatever RecordType happened to be selected
 * (defaulted, never left blank) by the time this hook runs. This is what makes hooking the
 * native form viable after all — the earlier research flagged the *timing* (target class known
 * only after instantiation) as the reason to avoid this form, but reassignment-by-reference
 * sidesteps that rather than requiring the class to be known upfront.
 *
 * The stub is written a write() call early, inside this hook, rather than deferred — doAdd()
 * unconditionally calls $record->write() again immediately after this hook returns regardless,
 * so that second call is a harmless no-op UPDATE once this hook has already given the stub a
 * real ID; there's no need for a more elaborate "queue after the real write" mechanism.
 */
class CMSMainAddFormImportExtension extends Extension
{
    private static $allowed_actions = [
        'importPreview',
    ];

    public function updateFields(FieldList $fields): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        $fields->insertAfter('RecordType', LiteralField::create(
            'PagePackerOrDivider',
            '<p class="page-packer-or-divider">'
            . _t(self::class . '.OR', '— or —')
            . '</p>'
        ));

        $fields->insertAfter('PagePackerOrDivider', UploadField::create(
            'PagePackerFile',
            _t(self::class . '.IMPORT_FILE', 'Import a previously exported page (.zip)')
        )->setAllowedExtensions(['zip']));

        // Empty on page load — populated by requirePreviewScript()'s JS once a file has actually
        // finished uploading, by calling importPreview() below with that file's ID. The preview
        // URL is embedded server-side (correct regardless of whatever route this controller is
        // actually mounted at) rather than hardcoded in the JS. $this->owner here is the Form
        // itself (updateFields() fires from CMSMainAddForm::createFields()), not a Controller —
        // Link() lives on its controller, reached via getController() (importPreview() is wired
        // to CMSMain, the same class that controller actually is).
        $fields->insertAfter('PagePackerFile', LiteralField::create(
            'PagePackerImportPreview',
            '<div id="PagePackerImportPreview" class="page-packer-import-preview" '
            . 'data-preview-url="' . htmlspecialchars($this->owner->getController()->Link('importPreview'), ENT_QUOTES) . '">'
            . '</div>'
        ));

        $this->requirePreviewScript();
    }

    /**
     * UploadField (SilverStripe\AssetAdmin\Forms\UploadField) is a React component mounted by a
     * thin Entwine bootstrap — there's no plain DOM CustomEvent fired anywhere for "a file just
     * finished uploading" (its only signal is a jQuery `.trigger('change')` on the whole form,
     * invisible to a native `addEventListener`, and fired for every field state change, not just
     * a completed upload). The one reliable, version-stable signal is the hidden mirror input
     * UploadField renders per attached file — `<input type="hidden" name="PagePackerFile[Files][]"
     * value="{fileID}">` — present in both the server-rendered markup and whatever React renders
     * after upload. A MutationObserver watching for that exact input appearing is what's actually
     * being watched for below; it's cheap (this form has very few fields) and doesn't depend on
     * any asset-admin internals beyond that one input's name attribute, which is derived directly
     * from the field's own name and therefore about as stable a contract as this can have.
     */
    private function requirePreviewScript(): void
    {
        Requirements::customScript(<<<'JS'
(function () {
    if (window.__pagePackerImportPreviewReady) { return; }
    window.__pagePackerImportPreviewReady = true;

    var lastSeenFileId = null;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function renderPreview(container, meta) {
        var warning = meta.classExists ? '' : (
            '<p class="alert alert-warning page-packer-import-preview__warning">'
            + '&#8220;' + escapeHtml(meta.className) + '&#8221; is not a page type installed on'
            + ' this site &mdash; the import may fail or partially apply, depending on the'
            + ' mismatch setting.</p>'
        );

        container.innerHTML =
            '<div class="alert alert-info page-packer-import-preview__summary">'
            + '<strong>' + escapeHtml(meta.className) + '</strong>'
            + (meta.title ? ' &mdash; ' + escapeHtml(meta.title) : '')
            + (meta.urlSegment ? ' <span class="page-packer-import-preview__slug">/' + escapeHtml(meta.urlSegment) + '</span>' : '')
            + '</div>'
            + warning;
    }

    function renderError(container, message) {
        container.innerHTML = '<p class="alert alert-danger page-packer-import-preview__error">' + escapeHtml(message) + '</p>';
    }

    function fetchAndRenderPreview(container, fileId) {
        container.innerHTML = '<p class="page-packer-import-preview__loading">Checking file&hellip;</p>';

        var url = container.getAttribute('data-preview-url') + '?FileID=' + encodeURIComponent(fileId);

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || result.data.error) {
                    renderError(container, (result.data && result.data.error) || 'Could not read this file.');
                    return;
                }

                renderPreview(container, result.data);
            })
            .catch(function () {
                renderError(container, 'Could not read this file.');
            });
    }

    function checkForUploadedFile() {
        var container = document.getElementById('PagePackerImportPreview');

        if (!container) { return; }

        var input = document.querySelector('input[name="PagePackerFile[Files][]"]');
        var fileId = input ? input.value : null;

        if (fileId && fileId !== lastSeenFileId) {
            lastSeenFileId = fileId;
            fetchAndRenderPreview(container, fileId);
        } else if (!fileId && lastSeenFileId) {
            lastSeenFileId = null;
            container.innerHTML = '';
        }
    }

    new MutationObserver(checkForUploadedFile).observe(document.body, { childList: true, subtree: true });
})();
JS, 'page-packer-import-preview');
    }

    /**
     * Reads a just-uploaded (but not yet confirmed — the editor hasn't clicked "Create" yet)
     * file's manifest and returns its {@see SiteTreeExporter::export()} `meta` block as JSON, so
     * the editor can see what they're about to import (page type, title, URL) before committing.
     * Deliberately read-only and side-effect-free: never queues anything, never writes.
     *
     * `classExists` additionally flags whether the exported class is actually installed on THIS
     * site — the single most useful thing to surface before import, since an editor importing
     * onto the wrong environment (e.g. a page type only some sites have Elemental/Userforms
     * variants of) would otherwise only find out after the job has already run and failed/warned.
     */
    public function importPreview(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create()->addHeader('Content-Type', 'application/json');

        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return $response->setStatusCode(403)->setBody(json_encode(['error' => 'Permission denied.']));
        }

        $fileID = (int) $request->getVar('FileID');
        $file = $fileID ? File::get()->byID($fileID) : null;

        if (!$file || !$file->exists()) {
            return $response->setStatusCode(404)->setBody(json_encode(['error' => 'File not found.']));
        }

        try {
            $manifest = Injector::inst()->create(AssetBundler::class)->readZip($file);
        } catch (Throwable $e) {
            return $response->setStatusCode(422)->setBody(json_encode(['error' => $e->getMessage()]));
        }

        // meta is absent for a file exported before this was added — fall back to the root
        // node's own fields (always present) rather than showing nothing at all.
        $meta = $manifest['meta'] ?? null;

        if (!$meta) {
            $rootLocalId = (string) ($manifest['rootLocalId'] ?? '0');
            $rootNode = $manifest['nodes'][$rootLocalId] ?? null;
            $meta = $rootNode ? [
                'className' => $rootNode['className'] ?? null,
                'title' => $rootNode['fields']['Title'] ?? null,
                'urlSegment' => $rootNode['fields']['URLSegment'] ?? null,
            ] : null;
        }

        if (!$meta || !$meta['className']) {
            return $response->setStatusCode(422)->setBody(json_encode([
                'error' => 'This file does not look like a valid export — no page metadata found.',
            ]));
        }

        $meta['classExists'] = class_exists($meta['className']) && is_a($meta['className'], SiteTree::class, true);

        return $response->setBody(json_encode($meta));
    }

    public function updateDoAdd(&$record, Form $form): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        $uploadField = $form->Fields()->dataFieldByName('PagePackerFile');
        $items = $uploadField ? $uploadField->getItems() : null;
        $uploadedFile = $items ? $items->first() : null;

        if (!$uploadedFile instanceof File) {
            // No file uploaded — leave $record untouched, native "create new" flow proceeds.
            return;
        }

        if (!$record instanceof DataObject || !$record->hasExtension(SiteTreeLockExtension::class)) {
            // Defensive: only page types actually carry the lock extension (applied to
            // SiteTree). Should always be true here since this form only ever creates pages.
            return;
        }

        $parentID = (int) $record->ParentID;

        // Reclass to a bare stub regardless of whichever RecordType happened to be selected —
        // see SiteTreeImportJob's class doc for why this must be plain SiteTree, not Page: a
        // per-site extension (e.g. Elemental applied to Page specifically) can introduce a
        // column on a "safer-looking" subclass just as easily as on a custom one.
        $record = $record->newClassInstance(SiteTree::class);
        $record->Title = _t(self::class . '.IMPORTING_TITLE', 'Importing…');
        $record->ParentID = $parentID;
        $record->write();

        $mismatchBehaviour = SiteTreeExporter::config()->get('mismatch_behaviour');
        $job = new SiteTreeImportJob($record, $uploadedFile, $mismatchBehaviour);
        QueuedJobService::singleton()->queueJob($job);
    }
}
