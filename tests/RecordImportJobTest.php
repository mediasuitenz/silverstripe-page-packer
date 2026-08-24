<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Jobs\RecordImportJob;
use MadeCurious\PagePacker\Model\RecordExportRequest;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The generic-DataObject equivalent of SiteTreeImportJobTest, plus the one check that has no
 * SiteTree analogue at all: RecordImportJob also rejects a manifest whose root class is a real,
 * installed, packable class that just isn't the stub's class or a subclass of it — e.g. a file
 * exported from one packable GridField being dropped onto a completely different one. The page
 * tree has no equivalent of this because every SiteTree subclass is fair game there; an
 * arbitrary DataObject's GridField is deliberately narrower.
 */
class RecordImportJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function manifestFor(string $className): array
    {
        return [
            'format' => 1,
            'rootLocalId' => '0',
            'meta' => ['className' => $className, 'title' => 'A record', 'urlSegment' => null],
            'nodes' => [
                '0' => [
                    'className' => $className,
                    'fields' => ['Title' => 'A record'],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];
    }

    public function testUnknownRootClassFails(): void
    {
        $manifest = $this->manifestFor('MadeCurious\\PagePacker\\Tests\\Fixtures\\NotARealClass');
        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $uploadedFile = $assetBundler->writeZip($manifest, 'unknown-root-class.zip');

        $stub = TestCatalogue::create(['Title' => 'Importing…']);
        $stub->write();

        $job = new RecordImportJob($stub, $uploadedFile);

        $caught = null;

        try {
            $job->process();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertStringContainsString('is not a record type that exists on this site', $caught->getMessage());

        $failedRequest = RecordExportRequest::get()->filter([
            'RecordID' => $stub->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => RecordExportRequest::ORIGIN_IMPORT,
            'Status' => RecordExportRequest::STATUS_FAILED,
        ])->first();
        $this->assertNotNull($failedRequest, 'A Failed RecordExportRequest entry must be recorded for the stub.');
    }

    public function testRootClassBelongingToADifferentGridFieldFails(): void
    {
        // TestProduct is a perfectly real, installed class — just not TestCatalogue or a
        // subclass of it, so it must still be rejected as a mismatch for a TestCatalogue stub.
        $manifest = $this->manifestFor(TestProduct::class);
        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $uploadedFile = $assetBundler->writeZip($manifest, 'wrong-class.zip');

        $stub = TestCatalogue::create(['Title' => 'Importing…']);
        $stub->write();

        $job = new RecordImportJob($stub, $uploadedFile);

        $caught = null;

        try {
            $job->process();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertStringContainsString('cannot be imported here', $caught->getMessage());
    }

    public function testImportedRecordKeepsTheStubsOwnClassWhenNoReclassIsNeeded(): void
    {
        $manifest = $this->manifestFor(TestCatalogue::class);
        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $uploadedFile = $assetBundler->writeZip($manifest, 'matching-class.zip');

        $stub = TestCatalogue::create();
        $stub->write();
        $stubID = $stub->ID;

        $job = new RecordImportJob($stub, $uploadedFile);
        $job->process();

        $imported = TestCatalogue::get()->byID($stubID);
        $this->assertSame('A record', $imported->Title);
        $this->assertSame(TestCatalogue::class, $imported->ClassName);
    }
}
