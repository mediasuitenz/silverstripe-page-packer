<?php

namespace MadeCurious\PagePacker\Support;

use MadeCurious\PagePacker\Jobs\SiteTreeExportJob;
use MadeCurious\PagePacker\Jobs\SiteTreeImportJob;
use MadeCurious\PagePacker\Security\SiteTreeImportExportPermissions;
use MadeCurious\RecordPacker\Support\PackingPolicy;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * The SiteTree/CMSMain-specific {@see PackingPolicy}
 */
class SiteTreePackingPolicy implements PackingPolicy
{
    public function permissionCode(): string
    {
        return SiteTreeImportExportPermissions::SITETREE_IMPORT_EXPORT;
    }

    public function exportJobClass(): string
    {
        return SiteTreeExportJob::class;
    }

    public function importJobClass(): string
    {
        return SiteTreeImportJob::class;
    }

    public function lockedWarningMessage(): string
    {
        return (string) _t(
            self::class . '.LOCKED_WARNING',
            'This page is currently being exported/imported by Page Packer.'
            . ' Please try again in a minute or so.'
        );
    }

    public function getExportModalForm(DataObject $owner): ?Form
    {
        $controller = Controller::curr();

        if (!$controller || !$controller->hasMethod('ExportModalForm')) {
            return null;
        }

        $form = $controller->ExportModalForm();
        $form->Fields()->dataFieldByName('PageID')->setValue($owner->ID);

        return $form;
    }

    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void
    {
        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        if ($moreOptions) {
            $moreOptions->push($trigger);
        } else {
            // Fallback for any theme/version that doesn't build the usual ActionMenus
            $actions->push($trigger);
        }
    }

    public function showsHistoryFieldInline(): bool
    {
        // A page's history already lives on its own dedicated "Content Export" tab
        return false;
    }

    public function displayTitle(DataObject $record): ?string
    {
        return $record->hasField('Title') ? (string) $record->Title : null;
    }

    public function setDisplayTitle(DataObject $record, string $value): bool
    {
        if (!$record->hasField('Title')) {
            return false;
        }

        $record->Title = $value;

        return true;
    }
}
