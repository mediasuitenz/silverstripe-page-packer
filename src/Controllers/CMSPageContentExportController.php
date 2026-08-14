<?php

namespace MadeCurious\PagePacker\Controllers;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
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
            $config = GridFieldConfig_Base::create();
            $config->addComponent(GridFieldDeleteAction::create());

            // using setFieldFormatting() to ensure we get rendered HTML
            $config->getComponentByType(GridFieldDataColumns::class)->setFieldFormatting([
                'StaleBadge' => fn ($value, $item) => $item->StaleBadge,
                'DownloadLinkHtml' => fn ($value, $item) => $item->DownloadLinkHtml,
            ]);

            $fields = FieldList::create(
                GridField::create(
                    'ExportRequests',
                    _t(__CLASS__ . '.HISTORY_TITLE', 'Export history'),
                    $record->ExportRequests(),
                    $config
                )
            );
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
