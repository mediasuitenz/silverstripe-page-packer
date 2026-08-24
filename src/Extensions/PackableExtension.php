<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Support\ModalMarkup;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Apply this (plus {@see RecordLockExtension}) to a project DataObject to get an "Export"
 * button + export history — the same capability a SiteTree page gets via
 * {@see SiteTreeExportExtension}, which extends this class rather than duplicating it.
 *
 * Two hosting contexts are supported:
 * - A record with its own LeftAndMain-style getCMSActions() (rare for a plain DataObject, but
 *   the same shape SiteTree/CMSMain uses) gets the trigger via updateCMSActions() below.
 * - A record edited through an ordinary GridField (the common case — see the developer guide)
 *   instead gets it via {@see GridFieldRecordActionsExtension}, which calls addExportTrigger()
 *   directly, because GridFieldDetailForm_ItemRequest builds its action bar itself and never
 *   calls DataObject::getCMSActions() at all.
 *
 * Everything about *how* the trigger is gated/built is overridable via the protected/public
 * hook methods below — SiteTreeExportExtension overrides them to host the form on CMSMain
 * instead of {@see RecordPackerController}, place the trigger inside
 * `ActionMenus.MoreOptions` instead of pushing it flat, and check the SiteTree-specific
 * permission/lock/job classes.
 */
class PackableExtension extends Extension
{
    private static $has_many = [
        'ExportRequests' => ExportRequest::class,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        // hide the export requests default — an editor sees this history through the module's
        // own UI, not the raw scaffolded relation.
        $fields->removeByName('ExportRequests');
    }

    public function updateCMSActions(FieldList $actions): void
    {
        $this->addExportTrigger($actions);
    }

    /**
     * Builds the "Export" trigger button (carrying the whole modal as a `data-modal` HTML
     * string) and places it onto $actions — unless the current member lacks permission, the
     * record hasn't been saved yet, an export/import for it is already in flight, or (for a
     * SiteTree page) no CMSMain-hosted form is available to build.
     *
     * Public (rather than folded into updateCMSActions()) so GridFieldRecordActionsExtension can
     * call it directly against the same extension instance already attached to the record.
     */
    public function addExportTrigger(FieldList $actions): void
    {
        if (!Permission::check($this->exportPermissionCode())) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        $locked = $this->owner->hasExtension($this->lockExtensionClass())
            && $this->owner->pendingJobExists([$this->exportJobClass()]);

        if ($locked) {
            return;
        }

        $form = $this->getExportModalForm();

        if (!$form) {
            return;
        }

        // Reused as-is: this modal's open/close behaviour is generic (keyed off
        // data-toggle="modal"/data-modal), nothing SiteTree-specific about it.
        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/export-modal.js');

        $modalId = 'PackerExportModal' . $this->owner->ID;
        $modalHtml = ModalMarkup::modal(
            $modalId,
            (string) _t(self::class . '.MODAL_TITLE', 'Export record'),
            $form->forTemplate()
        );
        $trigger = LiteralField::create(
            'PackerExportModalTrigger',
            ModalMarkup::trigger(
                $modalId,
                (string) _t(self::class . '.EXPORT_BUTTON', 'Export'),
                'font-icon-share',
                $modalHtml
            )
        );

        $this->placeExportTrigger($actions, $trigger);
    }

    /**
     * Which permission gates this record's export/import. Overridden by SiteTreeExportExtension
     * to SITETREE_IMPORT_EXPORT.
     */
    public function exportPermissionCode(): string
    {
        return ImportExportPermissions::RECORD_IMPORT_EXPORT;
    }

    /**
     * Which lock extension (and therefore which job classes) governs whether this record is
     * currently mid-export/import. Overridden by SiteTreeExportExtension to
     * SiteTreeLockExtension.
     */
    public function lockExtensionClass(): string
    {
        return RecordLockExtension::class;
    }

    /**
     * Which job class actually gets queued for this record's export. Overridden by
     * SiteTreeExportExtension to SiteTreeExportJob.
     */
    public function exportJobClass(): string
    {
        return RecordExportJob::class;
    }

    /**
     * Builds and pre-populates the export modal's form. The generic implementation always hosts
     * it on {@see RecordPackerController}'s own fixed route; SiteTreeExportExtension overrides
     * this to reuse CMSMain's own hosted form instead (see CMSMainExportActionExtension),
     * returning null if no such controller is currently available to host it.
     */
    protected function getExportModalForm(): ?Form
    {
        $form = RecordPackerController::singleton()->ExportModalForm();
        $form->Fields()->dataFieldByName('RecordClassName')->setValue(get_class($this->owner));
        $form->Fields()->dataFieldByName('RecordID')->setValue($this->owner->ID);

        return $form;
    }

    /**
     * Where the trigger lands in $actions. The generic implementation just pushes it onto the
     * end; SiteTreeExportExtension overrides this to push into `ActionMenus.MoreOptions`
     * instead, alongside SiteTree's own Unpublish/Rollback actions.
     */
    protected function placeExportTrigger(FieldList $actions, LiteralField $trigger): void
    {
        $actions->push($trigger);
    }
}
