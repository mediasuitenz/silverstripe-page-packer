<?php

namespace MadeCurious\SiteTreeImportExport\Controllers;

use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Security\Permission;

/**
 * A genuine top-level CMS tab for a page's export history, peer to Content/Settings/History —
 * not achievable by adding a tab to SiteTree::getCMSFields() at all: that screen (Content) is
 * itself just one of three entirely separate controllers CMSMain/CMSPageEditController,
 * CMSPageSettingsController, and versioned-admin's CMSPageHistoryViewerController — the tab
 * strip that switches between them is a literal, hardcoded 3-`<li>` block in
 * `silverstripe/cms`'s own CMSMain_Content.ss template, with no config/extension seam a module
 * can plug a 4th entry into.
 *
 * This class is the "peer controller" half of the fix, mirroring exactly how
 * CMSPageSettingsController is built (extends CMSMain, own $url_segment, own getTabIdentifier(),
 * a getEditForm() override providing just this screen's content, with the standard
 * CMSMain wrapping/actions still applied via parent::getEditForm()). The other half — actually
 * making a 4th `<li>` appear — requires overriding CMSMain_Content.ss itself, which is NOT
 * shippable inside this module (project templates always take priority over a module's, never
 * the other way around): the project consuming this module must copy
 * docs/templates/CMSMain_Content.ss into its own app/theme. See that file's own header comment.
 */
class CMSPageContentExportController extends CMSMain
{
    private static string $url_segment = 'pages/contentexport';

    private static string $url_rule = '/$Action/$ID/$OtherID';

    private static int $url_priority = 42;

    private static string $required_permission_codes = 'CMS_ACCESS_CMSMain';

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

        return parent::getEditForm($id, $fields);
    }
}
