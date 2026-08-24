<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Model\RecordExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The generic-DataObject equivalent of ExportRequestTest — same staleness/permission coverage,
 * against the polymorphic Record relation (RecordID + RecordClass) rather than ExportRequest's
 * fixed SiteTree `Page` has_one, and against an unversioned owner (TestCatalogue has no draft/
 * live distinction at all, unlike a SiteTree page).
 */
class RecordExportRequestTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();

        parent::tearDown();
    }

    public function testNeverTouchedRecordIsNeverStaleForAnExportOriginEntry(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $request = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $catalogue->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale());
    }

    public function testImportOriginEntryIsStaleAsSoonAsTheRecordHasAnyContent(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $catalogue = TestCatalogue::create(['Title' => 'Imported catalogue']);
        $catalogue->write();

        $request = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_IMPORT,
            // Deliberately left at its default ('') — see RecordExportRequest::isStale()'s doc.
        ]);
        $request->write();

        // An unversioned DataObject's "current" content is its live content by definition — the
        // record already existing at all, at Created time, means it's already stale.
        $this->assertTrue($request->isStale());
    }

    public function testExportOriginEntryIsStaleOnlyAfterALaterEdit(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $catalogue = TestCatalogue::create(['Title' => 'Original catalogue']);
        $catalogue->write();

        $request = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $catalogue->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing the current content.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        $catalogue->Title = 'Edited catalogue';
        $catalogue->write();

        $this->assertTrue($request->isStale(), 'Stale after a later edit to the record itself.');
    }

    /**
     * Mirrors ExportRequestTest's own nested-block staleness case: editing an OWNED child
     * (TestProduct) must count as staleness even though the parent TestCatalogue record itself
     * is never re-saved.
     */
    public function testStaleAfterEditingAnOwnedChildEvenWhenTheParentIsUntouched(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $product = TestProduct::create(['Title' => 'Widget']);
        $product->CatalogueID = $catalogue->ID;
        $product->write();

        $request = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $product->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        $product->Title = 'Updated widget';
        $product->write();

        $this->assertTrue($request->isStale(), 'Stale after editing an owned child, even though the parent was not re-saved.');
    }

    public function testDeletePermissionIsGatedByTheModulesPermission(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Owner of an export']);
        $catalogue->write();
        $request = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_EXPORT,
        ]);
        $request->write();

        $this->logOut();
        $this->assertFalse((bool) $request->canDelete());

        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);
        $this->assertTrue((bool) $request->canDelete());
    }

    public function testDeletingARecordExportRequestRemovesItFromTheOwnersHistory(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'Owner of two exports']);
        $catalogue->write();

        $keep = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_EXPORT,
        ]);
        $keep->write();
        $delete = RecordExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_EXPORT,
        ]);
        $delete->write();

        $this->assertSame(2, $catalogue->ExportRequests()->count());

        $this->assertTrue((bool) $delete->canDelete());
        $delete->delete();

        $remaining = $catalogue->ExportRequests();
        $this->assertSame(1, $remaining->count());
        $this->assertSame($keep->ID, $remaining->first()->ID);
    }
}
