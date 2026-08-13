<?php

namespace MadeCurious\PagePacker\Security;

use SilverStripe\Security\PermissionProvider;

/**
 * Registers the dedicated permission that gates every export/import action this module adds
 * (Settings tab export button, tree "Import Page" tool, and both queued jobs' own internal
 * re-check of the acting Member). Deliberately not tied to standard page edit/create rights, so
 * it can be granted or withheld independently of them.
 */
class ImportExportPermissions implements PermissionProvider
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
                    'Allow exporting a page to a downloadable file, and importing such a file to'
                    . ' create a new page.'
                ),
                'sort' => 100,
            ],
        ];
    }
}
