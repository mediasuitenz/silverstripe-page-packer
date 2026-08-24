<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;

/**
 * Proves the actual real-world path this generalisation exists for: a packable DataObject
 * edited through an ordinary GridField (GridFieldConfig_RecordEditor + GridFieldDetailForm, the
 * same config a real project ModelAdmin uses) — NOT the page tree/CMSMain — still gets the
 * Export trigger, via GridFieldRecordActionsExtension's updateFormActions() hook rather than
 * PackableExtension's own updateCMSActions() (which GridFieldDetailForm_ItemRequest never
 * calls — see that extension's class doc).
 */
class GridFieldRecordActionsExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function itemRequestFor(TestCatalogue $record): GridFieldDetailForm_ItemRequest
    {
        $gridField = GridField::create('TestCatalogues', 'Catalogues', TestCatalogue::get());
        $config = GridFieldConfig_RecordEditor::create();
        $gridField->setConfig($config);

        $detailForm = $config->getComponentByType(GridFieldDetailForm::class);

        $controller = Controller::create();
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $controller->pushCurrent();

        $itemRequest = GridFieldDetailForm_ItemRequest::create($gridField, $detailForm, $record, $controller, 'Form');
        $itemRequest->setRequest($request);

        return $itemRequest;
    }

    public function testExportTriggerAppearsOnAGridFieldEditedPackableRecord(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $form = $this->itemRequestFor($catalogue)->ItemEditForm();

        $this->assertNotNull($form->Actions()->fieldByName('PackerExportModalTrigger'));
    }

    public function testExportTriggerIsAbsentWithoutPermissionInAGridField(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $form = $this->itemRequestFor($catalogue)->ItemEditForm();

        $this->assertNull($form->Actions()->fieldByName('PackerExportModalTrigger'));
    }
}
