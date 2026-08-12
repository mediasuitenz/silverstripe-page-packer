<?php

namespace MadeCurious\SiteTreeImportExport\Tests;

use SilverStripe\CMS\Model\SiteTree;
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
}
