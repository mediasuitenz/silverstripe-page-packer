<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\SiteTreeExportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;

/**
 * The SiteTree-specific instance of {@see PackableExtension} — same "Export" trigger/modal
 * mechanics, but hosted on whichever CMSMain-derived controller is currently rendering the page
 * (via {@see \MadeCurious\PagePacker\Extensions\CMSMainExportActionExtension}'s own
 * `ExportModalForm`/`doExport`, using the page's `PageID`) rather than the generic
 * {@see \MadeCurious\PagePacker\Controllers\RecordPackerController}, and placed inside the
 * `ActionMenus.MoreOptions` popup next to Save/Publish/Unpublish/Rollback rather than pushed
 * flat onto the action bar.
 */
class SiteTreeExportExtension extends PackableExtension
{
    public function exportPermissionCode(): string
    {
        return ImportExportPermissions::SITETREE_IMPORT_EXPORT;
    }

    public function lockExtensionClass(): string
    {
        return SiteTreeLockExtension::class;
    }

    public function exportJobClass(): string
    {
        return SiteTreeExportJob::class;
    }

    protected function getExportModalForm(): ?Form
    {
        $controller = Controller::curr();

        if (!$controller || !$controller->hasMethod('ExportModalForm')) {
            return null;
        }

        $form = $controller->ExportModalForm();
        $form->Fields()->dataFieldByName('PageID')->setValue($this->owner->ID);

        return $form;
    }

    protected function placeExportTrigger(FieldList $actions, LiteralField $trigger): void
    {
        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        if ($moreOptions) {
            $moreOptions->push($trigger);
        } else {
            // Fallback for any theme/version that doesn't build the usual ActionMenus
            $actions->push($trigger);
        }
    }
}
