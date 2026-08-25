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

    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void
    {
        $actions->push($trigger);
    }
}
