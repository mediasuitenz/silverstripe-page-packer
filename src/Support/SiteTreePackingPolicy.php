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
 * The SiteTree/CMSMain-specific {@see PackingPolicy} — hosts the Export modal's form on
 * whichever CMSMain-derived controller is currently rendering the page (via
 * {@see \MadeCurious\PagePacker\Extensions\CMSMainExportActionExtension}'s own
 * `ExportModalForm`/`doExport`, using the page's `PageID`) rather than the generic
 * {@see \MadeCurious\RecordPacker\Controllers\RecordPackerController}, and places the trigger
 * inside the `ActionMenus.MoreOptions` popup next to Save/Publish/Unpublish/Rollback rather than
 * pushed flat onto the action bar.
 *
 * Wired up as the `PackingPolicy.sitetree` Injector service variant in this module's
 * `_config/extensions.yml`, which is what `SiteTree` requests for its `PackableExtension`/
 * `RecordLockExtension` — see that file, and {@see PackingPolicy}'s own doc comment for why a
 * named Injector variant (rather than a subclass of either extension) is how this is wired up.
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
            'This page is currently being exported/imported by PagePacker.'
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
        // A page's history already lives on its own dedicated "Content Export" tab — see
        // CMSPageContentExportController.
        return false;
    }

    public function displayTitle(DataObject $record): ?string
    {
        // SiteTree's Title works the same way any other packable record's does — no
        // page-tree-specific behaviour needed here.
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
