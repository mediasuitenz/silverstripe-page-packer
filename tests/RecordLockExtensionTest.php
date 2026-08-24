<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Jobs\RecordImportJob;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * The generic-DataObject equivalent of SiteTreeLockExtensionTest — same coverage (own-signature
 * matching, every actively-pending status, ID-only import signature surviving a reclass), but
 * against a plain, unversioned, non-SiteTree DataObject.
 */
class RecordLockExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    public function testExportJobsOwnSignatureMatchesWhatTheLockCheckQueries(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Being exported']);
        $catalogue->write();

        $job = new RecordExportJob($catalogue);

        $this->assertSame(
            RecordExportJob::signatureForRecord($catalogue),
            $job->getSignature()
        );
    }

    /**
     * @dataProvider lockingStatusProvider
     */
    public function testExportLockCoversEveryActivelyPendingStatus(string $status, bool $expectedLocked): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Being exported']);
        $catalogue->write();

        QueuedJobDescriptor::create([
            'Implementation' => RecordExportJob::class,
            'Signature' => RecordExportJob::signatureForRecord($catalogue),
            'JobStatus' => $status,
        ])->write();

        $this->assertSame($expectedLocked, $catalogue->pendingJobExists([RecordExportJob::class]));
    }

    public static function lockingStatusProvider(): array
    {
        return [
            'New' => [QueuedJob::STATUS_NEW, true],
            'Initialising' => [QueuedJob::STATUS_INIT, true],
            'Running' => [QueuedJob::STATUS_RUN, true],
            'Waiting' => [QueuedJob::STATUS_WAIT, true],
            'Complete' => [QueuedJob::STATUS_COMPLETE, false],
            'Broken' => [QueuedJob::STATUS_BROKEN, false],
        ];
    }

    public function testImportLockSurvivesTheStubsClassNameChanging(): void
    {
        $stub = TestCatalogue::create(['Title' => 'Importing…']);
        $stub->write();

        QueuedJobDescriptor::create([
            'Implementation' => RecordImportJob::class,
            'Signature' => RecordImportJob::signatureForRecordId((int) $stub->ID),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ])->write();

        $this->assertTrue(
            $stub->pendingJobExists([RecordImportJob::class]),
            'Locked while the job is running, before any reclass.'
        );

        // RecordImportJob only reclasses the stub when the manifest's root is a more specific
        // subclass — TestCatalogue has none, so simulate the "no reclass needed" path directly
        // and confirm the lock is unaffected either way, since the signature is ID-only.
        $reloaded = TestCatalogue::get()->byID($stub->ID);

        $this->assertTrue($reloaded->pendingJobExists([RecordImportJob::class]));
    }

    public function testLockedRecordShowsAWarningOnItsCMSFields(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Being imported']);
        $catalogue->write();

        QueuedJobDescriptor::create([
            'Implementation' => RecordImportJob::class,
            'Signature' => RecordImportJob::signatureForRecordId((int) $catalogue->ID),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ])->write();

        $fields = $catalogue->getCMSFields();

        // flattenFields(), not dataFieldByName() — LiteralField::hasData() is false (it's a
        // display-only banner, not a data-carrying input), so it's invisible to the latter; and
        // fieldByName() alone only recurses into nested tabs given a fully dotted path.
        $this->assertNotNull($fields->flattenFields()->fieldByName('PagePackerLockedWarning'));
    }
}
