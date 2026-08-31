<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Jobs\SiteTreeImportJob;
use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Serialization\AssetBundler;
use MadeCurious\RecordPacker\Serialization\RecordSerializer;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers SiteTreeImportJob::doImport()'s own guard on the ROOT node's class specifically — a
 * separate, stricter check from RecordSerializer's general mismatch handling (see
 * MismatchHandlingTest for that). There's no reasonable "best effort" partial import when the
 * root class itself can't be resolved (there's no page left to apply anything else to), so this
 * is fatal unconditionally, even under MISMATCH_BEST_EFFORT — confirmed explicitly below rather
 * than assumed from the (fail-mode-only) doc comment.
 */
class SiteTreeImportJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testUnknownRootClassFailsRegardlessOfMismatchBehaviour(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'meta' => [
                'className' => 'SilverStripe\\UserForms\\Model\\UserDefinedForm\\FromANewerModuleVersion',
                'title' => 'A page',
                'urlSegment' => 'a-page',
            ],
            'nodes' => [
                '0' => [
                    'className' => 'SilverStripe\\UserForms\\Model\\UserDefinedForm\\FromANewerModuleVersion',
                    'fields' => ['Title' => 'A page'],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $uploadedFile = $assetBundler->writeZip($manifest, 'unknown-root-class.zip');

        $stub = SiteTree::create(['Title' => 'Importing…']);
        $stub->write();

        // Deliberately BEST_EFFORT, not FAIL — proving this is fatal either way, not merely the
        // default behaviour.
        RecordSerializer::config()->set('mismatch_behaviour', RecordSerializer::MISMATCH_BEST_EFFORT);
        $job = new SiteTreeImportJob($stub, $uploadedFile);

        $caught = null;

        try {
            $job->process();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'An unresolvable root class must always throw.');
        $this->assertStringContainsString('is not a page type that exists on this site', $caught->getMessage());

        // failStub() runs before process() re-throws (see its own doc comment: the stub is kept,
        // not deleted, so the editor sees a visibly broken page they can inspect/remove rather
        // than a silently vanished one).
        $reloadedStub = SiteTree::get()->byID($stub->ID);
        $this->assertStringContainsString('Import failed:', $reloadedStub->Title);

        $failedRequest = ExportRequest::get()->filter([
            'RecordID' => $stub->ID,
            'RecordClass' => SiteTree::class,
            'Origin' => ExportRequest::ORIGIN_IMPORT,
            'Status' => ExportRequest::STATUS_FAILED,
        ])->first();
        $this->assertNotNull($failedRequest, 'A Failed ExportRequest entry must be recorded for the stub.');
    }
}
