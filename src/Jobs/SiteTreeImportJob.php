<?php

namespace MadeCurious\SiteTreeImportExport\Jobs;

use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use MadeCurious\SiteTreeImportExport\Serialization\AssetBundler;
use MadeCurious\SiteTreeImportExport\Serialization\SiteTreeExporter;
use MadeCurious\SiteTreeImportExport\Serialization\SiteTreeImporter;
use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;

/**
 * Populates a pre-written stub page from an uploaded export zip: reclasses the stub to the
 * manifest's target class via `newClassInstance()` (preserving its ID/DB row — see the module's
 * implementation plan for why the stub must be plain `Page`, to avoid orphaning multi-table-
 * inheritance rows from whatever the original class introduced), then runs the two-pass
 * deserializer. Always leaves the result as a draft — see the explicit Versioned stage wrapping
 * below, never relied on ambiently, for the same reason SiteTreeExportJob wraps its read.
 */
class SiteTreeImportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(
        ?SiteTree $stub = null,
        ?File $uploadedFile = null,
        string $mismatchBehaviour = SiteTreeExporter::MISMATCH_FAIL
    ) {
        if ($stub) {
            $this->stubID = $stub->ID;
            $this->uploadedFileID = $uploadedFile ? $uploadedFile->ID : null;
            $this->mismatchBehaviour = $mismatchBehaviour;

            $member = Security::getCurrentUser();

            if ($member) {
                $this->memberID = $member->ID;
            }
        }
    }

    public function getJobType(): string
    {
        $this->totalSteps = 1;

        return QueuedJob::QUEUED;
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Import page (#{ID})', ['ID' => $this->stubID]);
    }

    public function getSignature(): string
    {
        return self::signatureForRecordId((int) $this->stubID);
    }

    /**
     * ID-only, deliberately never embeds ClassName — the stub's class changes mid-job via
     * newClassInstance(), and a signature that changed with it would stop matching this job's
     * QueuedJobDescriptor row at exactly the point the lock matters most. See
     * SiteTreeLockExtension's class doc for the full rationale.
     */
    public static function signatureForRecordId(int $id): string
    {
        return md5(sprintf('sitetree-import-%s', $id));
    }

    public function process(): void
    {
        if ($this->memberID) {
            $member = Member::get()->byID($this->memberID);

            if ($member) {
                Security::setCurrentUser($member);
            }
        }

        try {
            Versioned::withVersionedMode(function () {
                Versioned::set_stage(Versioned::DRAFT);
                $this->doImport();
            });
        } catch (Throwable $e) {
            $this->failStub($e);
            $this->addMessage('Import failed: ' . $e->getMessage(), 'ERROR');
            $this->isComplete = true;

            throw $e;
        }

        $this->isComplete = true;
    }

    private function doImport(): void
    {
        $stub = SiteTree::get()->byID($this->stubID);

        if (!$stub || !$stub->exists()) {
            throw new RuntimeException("Import stub page #{$this->stubID} no longer exists.");
        }

        $uploadedFile = $this->uploadedFileID ? File::get()->byID($this->uploadedFileID) : null;

        if (!$uploadedFile) {
            throw new RuntimeException('The uploaded import file could not be found.');
        }

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $manifest = $assetBundler->readZip($uploadedFile);

        $rootLocalId = (string) ($manifest['rootLocalId'] ?? '0');
        $targetClass = $manifest['nodes'][$rootLocalId]['className'] ?? null;

        // A completely unresolvable root class has no reasonable "best effort" partial import —
        // there's no page to create content onto — so this is fatal regardless of
        // mismatch_behaviour, unlike field/nested-relation mismatches further down.
        if (!$targetClass || !is_a($targetClass, SiteTree::class, true)) {
            throw new RuntimeException(
                "\"{$targetClass}\" is not a page type that exists on this site; the file cannot be imported."
            );
        }

        /** @var SiteTree $record */
        $record = $stub->newClassInstance($targetClass);

        $importer = new SiteTreeImporter($assetBundler, $this->mismatchBehaviour);
        $importer->import($record, $manifest);

        foreach ($importer->warnings() as $warning) {
            $this->addMessage($warning, 'WARNING');
        }

        $exportRequest = ExportRequest::create();
        $exportRequest->PageID = $record->ID;
        $exportRequest->MemberID = $this->memberID;
        $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
        $exportRequest->Origin = ExportRequest::ORIGIN_IMPORT;
        $exportRequest->ResultFileID = $uploadedFile->ID;
        // SourceLiveVersion deliberately left at its default (0) — see ExportRequest::isStale()'s
        // doc comment: this page has no live version yet, and 0 is the field's NOT NULL sentinel
        // for that, not null (SilverStripe's Int db field can't be nullable here).
        $exportRequest->write();

        $this->addMessage("Imported page #{$record->ID} successfully.");
    }

    /**
     * On failure, the stub is deliberately kept (not deleted) and re-titled to surface the error
     * directly in the CMS tree/Settings tab — a silently-vanished page the editor just watched
     * appear would be a worse "fail with a clear error" experience than a visibly broken one
     * they can inspect and remove themselves.
     */
    private function failStub(Throwable $e): void
    {
        $stub = SiteTree::get()->byID($this->stubID);

        if (!$stub || !$stub->exists()) {
            return;
        }

        $stub->Title = 'Import failed: ' . $e->getMessage();
        $stub->write();

        $exportRequest = ExportRequest::create();
        $exportRequest->PageID = $stub->ID;
        $exportRequest->MemberID = $this->memberID;
        $exportRequest->Status = ExportRequest::STATUS_FAILED;
        $exportRequest->Origin = ExportRequest::ORIGIN_IMPORT;
        $exportRequest->StatusMessage = $e->getMessage();
        $exportRequest->ResultFileID = $this->uploadedFileID;
        $exportRequest->write();
    }
}
