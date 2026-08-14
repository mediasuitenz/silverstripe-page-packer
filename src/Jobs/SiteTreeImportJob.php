<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\SiteTreeSerializer;
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
 * Populates a draft page from an uploaded export zip then runs the two-pass
 * deserializer.
 */
class SiteTreeImportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(
        ?SiteTree $stub = null,
        ?File $uploadedFile = null
    ) {
        if ($stub) {
            $this->stubID = $stub->ID;
            $this->uploadedFileID = $uploadedFile ? $uploadedFile->ID : null;

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
     * ID-only, deliberately never embeds ClassName as the stub's class changes mid-job
     */
    public static function signatureForRecordId(int $id): string
    {
        return md5(sprintf('sitetree-import-%s', $id));
    }

    public function process(): void
    {
        $currentMember - Security::getCurrentUser();
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
        } finally {
            Security::setCurrentUser($currentMember);
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

        $assetBundler = AssetBundler::create();
        $manifest = $assetBundler->readZip($uploadedFile);

        $rootLocalId = (string) ($manifest['rootLocalId'] ?? '0');
        $targetClass = $manifest['nodes'][$rootLocalId]['className'] ?? null;

        // A completely unresolvable root class has no reasonable "best effort" partial import
        if (!$targetClass || !is_a($targetClass, SiteTree::class, true)) {
            throw new RuntimeException(
                "\"{$targetClass}\" is not a page type that exists on this site; the file cannot be imported."
            );
        }

        /** @var SiteTree $record */
        $record = $stub->newClassInstance($targetClass);

        $serializer = SiteTreeSerializer::create($assetBundler, true);
        $serializer->import($record, $manifest);

        foreach ($serializer->warnings() as $warning) {
            $this->addMessage($warning, 'WARNING');
        }

        $exportRequest = ExportRequest::create();
        $exportRequest->PageID = $record->ID;
        $exportRequest->MemberID = $this->memberID;
        $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
        $exportRequest->Origin = ExportRequest::ORIGIN_IMPORT;
        $exportRequest->ResultFileID = $uploadedFile->ID;
        $exportRequest->IncludeAssets = $assetBundler->hasEmbeddedAssets($manifest);
        $exportRequest->write();

        $this->addMessage("Imported page #{$record->ID} successfully.");
    }

    /**
     * On failure, the stub is deliberately kept (not deleted) and re-titled to surface the error
     * directly in the CMS tree/Settings tab
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
