<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Controllers\CMSPageContentExportController;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Permission;

class CMSMainContentExportTabExtension extends Extension
{
    public function getLinkPageContentExport(): ?string
    {
        $owner = $this->getOwner();
        $id = $owner->currentRecordID();

        if (!$id) {
            return null;
        }

        return $owner->LinkWithSearch(
            Controller::join_links(CMSPageContentExportController::singleton()->Link('show'), $id)
        );
    }

    public function getHasContentExport(): bool
    {
        return Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT);
    }
}
