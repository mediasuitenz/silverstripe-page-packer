<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\SiteTreeExportJob;
use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Adds the "Export" button and Export Requests Gridfield
 */
class SiteTreeExportExtension extends Extension
{

    private static $has_many = [
        'ExportRequests' => ExportRequest::class,
    ];


    public function updateCMSFields(FieldList $fields): void
    {
        // hide the export requests default
        $fields->removeByName('ExportRequests');
    }

    /**
     * Adds a plain button carrying the whole modal as a `data-modal` HTML string
     */
    public function updateCMSActions(FieldList $actions): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/export-modal.js');

        $locked = $this->owner->hasExtension(SiteTreeLockExtension::class)
            && $this->owner->pendingJobExists([SiteTreeExportJob::class]);

        // Hide the button while an export for this page is already in flight    
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
            // Fallback for any theme/version that doesn't build the usual ActionMenus
            $actions->push($trigger);
        }
    }

}
