<?php

namespace MadeCurious\SiteTreeImportExport\Extensions;

use MadeCurious\SiteTreeImportExport\Controllers\CMSPageContentExportController;
use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeExportJob;
use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
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

/**
 * Builds the export modal's form (see {@see SiteTreeExportExtension::updateCMSActions()}, which
 * embeds this form's rendered HTML into the trigger button's `data-modal` attribute — the same
 * pattern `SilverStripe\Forms\GridField\GridFieldImportButton` uses for its own CSV-import
 * dialog, generalized to work outside a GridField) and handles its submission.
 *
 * This is a genuinely separate `<form>`, not a checkbox/field added to the page's own edit form:
 * the modal HTML is appended directly to `<body>` on click (see the JS in
 * SiteTreeExportExtension), so it's never nested inside the CMS's own edit-form tag — avoiding
 * both the HTML-invalid nested-`<form>` problem and, more importantly, the record-dirty-tracking
 * concern a checkbox/description field would raise if it lived inside the page's own form.
 */
class CMSMainExportActionExtension extends Extension
{
    private static $allowed_actions = [
        'ExportModalForm',
        'doExport',
    ];

    /**
     * Deliberately generic (not page-specific) — SiteTreeExportExtension::updateCMSActions()
     * calls this and then overwrites the PageID hidden field's value for the specific page being
     * rendered. The framework also calls this fresh (with no page context at all) to reconstruct
     * the form when handling the POSTed submission — the actually-submitted PageID always comes
     * through via the browser's posted form data regardless, the same way CMSMainAddForm's own
     * ParentID handling works.
     */
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
        $form->addExtraClass('sitetree-import-export-modal-form');

        return $form;
    }

    public function doExport(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return Security::permissionFailure($this->owner);
        }

        $id = (int) ($data['PageID'] ?? 0);
        /** @var SiteTree|null $record */
        $record = DataObject::get(SiteTree::class)->byID($id);

        if (!$record || !$record->exists() || !$record->canView()) {
            return Security::permissionFailure($this->owner);
        }

        $includeAssets = !empty($data['IncludeAssets']);
        $description = trim((string) ($data['Description'] ?? ''));

        $exportRequest = ExportRequest::create();
        $exportRequest->PageID = $record->ID;
        $exportRequest->MemberID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : null;
        $exportRequest->Status = ExportRequest::STATUS_QUEUED;
        $exportRequest->Origin = ExportRequest::ORIGIN_EXPORT;
        $exportRequest->Description = $description;
        $exportRequest->IncludeAssets = $includeAssets;
        $exportRequest->write();

        $job = new SiteTreeExportJob($record, $includeAssets, $exportRequest->ID);
        QueuedJobService::singleton()->queueJob($job);

        $message = _t(
            self::class . '.QUEUED_FOR_EXPORT',
            "Queued '{title}' for export.",
            ['title' => $record->Title]
        );

        // A plain (non-AJAX) form submission causes a real browser navigation on this redirect,
        // not a PJAX panel swap — the CMS's own X-Status/toast handling only fires for AJAX
        // responses, so there's nothing to hook into here. Instead: land on the Content Export
        // tab (where the queued export will shortly appear in the history list) with the
        // confirmation message in the query string; SiteTreeExportExtension's JS reads it on
        // load, renders a toast using the CMS's own .toasts/.toast markup/CSS, and strips the
        // param from the URL so a refresh doesn't re-show it.
        $link = CMSPageContentExportController::singleton()->Link('show/' . $record->ID)
            . '?sitetree-export-toast=' . rawurlencode($message ?? '');

        return $this->owner->redirect($link);
    }
}
