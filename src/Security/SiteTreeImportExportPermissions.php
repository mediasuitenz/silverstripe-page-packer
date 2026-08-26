<?php

namespace MadeCurious\PagePacker\Security;

use SilverStripe\Security\PermissionProvider;

/**
 * Gates the page-tree export/import UI (CMSMainExportActionExtension,
 * CMSMainAddFormImportExtension, CMSPageContentExportController) — kept separate from
 * madecurious/silverstripe-record-packer's own ImportExportPermissions::RECORD_IMPORT_EXPORT so
 * a group can be granted export/import on pages without also being granted it on generic
 * records (or vice versa).
 */
class SiteTreeImportExportPermissions implements PermissionProvider
{
    const SITETREE_IMPORT_EXPORT = 'SITETREE_IMPORT_EXPORT';

    public function providePermissions()
    {
        return [
            self::SITETREE_IMPORT_EXPORT => [
                'name' => _t(
                    __CLASS__ . '.PERMISSION_NAME',
                    'Export/import SiteTree page content'
                ),
                'category' => _t(
                    __CLASS__ . '.PERMISSION_CATEGORY',
                    'Content'
                ),
                'help' => _t(
                    __CLASS__ . '.PERMISSION_HELP',
                    'Allow exporting a page to a downloadable file, and importing such a file to create a new page.'
                ),
                'sort' => 100,
            ],
        ];
    }
}
