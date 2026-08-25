<?php

namespace MadeCurious\PagePacker\Support;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Jobs\RecordImportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * The default {@see PackingPolicy} — applies to any project DataObject that isn't a SiteTree
 * page (see {@see SiteTreePackingPolicy} for that one). Registered as the default alias for the
 * `PackingPolicy` Injector service in this module's `_config/extensions.yml`, so it's what
 * `PackableExtension`/`RecordLockExtension` get when applied to a class without requesting the
 * `.sitetree` variant.
 */
class RecordPackingPolicy implements PackingPolicy
{
    public function permissionCode(): string
    {
        return ImportExportPermissions::RECORD_IMPORT_EXPORT;
    }

    public function exportJobClass(): string
    {
        return RecordExportJob::class;
    }

    public function importJobClass(): string
    {
        return RecordImportJob::class;
    }

    public function lockedWarningMessage(): string
    {
        return (string) _t(
            self::class . '.LOCKED_WARNING',
            'This record is currently being exported/imported by PagePacker.'
            . ' Please try again in a minute or so.'
        );
    }

    public function getExportModalForm(DataObject $owner): ?Form
    {
        $form = RecordPackerController::singleton()->ExportModalForm();
        $form->Fields()->dataFieldByName('RecordClassName')->setValue(get_class($owner));
        $form->Fields()->dataFieldByName('RecordID')->setValue($owner->ID);

        return $form;
    }

    /**
     * Placed immediately before the Delete button — i.e. between Save and Delete — rather than
     * appended at the very end of $actions, which for a GridField-hosted record lands inside
     * the trailing right-aligned button group instead of the main action row.
     * GridFieldDetailForm_ItemRequest::getFormActions() builds it as
     * `FormAction::create('doDelete', ...)`, but FormAction itself prefixes every action field's
     * name with `action_` (so submitted actions never collide with ordinary data field names in
     * POST data) — so the name actually present in the FieldList is `action_doDelete`, not
     * `doDelete`.
     *
     * insertBefore() falls back to appending at the end (its own default
     * $appendIfMissing behaviour) if there's no Delete button to sit before at all — e.g. the
     * current member can't delete this record, or $actions comes from a getCMSActions() context
     * that doesn't use that name.
     */
    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void
    {
        $actions->insertBefore('action_doDelete', $trigger);
    }

    public function showsHistoryFieldInline(): bool
    {
        return true;
    }
}
