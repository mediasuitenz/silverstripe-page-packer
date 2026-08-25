<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * The generic-DataObject equivalent of SiteTreeExportExtensionTest — proves PackableExtension,
 * configured with the default RecordPackingPolicy (see PackingPolicy's class doc), behaves the
 * same way on a plain, unversioned, non-SiteTree DataObject (see TestCatalogue's own doc
 * comment) as it does on a SiteTree page configured with the .sitetree variant — without
 * needing a hosting CMSMain-style controller at all (the default policy's trigger always points
 * at RecordPackerController's own fixed route).
 */
class PackableExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    public function testExportRequestsAutoScaffoldedTabIsRemoved(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $fields = $catalogue->getCMSFields();

        // No permission granted, so the formatted replacement isn't added either — this proves
        // specifically that the raw, unformatted has_many field never survives, independent of
        // the inline-history behaviour covered separately below.
        $this->assertNull($fields->dataFieldByName('ExportRequests'));
    }

    /**
     * Regression test: a generic record (unlike a SiteTree page, which gets a dedicated
     * "Content Export" tab from CMSPageContentExportController) has nowhere else to see its
     * export history or download a past export once the raw scaffolded field is removed — see
     * PackingPolicy::showsHistoryFieldInline()'s own doc comment. Without this, a Catalogue
     * editor could queue an export but never retrieve the resulting zip.
     */
    public function testExportHistoryIsShownInlineWithPermission(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $fields = $catalogue->getCMSFields();

        $this->assertNotNull(
            $fields->dataFieldByName('ExportRequests'),
            'A formatted Export history field must be shown inline for a generic packable record.'
        );
    }

    public function testExportHistoryIsAbsentWithoutPermission(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $fields = $catalogue->getCMSFields();

        $this->assertNull($fields->dataFieldByName('ExportRequests'));
    }

    public function testExportHistoryIsAbsentForAnUnsavedRecord(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'Not written yet']);

        $fields = $catalogue->getCMSFields();

        $this->assertNull($fields->dataFieldByName('ExportRequests'));
    }

    public function testExportTriggerAppearsForAPackableRecordWithPermission(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $actions = $catalogue->getCMSActions();

        $this->assertNotNull($actions->fieldByName('PackerExportModalTrigger'));
    }

    public function testExportTriggerIsAbsentWithoutPermission(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $actions = $catalogue->getCMSActions();

        $this->assertNull($actions->fieldByName('PackerExportModalTrigger'));
    }

    public function testExportTriggerIsAbsentWhileAnExportIsInFlight(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        QueuedJobDescriptor::create([
            'Implementation' => RecordExportJob::class,
            'Signature' => RecordExportJob::signatureForRecord($catalogue),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ])->write();

        $actions = $catalogue->getCMSActions();

        $this->assertNull($actions->fieldByName('PackerExportModalTrigger'));
    }

    public function testExportTriggerIsAbsentForAnUnsavedRecord(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'Not written yet']);

        $actions = $catalogue->getCMSActions();

        $this->assertNull($actions->fieldByName('PackerExportModalTrigger'));
    }
}
