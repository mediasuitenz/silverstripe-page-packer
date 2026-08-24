<?php

namespace MadeCurious\PagePacker\Forms\GridField;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Extensions\PackableExtension;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
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
        $form->Fields()->insertAfter('ImportFile', LiteralField::create(
            'PackerImportPreview',
            '<div id="' . $previewId . '" class="page-packer-import-preview" '
            . 'data-preview-url="' . htmlspecialchars($controller->Link('importPreview'), ENT_QUOTES) . '" '
            . 'data-upload-field-name="ImportFile"></div>'
        ));

        $modalHtml = '<div id="' . $modalId . '" class="modal fade" tabindex="-1" role="dialog">'
            . '<div class="modal-dialog" role="document"><div class="modal-content">'
            . '<div class="modal-header"><h2 class="modal-title">'
            . htmlspecialchars((string) _t(self::class . '.MODAL_TITLE', 'Import a record'))
            . '</h2><button type="button" class="btn btn-close btn--icon-xl btn--no-text modal__close-button" '
            . 'data-dismiss="modal" aria-label="Close" title="Close">'
            . '<span class="btn__icon font-icon-cancel" aria-hidden="true"></span></button></div>'
            . '<div class="modal-body">' . $form->forTemplate() . '</div>'
            . '</div></div></div>';

        $triggerHtml = '<button type="button" class="btn btn-secondary font-icon-upload" '
            . 'data-toggle="modal" data-target="#' . $modalId . '" '
            . 'data-modal="' . htmlspecialchars($modalHtml, ENT_QUOTES) . '">'
            . htmlspecialchars((string) _t(self::class . '.IMPORT_BUTTON', 'Import')) . '</button>';

        return [
            $this->targetFragment => $triggerHtml,
        ];
    }
}
