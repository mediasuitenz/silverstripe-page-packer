<?php

namespace MadeCurious\PagePacker\Controllers;

use MadeCurious\PagePacker\Extensions\PackableExtension;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Jobs\RecordImportJob;
use MadeCurious\PagePacker\Model\RecordExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
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

        $exportRequest = RecordExportRequest::create();
        $exportRequest->RecordID = $record->ID;
        $exportRequest->RecordClass = get_class($record);
        $exportRequest->MemberID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : null;
        $exportRequest->Status = RecordExportRequest::STATUS_QUEUED;
        $exportRequest->Origin = RecordExportRequest::ORIGIN_EXPORT;
        $exportRequest->Description = $description;
        $exportRequest->IncludeAssets = $includeAssets;
        $exportRequest->write();

        $job = new RecordExportJob($record, $includeAssets, $exportRequest->ID);
        QueuedJobService::singleton()->queueJob($job);

        $message = _t(
            self::class . '.QUEUED_FOR_EXPORT',
            "Queued '{title}' for export.",
            ['title' => $this->titleFor($record)]
        );

        return $this->redirectToReferer($message);
    }

    public function ImportModalForm(): Form
    {
        $fields = FieldList::create(
            HiddenField::create('RecordClassName'),
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

        $uploadField = $form->Fields()->dataFieldByName('ImportFile');
        $items = $uploadField ? $uploadField->getItems() : null;
        $uploadedFile = $items ? $items->first() : null;

        if (!$uploadedFile instanceof File) {
            $form->sessionMessage(
                _t(self::class . '.NO_FILE', 'Please choose a file to import.'),
                'bad'
            );

            return $this->redirectToReferer();
        }

        $stub = $class::create();
        $stub->write();

        $job = new RecordImportJob($stub, $uploadedFile);
        QueuedJobService::singleton()->queueJob($job);

        return $this->redirectToReferer(
            _t(self::class . '.QUEUED_FOR_IMPORT', 'Queued the uploaded file for import.')
        );
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
     * "record edit" URL the way the page tree has one, so this reads the Referer instead,
     * validated as a same-site URL first.
     */
    private function redirectToReferer(?string $toastMessage = null): HTTPResponse
    {
        $referer = $this->getRequest()->getHeader('Referer');
        $link = ($referer && Director::is_site_url($referer)) ? $referer : Director::absoluteBaseURL();

        if ($toastMessage) {
            $separator = str_contains($link, '?') ? '&' : '?';
            $link .= $separator . 'page-packer-toast=' . rawurlencode($toastMessage);
        }

        return $this->redirect($link);
    }
}
