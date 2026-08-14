<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;

class SiteTreeExportExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * SiteTree::getCMSFields() ends with parent::getCMSFields() (DataObject's generic
     * FormScaffolder), which auto-generates a tab for every has_many relation it isn't told to
     * skip — declaring the ExportRequests has_many (so CMSPageContentExportController's GridField
     * has a real RelationList) got us a free, unwanted "Export requests" tab sitting directly
     * under Root alongside "Main", duplicating that dedicated top-level tab. Must stay removed.
     */
    public function testExportRequestsAutoScaffoldedTabIsRemoved(): void
    {
        $page = SiteTree::create(['Title' => 'A page']);
        $page->write();

        $fields = $page->getCMSFields();

        $this->assertNull(
            $fields->dataFieldByName('ExportRequests'),
            'The auto-scaffolded has_many tab/field must be removed from the main Content'
            . ' screen — it lives on its own dedicated top-level tab instead.'
        );

        $root = $fields->fieldByName('Root');

        foreach ($root->Tabs() as $tab) {
            $this->assertNotSame('ExportRequests', $tab->getName());
        }
    }

    /**
     * The Export trigger belongs behind the "More options" three-dot popup next to Save/Publish
     * (ActionMenus.MoreOptions — the same Tab SiteTree's own Unpublish/Rollback actions live in),
     * not as a top-level button in the main action bar.
     */
    public function testExportTriggerIsInMoreOptionsNotTopLevel(): void
    {
        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $page = SiteTree::create(['Title' => 'A page']);
        $page->write();

        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        $controller = CMSMain::create();
        $controller->setRequest($request);
        $controller->pushCurrent();

        try {
            $actions = $page->getCMSActions();
        } finally {
            $controller->popCurrent();
        }

        $this->assertNull(
            $actions->fieldByName('SiteTreeExportModalTrigger'),
            'The export trigger must not sit in the top-level action bar.'
        );

        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');
        $this->assertNotNull($moreOptions, 'Expected the ActionMenus.MoreOptions tab to exist.');
        $this->assertNotNull(
            $moreOptions->fieldByName('SiteTreeExportModalTrigger'),
            'The export trigger must be inside the More options popup.'
        );
    }
}
