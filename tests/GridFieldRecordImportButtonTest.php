<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Forms\GridField\GridFieldRecordImportButton;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;

/**
 * Covers GridFieldRecordImportButton — the opt-in GridField/DataObject equivalent of the page
 * tree's "Add new page" import option. Only ever renders for a GridField whose model class has
 * PackableExtension applied, and only with permission.
 */
class GridFieldRecordImportButtonTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function gridFieldFor(string $modelClass): GridField
    {
        $gridField = GridField::create('Records', 'Records', $modelClass::get());
        // GridFieldRecordImportButton now calls $gridField->Link() to populate GridFieldLink — a
        // bare GridField (as used everywhere else in this test file) has no hosting Form by
        // default, and FormField::Link() requires one.
        Form::create(RecordPackerController::create(), 'TestForm', FieldList::create($gridField), FieldList::create());

        return $gridField;
    }

    public function testButtonRendersForAPackableModelClassWithPermission(): void
    {
        $this->logInWithPermission(['ADMIN', ImportExportPermissions::RECORD_IMPORT_EXPORT]);

        $fragments = (new GridFieldRecordImportButton())->getHTMLFragments($this->gridFieldFor(TestCatalogue::class));

        $this->assertArrayHasKey('before', $fragments);
        $this->assertStringContainsString('data-toggle="modal"', $fragments['before']);
    }

    public function testFormCarriesTheGridFieldsOwnLinkForRedirectingIntoTheNewStub(): void
    {
        $this->logInWithPermission(['ADMIN', ImportExportPermissions::RECORD_IMPORT_EXPORT]);

        $gridField = $this->gridFieldFor(TestCatalogue::class);
        $fragments = (new GridFieldRecordImportButton())->getHTMLFragments($gridField);

        // The modal markup is itself embedded (HTML-entity-escaped) inside the trigger button's
        // own data-modal attribute, so check for the field name and its value as plain
        // substrings rather than a literal `name="..." value="..."` fragment.
        $this->assertStringContainsString('GridFieldLink', $fragments['before']);
        $this->assertStringContainsString(
            $gridField->Link(),
            $fragments['before'],
            "doImport() can only redirect into the new stub's own edit view if this is present."
        );
    }

    public function testButtonIsAbsentForANonPackableModelClass(): void
    {
        $this->logInWithPermission(['ADMIN', ImportExportPermissions::RECORD_IMPORT_EXPORT]);

        // TestProduct is a real, installed DataObject, but doesn't have PackableExtension
        // applied — it's the owned child, not something you'd import standalone.
        $fragments = (new GridFieldRecordImportButton())->getHTMLFragments($this->gridFieldFor(TestProduct::class));

        $this->assertSame([], $fragments);
    }

    public function testButtonIsAbsentWithoutPermission(): void
    {
        $this->logOut();

        $fragments = (new GridFieldRecordImportButton())->getHTMLFragments($this->gridFieldFor(TestCatalogue::class));

        $this->assertSame([], $fragments);
    }
}
