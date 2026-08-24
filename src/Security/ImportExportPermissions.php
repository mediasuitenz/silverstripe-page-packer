<?php

namespace MadeCurious\PagePacker\Security;

use SilverStripe\Security\PermissionProvider;

class ImportExportPermissions implements PermissionProvider
{
    const SITETREE_IMPORT_EXPORT = 'SITETREE_IMPORT_EXPORT';

    /**
     * The generic, any-DataObject equivalent of SITETREE_IMPORT_EXPORT — gates
     * PackableExtension/RecordPackerController for a project DataObject rather than a SiteTree
     * page. Kept as its own permission (rather than reusing SITETREE_IMPORT_EXPORT) so a group
     * can be granted one without the other.
     */
    const RECORD_IMPORT_EXPORT = 'RECORD_IMPORT_EXPORT';

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
            self::RECORD_IMPORT_EXPORT => [
                'name' => _t(
                    __CLASS__ . '.RECORD_PERMISSION_NAME',
                    'Export/import PagePacker-enabled records'
                ),
                'category' => _t(
                    __CLASS__ . '.PERMISSION_CATEGORY',
                    'Content'
                ),
                'help' => _t(
                    __CLASS__ . '.RECORD_PERMISSION_HELP',
                    'Allow exporting a PagePacker-enabled record (e.g. one edited via a GridField) to a'
                    . ' downloadable file, and importing such a file to create a new record.'
                ),
                'sort' => 101,
            ],
        ];
    }
}
