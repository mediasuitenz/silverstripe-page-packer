<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Controllers\CMSPageContentExportController;
use MadeCurious\PagePacker\Jobs\SiteTreeExportJob;
use MadeCurious\PagePacker\Security\SiteTreeImportExportPermissions;
use MadeCurious\RecordPacker\Support\ExportQueuer;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Builds the export modal's form and handles its submission.
 */
class CMSMainExportActionExtension extends Extension
{
    private static $allowed_actions = [
        'ExportModalForm',
        'doExport',
    ];

    public function ExportModalForm(): Form
    {
        $fields = FieldList::create(
            HiddenField::create('PageID'),
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

        $form = Form::create($this->owner, 'ExportModalForm', $fields, $actions);
        $form->setFormAction($this->owner->Link('ExportModalForm'));
        $form->setValidationExemptActions(['doExport']);
        $form->addExtraClass('page-packer-modal-form');

        return $form;
    }

    public function doExport(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check(SiteTreeImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return Security::permissionFailure($this->owner);
        }

        $id = (int) ($data['PageID'] ?? 0);
        /** @var SiteTree|null $record */
        $record = SiteTree::get()->byID($id);

        if (!$record || !$record->exists() || !$record->canView()) {
            return Security::permissionFailure($this->owner);
        }

        $includeAssets = !empty($data['IncludeAssets']);
        $description = trim((string) ($data['Description'] ?? ''));

        ExportQueuer::queue($record, SiteTreeExportJob::class, $includeAssets, $description);

        $message = _t(
            self::class . '.QUEUED_FOR_EXPORT',
            "Queued '{title}' for export.",
            ['title' => $record->Title]
        );

        $link = CMSPageContentExportController::singleton()->Link('show/' . $record->ID)
            . '?page-packer-toast=' . rawurlencode($message ?? '');

        return $this->owner->redirect($link);
    }
}
