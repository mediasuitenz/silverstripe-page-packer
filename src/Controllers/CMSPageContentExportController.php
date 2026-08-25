<?php

namespace MadeCurious\PagePacker\Controllers;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Support\ExportHistoryField;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Security\Permission;

class CMSPageContentExportController extends CMSMain
{
    private static string $url_segment = 'pages/contentexport';

    private static string $url_rule = '/$Action/$ID/$OtherID';

    private static int $url_priority = 42;

    private static string $required_permission_codes = 'CMS_ACCESS_CMSMain';

    private static bool $ignore_menuitem = true;

    public function getTabIdentifier(): string
    {
        return 'contentexport';
    }

    public function getEditForm($id = null, $fields = null): Form
    {
        $id = $id ?: $this->currentRecordID();
        $record = $id ? $this->getRecord($id) : null;

        if ($record && Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            $fields = FieldList::create(ExportHistoryField::create($record));
        } else {
            $fields = FieldList::create();
        }

        $form = parent::getEditForm($id, $fields);
        // hide the redundant "preview" window
        $form->removeExtraClass('cms-previewable');
        $form->Fields()->removeByName('SilverStripeNavigator');

        return $form;
    }
}
