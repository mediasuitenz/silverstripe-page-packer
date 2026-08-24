<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Model\RecordExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * The generic, any-DataObject equivalent of {@see SiteTreeExportExtension} — apply this, plus
 * {@see RecordLockExtension}, to a project DataObject to get the same "Export" button and export
 * history a SiteTree page gets out of the box.
 *
 * Two hosting contexts are supported:
 * - A record with its own LeftAndMain-style getCMSActions() (rare for a plain DataObject, but
 *   the same shape SiteTree/CMSMain uses) gets the trigger via updateCMSActions() below, exactly
 *   like SiteTreeExportExtension.
 * - A record edited through an ordinary GridField (the common case — see docs) instead gets it
 *   via {@see GridFieldRecordActionsExtension}, which calls addExportTrigger() directly, because
 *   GridFieldDetailForm_ItemRequest builds its action bar itself and never calls
 *   DataObject::getCMSActions() at all.
 *
 * A deliberately independent class from SiteTreeExportExtension (rather than reusing/subclassing
 * it) so the SiteTree/CMSMain export flow this was generalised from is completely unaffected —
 * see also RecordExportRequest's class doc for why export history is tracked in its own table
 * rather than widening ExportRequest itself.
 */
class PackableExtension extends Extension
{
    private static $has_many = [
        'ExportRequests' => RecordExportRequest::class,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        // hide the export requests default — same reasoning as SiteTreeExportExtension: an
        // editor sees this history through the module's own UI, not the raw scaffolded relation.
        $fields->removeByName('ExportRequests');
    }

    public function updateCMSActions(FieldList $actions): void
    {
        $this->addExportTrigger($actions);
    }

    /**
     * Builds the "Export" trigger button (carrying the whole modal as a `data-modal` HTML
     * string, same technique as SiteTreeExportExtension) and pushes it onto $actions — unless
     * the current member lacks permission, the record hasn't been saved yet, or an export/import
     * for it is already in flight.
     *
     * Public (rather than folded into updateCMSActions()) so GridFieldRecordActionsExtension can
     * call it directly against the same extension instance already attached to the record.
     */
    public function addExportTrigger(FieldList $actions): void
    {
        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        $locked = $this->owner->hasExtension(RecordLockExtension::class)
            && $this->owner->pendingJobExists([RecordExportJob::class]);

        if ($locked) {
            return;
        }

        // Reused as-is: this modal's open/close behaviour is generic (keyed off
        // data-toggle="modal"/data-modal), nothing SiteTree-specific about it.
        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/export-modal.js');

        $controller = RecordPackerController::singleton();

        $modalId = 'PackerExportModal' . $this->owner->ID;
        $form = $controller->ExportModalForm();
        $form->Fields()->dataFieldByName('RecordClassName')->setValue(get_class($this->owner));
        $form->Fields()->dataFieldByName('RecordID')->setValue($this->owner->ID);

        $modalHtml = '<div id="' . $modalId . '" class="modal fade" tabindex="-1" role="dialog">'
            . '<div class="modal-dialog" role="document"><div class="modal-content">'
            . '<div class="modal-header"><h2 class="modal-title">'
            . htmlspecialchars((string) _t(self::class . '.MODAL_TITLE', 'Export record'))
            . '</h2><button type="button" class="btn btn-close btn--icon-xl btn--no-text modal__close-button" '
            . 'data-dismiss="modal" aria-label="Close" title="Close">'
            . '<span class="btn__icon font-icon-cancel" aria-hidden="true"></span></button></div>'
            . '<div class="modal-body">' . $form->forTemplate() . '</div>'
            . '</div></div></div>';

        $triggerHtml = '<button type="button" class="btn btn-secondary font-icon-share" '
            . 'data-toggle="modal" data-target="#' . $modalId . '" '
            . 'data-modal="' . htmlspecialchars($modalHtml, ENT_QUOTES) . '">'
            . htmlspecialchars((string) _t(self::class . '.EXPORT_BUTTON', 'Export')) . '</button>';

        $actions->push(LiteralField::create('PackerExportModalTrigger', $triggerHtml));
    }
}
