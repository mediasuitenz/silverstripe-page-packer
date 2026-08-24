<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\SiteTreeImportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\AssetBundler;
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
 * page type
 */
class CMSMainAddFormImportExtension extends Extension
{
    private static $allowed_actions = [
        'importPreview',
    ];

    public function updatePageOptions(FieldList $fields): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        $fields->insertAfter('PageType', LiteralField::create(
            'PagePackerOrDivider',
            '<p class="page-packer-or-divider">'
            . _t(self::class . '.OR', '— or —')
            . '</p>'
        ));

        $fields->insertAfter('PagePackerOrDivider', UploadField::create(
            'PagePackerFile',
            _t(self::class . '.IMPORT_FILE', 'Import a previously exported page (.zip)')
        )->setAllowedExtensions(['zip'])
        ->setAllowedMaxFileNumber(1)
        ->setFolderName('page-packer-uploads'));

        // populated by requirePreviewScript once a file has finished uploading
        $fields->insertAfter('PagePackerFile', LiteralField::create(
            'PagePackerImportPreview',
            '<div id="PagePackerImportPreview" class="page-packer-import-preview" '
            . 'data-preview-url="' . htmlspecialchars($this->owner->Link('importPreview'), ENT_QUOTES) . '">'
            . '</div>'
        ));

        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/import-preview.js');

    }

    /**
     * Reads a just-uploaded file's manifest and returns the meta block as JSON, so
     * the editor can see what they're about to import (page type, title, URL) before committing.
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

        // flags whether the exported class is actually installed on this site - easiest "will this work?" check
        $meta['classExists'] = class_exists($meta['className']) && is_a($meta['className'], SiteTree::class, true);

        // include "referenced" assets as per the exporter
        $meta['assetCount'] = count($manifest['assets'] ?? []);

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
            return;
        }

        if (!$record instanceof DataObject || !$record->hasExtension(SiteTreeLockExtension::class)) {
            return;
        }

        $parentID = (int) $record->ParentID;

        // Reclass to a bare stub regardless of whichever RecordType happened to be selected
        $record = $record->newClassInstance(SiteTree::class);
        $record->Title = _t(self::class . '.IMPORTING_TITLE', 'Importing…');
        $record->ParentID = $parentID;
        $record->write();

        $job = new SiteTreeImportJob($record, $uploadedFile);
        QueuedJobService::singleton()->queueJob($job);
    }
}
