<?php

namespace MadeCurious\PagePacker\Forms\GridField;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Extensions\PackableExtension;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Support\CurrentBackUrl;
use MadeCurious\PagePacker\Support\ModalMarkup;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * An opt-in GridField toolbar component — add it to a GridFieldConfig (alongside
 * GridFieldAddNewButton) to let editors create a new record in that GridField by uploading a
 * previously exported PagePacker file. The GridField/DataObject equivalent of the page tree's
 * "Add new page" import option — see CMSMainAddFormImportExtension — but opt-in rather than
 * automatic, since (unlike the page tree) not every GridField is a sensible import target.
 *
 * Renders nothing for a GridField whose model class doesn't have PackableExtension applied.
 */
class GridFieldRecordImportButton implements GridField_HTMLProvider
{
    protected $targetFragment;

    public function __construct($targetFragment = 'before')
    {
        $this->targetFragment = $targetFragment;
    }

    public function getHTMLFragments($gridField)
    {
        $modelClass = $gridField->getModelClass();
        $singleton = DataObject::singleton($modelClass);

        if (!$singleton->hasExtension(PackableExtension::class)) {
            return [];
        }

        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return [];
        }

        if ($singleton->hasMethod('canCreate') && !$singleton->canCreate()) {
            return [];
        }

        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/export-modal.js');
        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/record-import-preview.js');

        $controller = RecordPackerController::singleton();

        $modalId = 'PackerImportModal' . md5($modelClass);
        $previewId = 'PackerImportPreview' . md5($modelClass);

        $form = $controller->ImportModalForm();
        $form->Fields()->dataFieldByName('RecordClassName')->setValue($modelClass);
        $form->Fields()->dataFieldByName('BackURL')->setValue(CurrentBackUrl::capture());
        // Lets doImport() redirect straight into the new stub's own edit view — see
        // ImportModalForm()'s own comment on this field.
        $form->Fields()->dataFieldByName('GridFieldLink')->setValue($gridField->Link());
        $form->Fields()->insertAfter('ImportFile', LiteralField::create(
            'PackerImportPreview',
            '<div id="' . $previewId . '" class="page-packer-import-preview" '
            . 'data-preview-url="' . htmlspecialchars($controller->Link('importPreview'), ENT_QUOTES) . '" '
            . 'data-upload-field-name="ImportFile"></div>'
        ));

        $modalHtml = ModalMarkup::modal(
            $modalId,
            (string) _t(self::class . '.MODAL_TITLE', 'Import a record'),
            $form->forTemplate()
        );
        $triggerHtml = ModalMarkup::trigger(
            $modalId,
            (string) _t(self::class . '.IMPORT_BUTTON', 'Import'),
            'font-icon-upload',
            $modalHtml
        );

        return [
            $this->targetFragment => $triggerHtml,
        ];
    }
}
