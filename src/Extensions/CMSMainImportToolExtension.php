<?php

namespace MadeCurious\SiteTreeImportExport\Extensions;

use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeImportJob;
use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use MadeCurious\SiteTreeImportExport\Serialization\SiteTreeExporter;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Adds an "Import Page" tool above the site tree, deliberately separate from the native
 * Add-New-Page screen — see the module's implementation plan for why: the imported page's final
 * class isn't known until the uploaded file is parsed, which is fundamentally incompatible with
 * `CMSMainAddForm::doAdd()`'s assumption that the target class was already chosen (and
 * instantiated) via its radio list before any custom hook runs.
 *
 * The form is rendered directly inline via updateExtraTreeTools rather than as a separate
 * routed/PJAX-loaded screen — one less UI integration point to get wrong, and the plain
 * UploadField still self-mounts its React widget the same way either way.
 */
class CMSMainImportToolExtension extends Extension
{
    private static $allowed_actions = [
        'ImportForm',
        'doImport',
    ];

    public function updateExtraTreeTools(&$html): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        $form = $this->owner->ImportForm();
        $html .= '<details class="sitetree-import-export-import-tool">'
            . '<summary>' . _t(self::class . '.IMPORT_PAGE', 'Import Page') . '</summary>'
            . $form->forTemplate()
            . '</details>';
    }

    public function ImportForm(): Form
    {
        $currentPageID = (int) $this->owner->getRequest()->param('ID');

        $fields = FieldList::create(
            UploadField::create(
                'ImportFile',
                _t(self::class . '.IMPORT_FILE', 'Export file (.zip)')
            )->setAllowedExtensions(['zip']),
            HiddenField::create('ParentID', '', $currentPageID)
        );

        $actions = FieldList::create(
            FormAction::create('doImport', _t(self::class . '.IMPORT_BUTTON', 'Import'))
                ->setUseButtonTag(true)
        );

        $form = Form::create($this->owner, 'ImportForm', $fields, $actions);
        $form->setFormAction($this->owner->Link('ImportForm'));
        $form->setValidationExemptActions(['doImport']);

        return $form;
    }

    public function doImport(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return Security::permissionFailure($this->owner);
        }

        $fileIDs = $data['ImportFile']['Files'] ?? [];
        $uploadedFile = $fileIDs ? File::get()->byID((int) reset($fileIDs)) : null;

        if (!$uploadedFile) {
            $form->sessionMessage(
                _t(self::class . '.NO_FILE', 'Please choose an export file to import.'),
                'bad'
            );

            return $this->owner->redirectBack();
        }

        if (!SiteTree::singleton()->canCreate()) {
            return Security::permissionFailure($this->owner);
        }

        // Pre-write the stub immediately, titled so it's obviously in-progress, so the queued
        // job has a real ID to lock and populate, and the editor sees it appear in the tree
        // right away — mirroring async-publisher's "write new records before queuing" pattern.
        //
        // Deliberately bare SiteTree, not Page: newClassInstance() (used later to reclass this
        // stub to the manifest's resolved target class) doesn't clean up rows in whatever extra
        // table the *original* class introduced. SiteTree's own table is shared by every
        // subclass, so nothing is ever orphaned there — but Page is NOT automatically safe here:
        // verified against this actual dev site that Page has picked up its own extra column
        // (ElementalAreaID) because ElementalPageExtension was applied to Page specifically
        // (app/_config/mysite.yml), not to SiteTree — exactly the kind of per-site customization
        // this module can't assume away, so the stub must start one level higher than that.
        $stub = SiteTree::create();
        $stub->Title = _t(self::class . '.IMPORTING_TITLE', 'Importing…');
        $stub->ParentID = (int) ($data['ParentID'] ?? 0);
        $stub->write();

        $mismatchBehaviour = SiteTreeExporter::config()->get('mismatch_behaviour');
        $job = new SiteTreeImportJob($stub, $uploadedFile, $mismatchBehaviour);
        QueuedJobService::singleton()->queueJob($job);

        $message = _t(
            self::class . '.QUEUED_FOR_IMPORT',
            'Queued the import — the new page will appear populated in a moment.'
        );
        $this->owner->getResponse()->addHeader('X-Status', rawurlencode($message ?? ''));
        $response = $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
        $response->addHeader('X-ControllerURL', rawurlencode($stub->CMSEditLink()));

        return $response;
    }
}
