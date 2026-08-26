<?php

namespace MadeCurious\PagePacker\Controllers;

use MadeCurious\PagePacker\Extensions\PackableExtension;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Jobs\RecordImportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Support\ExportQueuer;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * The generic-DataObject/GridField equivalent of the page tree's export/import wiring
 * (CMSMainExportActionExtension + CMSMainAddFormImportExtension) — a small, standalone
 * controller registered by its own route (see _config/routes.yml), rather than attached to
 * CMSMain, so {@see PackableExtension}'s "Export" trigger and
 * {@see \MadeCurious\PagePacker\Forms\GridField\GridFieldRecordImportButton}'s "Import" trigger
 * both have somewhere to post to regardless of which admin section/GridField happens to be
 * hosting the record — there's no single "CMSMain" for arbitrary project DataObjects the way
 * there is for pages.
 *
 * Kept entirely separate from the SiteTree/CMSMain flow, which continues to use its own hosted
 * forms unchanged.
 */
class RecordPackerController extends Controller
{
    private static $url_segment = 'page-packer';

    private static $allowed_actions = [
        'ExportModalForm',
        'doExport',
        'ImportModalForm',
        'doImport',
        'importPreview',
    ];

    public function Link($action = null)
    {
        return Controller::join_links(static::config()->get('url_segment'), $action);
    }

    public function ExportModalForm(): Form
    {
        $fields = FieldList::create(
            HiddenField::create('RecordClassName'),
            HiddenField::create('RecordID'),
            // Populated by the caller (see PackingPolicy::getExportModalForm()) from the URL
            // that's actually being viewed at the moment the trigger is built, rather than left
            // to the submitting request's Referer header — which a Referrer-Policy, browser
            // privacy setting, or extension can omit or strip entirely, silently falling back to
            // the site root. See redirectToReferer()'s own doc comment.
            HiddenField::create('BackURL'),
            CheckboxField::create(
                'IncludeAssets',
                _t(self::class . '.INCLUDE_ASSETS', 'Include referenced files/images'),
                true
            ),
            TextField::create('Description', _t(self::class . '.DESCRIPTION', 'Description (optional)'))
        );

        $actions = FieldList::create(
            FormAction::create('doExport', _t(self::class . '.EXPORT_BUTTON', 'Export'))
                ->addExtraClass('btn-primary')
                ->setUseButtonTag(true)
        );

        $form = Form::create($this, 'ExportModalForm', $fields, $actions);
        $form->setFormAction($this->Link('ExportModalForm'));
        $form->setValidationExemptActions(['doExport']);
        $form->addExtraClass('page-packer-modal-form');

        return $form;
    }

    public function doExport(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return Security::permissionFailure($this);
        }

        $class = (string) ($data['RecordClassName'] ?? '');

        if (!$this->isPackable($class)) {
            return HTTPResponse::create('Not a packable record type.', 400);
        }

        $id = (int) ($data['RecordID'] ?? 0);
        /** @var DataObject|null $record */
        $record = $id ? $class::get()->byID($id) : null;

        if (!$record || !$record->exists() || !$record->canView()) {
            return Security::permissionFailure($this);
        }

        $includeAssets = !empty($data['IncludeAssets']);
        $description = trim((string) ($data['Description'] ?? ''));

        ExportQueuer::queue($record, RecordExportJob::class, $includeAssets, $description);

        $message = _t(
            self::class . '.QUEUED_FOR_EXPORT',
            "Queued '{title}' for export.",
            ['title' => $this->titleFor($record)]
        );

        return $this->redirectToReferer($message, (string) ($data['BackURL'] ?? ''));
    }

    public function ImportModalForm(): Form
    {
        $fields = FieldList::create(
            HiddenField::create('RecordClassName'),
            // See ExportModalForm()'s own comment on BackURL.
            HiddenField::create('BackURL'),
            // Populated by GridFieldRecordImportButton from the very $gridField instance it's
            // rendering into — lets doImport() redirect straight into the new stub's own edit
            // view once queued, the same "land on the new record" behaviour the page tree already
            // gets for free from CMSMain's native "Add new page" flow (see
            // CMSMainAddFormImportExtension). BackURL alone can't do this: it's just the grid's
            // *list* URL, with no reusable way to build a specific item's edit URL from it.
            HiddenField::create('GridFieldLink'),
            UploadField::create(
                'ImportFile',
                _t(self::class . '.IMPORT_FILE', 'Import a previously exported record (.zip)')
            )->setAllowedExtensions(['zip'])
            ->setAllowedMaxFileNumber(1)
            ->setFolderName('page-packer-uploads')
        );

        $actions = FieldList::create(
            FormAction::create('doImport', _t(self::class . '.IMPORT_BUTTON', 'Import'))
                ->addExtraClass('btn-primary')
                ->setUseButtonTag(true)
        );

        $form = Form::create($this, 'ImportModalForm', $fields, $actions);
        $form->setFormAction($this->Link('ImportModalForm'));
        $form->setValidationExemptActions(['doImport']);
        $form->addExtraClass('page-packer-modal-form');

        return $form;
    }

    public function doImport(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return Security::permissionFailure($this);
        }

        $class = (string) ($data['RecordClassName'] ?? '');

        if (!$this->isPackable($class)) {
            return HTTPResponse::create('Not a packable record type.', 400);
        }

        $singleton = DataObject::singleton($class);

        if ($singleton->hasMethod('canCreate') && !$singleton->canCreate()) {
            return Security::permissionFailure($this);
        }

        $backURL = (string) ($data['BackURL'] ?? '');

        $uploadField = $form->Fields()->dataFieldByName('ImportFile');
        $items = $uploadField ? $uploadField->getItems() : null;
        $uploadedFile = $items ? $items->first() : null;

        if (!$uploadedFile instanceof File) {
            $form->sessionMessage(
                _t(self::class . '.NO_FILE', 'Please choose a file to import.'),
                'bad'
            );

            return $this->redirectToReferer(null, $backURL);
        }

        $stub = $class::create();

        // Placeholder so the edit view we're about to redirect into isn't just blank while the
        // queued job fills it in — mirrors CMSMainAddFormImportExtension's identical placeholder
        // for the page-tree import flow.
        if ($stub->hasField('Title')) {
            $stub->Title = _t(self::class . '.IMPORTING_TITLE', 'Importing…');
        }

        $stub->write();

        $job = new RecordImportJob($stub, $uploadedFile);
        QueuedJobService::singleton()->queueJob($job);

        $message = _t(self::class . '.QUEUED_FOR_IMPORT', 'Queued the uploaded file for import.');
        $itemLink = $this->itemEditLink((string) ($data['GridFieldLink'] ?? ''), $stub);

        if ($itemLink) {
            return $this->redirect($this->appendToast($itemLink, $message, 'Import'));
        }

        // Fallback for the rare case GridFieldLink wasn't usable (missing/invalid, e.g. a
        // GridField whose config doesn't route a normal item/edit URL) — back to the grid list,
        // same as before this was added.
        return $this->redirectToReferer($message, $backURL, 'Import');
    }

    /**
     * Builds the URL of $stub's own edit view inside the GridField that triggered this import, or
     * null if $gridFieldLink isn't usable — either not captured at all (an older cached copy of
     * GridFieldRecordImportButton's markup) or not actually a same-site URL.
     */
    private function itemEditLink(string $gridFieldLink, DataObject $stub): ?string
    {
        if (!$gridFieldLink || !Director::is_site_url($gridFieldLink)) {
            return null;
        }

        return Controller::join_links($gridFieldLink, 'item', (string) $stub->ID);
    }

    /**
     * Reads a just-uploaded file's manifest and returns the meta block as JSON, so the editor
     * can see what they're about to import before committing — same shape as
     * CMSMainAddFormImportExtension::importPreview() for the page-tree flow, except "classExists"
     * here means "packable and installed on this site" rather than "is a SiteTree subclass".
     */
    public function importPreview(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create()->addHeader('Content-Type', 'application/json');

        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
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
                'error' => 'This file does not look like a valid export — no record metadata found.',
            ]));
        }

        $meta['classExists'] = $this->isPackable((string) $meta['className']);
        // include "referenced" assets as per the exporter
        $meta['assetCount'] = count($manifest['assets'] ?? []);

        return $response->setBody(json_encode($meta));
    }

    private function isPackable(string $class): bool
    {
        return $class !== ''
            && class_exists($class)
            && is_a($class, DataObject::class, true)
            && DataObject::singleton($class)->hasExtension(PackableExtension::class);
    }

    private function titleFor(DataObject $record): string
    {
        return $record->hasField('Title') ? (string) $record->Title : ('#' . $record->ID);
    }

    /**
     * Redirects to wherever the modal's form was submitted from — there's no single fixed
     * "record edit" URL the way the page tree has one. Prefers the explicit $backURL (the
     * BackURL hidden field every caller populates from the URL actually being viewed at the
     * moment the trigger was built — see ExportModalForm()'s own comment), falling back to the
     * Referer header only if that's missing, and to the site root as a last resort. Deliberately
     * doesn't trust Referer as the primary source: a Referrer-Policy, browser privacy setting, or
     * extension can omit or strip it on an otherwise ordinary same-origin form POST, which
     * silently sent every export/import here back to the site root instead of the CMS.
     */
    private function redirectToReferer(
        ?string $toastMessage = null,
        ?string $backURL = null,
        ?string $toastTitle = null
    ): HTTPResponse {
        $link = ($backURL && Director::is_site_url($backURL)) ? $backURL : null;

        if (!$link) {
            $referer = $this->getRequest()->getHeader('Referer');
            $link = ($referer && Director::is_site_url($referer)) ? $referer : Director::absoluteBaseURL();
        }

        return $this->redirect($this->appendToast($link, $toastMessage, $toastTitle));
    }

    /**
     * Appends the toast query params export-modal.js reads and clears from the URL bar once
     * shown. $toastTitle is the toast's header ("Export"/"Import") — left off for doExport(),
     * whose default of "Export" (baked into export-modal.js for backwards compatibility with
     * exports queued before this existed) is already correct.
     */
    private function appendToast(string $link, ?string $toastMessage, ?string $toastTitle = null): string
    {
        if (!$toastMessage) {
            return $link;
        }

        $separator = str_contains($link, '?') ? '&' : '?';
        $link .= $separator . 'page-packer-toast=' . rawurlencode($toastMessage);

        if ($toastTitle) {
            $link .= '&page-packer-toast-title=' . rawurlencode($toastTitle);
        }

        return $link;
    }
}
