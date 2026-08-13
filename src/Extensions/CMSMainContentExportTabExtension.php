<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Controllers\CMSPageContentExportController;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Permission;

/**
 * Supplies the link/visibility the project's CMSMain_Content.ss override (see
 * docs/templates/CMSMain_Content.ss) needs to render the "Content Export" tab as a genuine peer
 * of Content/Settings/History — mirrors andrewandante/silverstripe-clippy's own
 * CMSMainExtension, which adds a "User Guides" tab to the same template the same way.
 */
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

    /**
     * Always true for a permitted user regardless of whether the page has any export history
     * yet — unlike clippy's $HasUserGuides (conditional on content existing), export is a
     * primary action available on any page, not conditional on prior use.
     */
    public function getHasContentExport(): bool
    {
        return Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT);
    }
}
