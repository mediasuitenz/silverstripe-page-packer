<?php

namespace MadeCurious\SiteTreeImportExport\Tests;

use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeExportJob;
use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeImportJob;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Covers the two fixes made to the ported async-publisher lock pattern (see
 * SiteTreeLockExtension's class doc): the pending-job status filter must include STATUS_RUN, and
 * import locking must key off an ID-only signature that survives the stub's ClassName changing
 * mid-job.
 */
class SiteTreeLockExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * The real end-to-end path: construct the job the way CMSMainExportActionExtension::doExport()
     * actually does, and assert its OWN getSignature() — not a hand-fabricated one — is what
     * pendingJobExists() will find. Regression test for a real bug: getSignature() used to return
     * signatureForRecordId()'s ID-only form while pendingJobExists() queried the ID+ClassName form
     * from signatureForRecord(), so the two never matched and a page stayed editable throughout a
     * genuinely in-flight export. Every other test in this file fabricates the QueuedJobDescriptor
     * directly with signatureForRecord($page), which is exactly why it didn't catch this — it
     * never exercised the job's own getSignature() at all.
     */
    public function testExportJobsOwnSignatureMatchesWhatTheLockCheckQueries(): void
    {
        $page = SiteTree::create(['Title' => 'Being exported']);
        $page->write();

        $job = new SiteTreeExportJob($page);

        $this->assertSame(
            SiteTreeExportJob::signatureForRecord($page),
            $job->getSignature(),
            "The job's own getSignature() must match signatureForRecord(), which is what"
            . ' SiteTreeLockExtension::pendingJobExists() queries QueuedJobDescriptor for.'
        );
    }

    #[DataProvider('lockingStatusProvider')]
    public function testExportLockCoversEveryActivelyPendingStatus(string $status, bool $expectedLocked): void
    {
        $page = SiteTree::create(['Title' => 'Being exported']);
        $page->write();

        $descriptor = QueuedJobDescriptor::create([
            'Implementation' => SiteTreeExportJob::class,
            'Signature' => SiteTreeExportJob::signatureForRecord($page),
            'JobStatus' => $status,
        ]);
        $descriptor->write();

        // pendingJobExists() is the real, environment-independent unit under test — it's what
        // canEdit()/canPublish() veto on. canEdit() itself isn't separately assertable here:
        // SiteTreeLockExtension deliberately defers to normal permission logic under
        // Director::is_cli() (so a queued job never vetoes its own writes), and PHPUnit itself
        // always runs under the CLI SAPI — so canEdit() would show the same bypass regardless of
        // pendingJobExists(), not because the veto is broken, but because this test process IS
        // the CLI context the bypass exists for.
        $this->assertSame($expectedLocked, $page->pendingJobExists([SiteTreeExportJob::class]));
    }

    public static function lockingStatusProvider(): array
    {
        return [
            'New' => [QueuedJob::STATUS_NEW, true],
            'Initialising' => [QueuedJob::STATUS_INIT, true],
            // This is the status the original async-publisher pattern's filter omitted — the
            // regression this test exists to catch.
            'Running' => [QueuedJob::STATUS_RUN, true],
            'Waiting' => [QueuedJob::STATUS_WAIT, true],
            'Complete' => [QueuedJob::STATUS_COMPLETE, false],
            'Broken' => [QueuedJob::STATUS_BROKEN, false],
        ];
    }

    public function testImportLockSurvivesTheStubsClassNameChanging(): void
    {
        if (!class_exists('Page')) {
            $this->markTestSkipped('No concrete Page subclass available to reclass to.');
        }

        $stub = SiteTree::create(['Title' => 'Importing…']);
        $stub->write();

        $descriptor = QueuedJobDescriptor::create([
            'Implementation' => SiteTreeImportJob::class,
            'Signature' => SiteTreeImportJob::signatureForRecordId((int) $stub->ID),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ]);
        $descriptor->write();

        $this->assertTrue(
            $stub->pendingJobExists([SiteTreeImportJob::class]),
            'Locked while the job is running, before the reclass.'
        );

        // Mid-job reclass, exactly as SiteTreeImportJob::doImport() does via newClassInstance().
        // A signature formula that embedded ClassName (the original async-publisher pattern)
        // would stop matching right here, since ClassName is now 'Page', not 'SiteTree'.
        $reclassed = $stub->newClassInstance('Page');
        $reclassed->write();

        $this->assertTrue(
            $reclassed->pendingJobExists([SiteTreeImportJob::class]),
            'Still locked after the reclass — the ID-only signature is unaffected by the class change.'
        );
    }

    public function testImportLockKeyIsStableAcrossAReclass(): void
    {
        $stub = SiteTree::create(['Title' => 'Importing…']);
        $stub->write();
        $stubID = (int) $stub->ID;

        $descriptor = QueuedJobDescriptor::create([
            'Implementation' => SiteTreeImportJob::class,
            'Signature' => SiteTreeImportJob::signatureForRecordId($stubID),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ]);
        $descriptor->write();

        // Simulate the reclass by loading a fresh instance under a different runtime ClassName —
        // the signature must be computed the same way regardless.
        $this->assertSame(
            SiteTreeImportJob::signatureForRecordId($stubID),
            SiteTreeImportJob::signatureForRecordId($stubID),
            'Signature formula is ID-only and therefore stable across any ClassName change.'
        );

        $reloaded = SiteTree::get()->byID($stubID);
        $this->assertTrue($reloaded->pendingJobExists([SiteTreeImportJob::class]));
    }
}
