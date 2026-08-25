<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
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
        // GridField::Link() (used by e.g. the right-hand "add new" button once canCreate() is
        // true) needs the GridField attached to a real hosting Form.
        Form::create(RecordPackerController::create(), 'TestForm', FieldList::create($gridField), FieldList::create());

        $detailForm = $config->getComponentByType(GridFieldDetailForm::class);

        // A bare Controller lacks a url_segment, which breaks Link() calls the moment any
        // rendering path needs one (e.g. the Delete button/right-hand group) — RecordPackerController
        // has a real one configured.
        $controller = RecordPackerController::create();
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

    /**
     * The trigger must sit between Save and Delete, not wherever a bare push() would land it
     * (the trailing right-aligned button group) — see RecordPackingPolicy::placeExportTrigger().
     * Needs ADMIN (on top of the module's own permission) so canDelete() actually renders a
     * Delete button ('action_doDelete' — FormAction prefixes every action field's name with
     * 'action_') to position against at all.
     */
    public function testExportTriggerIsPositionedImmediatelyBeforeDelete(): void
    {
        $this->logInWithPermission(['ADMIN', ImportExportPermissions::RECORD_IMPORT_EXPORT]);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $actions = $this->itemRequestFor($catalogue)->ItemEditForm()->Actions();

        $names = [];
        foreach ($actions as $field) {
            $names[] = $field->getName();
        }

        $this->assertContains(
            'action_doDelete',
            $names,
            'Expected a Delete button to be present to position against.'
        );
        $exportIndex = array_search('PackerExportModalTrigger', $names, true);
        $deleteIndex = array_search('action_doDelete', $names, true);

        $this->assertSame(
            $deleteIndex - 1,
            $exportIndex,
            'Export trigger must sit immediately before Delete: ' . implode(', ', $names)
        );
    }
}
