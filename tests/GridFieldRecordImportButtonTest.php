<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Forms\GridField\GridFieldRecordImportButton;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
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
        return GridField::create('Records', 'Records', $modelClass::get());
    }

    public function testButtonRendersForAPackableModelClassWithPermission(): void
    {
        $this->logInWithPermission(['ADMIN', ImportExportPermissions::RECORD_IMPORT_EXPORT]);

        $fragments = (new GridFieldRecordImportButton())->getHTMLFragments($this->gridFieldFor(TestCatalogue::class));

        $this->assertArrayHasKey('before', $fragments);
        $this->assertStringContainsString('data-toggle="modal"', $fragments['before']);
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
