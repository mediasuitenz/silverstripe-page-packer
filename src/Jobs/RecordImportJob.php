<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\RecordSerializer;
use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;

/**
 * Populates a stub record from an uploaded export zip then runs {@see RecordSerializer}'s
 * two-pass import against it. Works for any DataObject — {@see SiteTreeImportJob} is a thin
 * subclass used for SiteTree pages specifically (see its own doc comment for the little that's
 * actually different).
 *
 * The stub is expected to already be the exact class this import should produce, or a
 * superclass of it — e.g. the model class of whichever GridField the import was triggered from
 * (see GridFieldRecordImportButton), or (for the page tree) a bare, un-typed SiteTree, since the
 * editor there is choosing "import" INSTEAD OF picking a page type up front. This job only
 * reclasses the stub if the manifest's root node turns out to be a MORE SPECIFIC subclass of the
 * stub's current class (mirroring newClassInstance()), and fails outright if the manifest's root
 * class isn't the stub's class or a subclass of it — there's no reasonable way to import, say,
 * an exported Product into a Catalogue GridField.
 */
class RecordImportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(?DataObject $stub = null, ?File $uploadedFile = null)
    {
        if ($stub) {
            $this->stubID = $stub->ID;
            $this->stubClass = get_class($stub);
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
        return _t(self::class . '.TITLE', 'Import {class} (#{ID})', [
            'class' => $this->stubClass ? ClassInfo::shortName($this->stubClass) : 'record',
            'ID' => $this->stubID,
        ]);
    }

    public function getSignature(): string
    {
        return static::signatureForRecordId((int) $this->stubID);
    }

    /**
     * ID-only, deliberately never embeds ClassName as the stub's class changes mid-job
     */
    public static function signatureForRecordId(int $id): string
    {
        return md5(sprintf('%s-%s', static::signaturePrefix(), $id));
    }

    /**
     * Overridden by SiteTreeImportJob so a page's signature stays namespaced separately from a
     * generic record's, even though both otherwise compute the same way.
     */
    protected static function signaturePrefix(): string
    {
        return 'record-import';
    }

    /**
     * The word used for "class" in this job's own error messages — overridden by
     * SiteTreeImportJob to restore the original "page type" wording.
     */
    protected static function rootClassLabel(): string
    {
        return 'record type';
    }

    public function process(): void
    {
        $currentMember = Security::getCurrentUser();

        if ($this->memberID) {
            $member = Member::get()->byID($this->memberID);

            if ($member) {
                Security::setCurrentUser($member);
            }
        }

        try {
            $stubClass = $this->stubClass;
            $isVersioned = $stubClass && class_exists($stubClass)
                && DataObject::singleton($stubClass)->hasExtension(Versioned::class);

            if ($isVersioned) {
                Versioned::withVersionedMode(function () {
                    Versioned::set_stage(Versioned::DRAFT);
                    $this->doImport();
                });
            } else {
                $this->doImport();
            }
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
        $stubClass = $this->stubClass;

        if (!$stubClass || !class_exists($stubClass) || !is_a($stubClass, DataObject::class, true)) {
            throw new RuntimeException("Import stub #{$this->stubID} has an unresolvable class \"{$stubClass}\".");
        }

        $stub = $stubClass::get()->byID($this->stubID);

        if (!$stub || !$stub->exists()) {
            throw new RuntimeException("Import stub record #{$this->stubID} no longer exists.");
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
        if (!$targetClass || !class_exists($targetClass) || !is_a($targetClass, DataObject::class, true)) {
            throw new RuntimeException(
                "\"{$targetClass}\" is not a " . static::rootClassLabel()
                . ' that exists on this site; the file cannot be imported.'
            );
        }

        // Nor does a root class that belongs to a completely different part of the object graph
        // than the GridField (or other packable class) this import was triggered against.
        if ($targetClass !== $stubClass && !is_a($targetClass, $stubClass, true)) {
            throw new RuntimeException(
                "This file contains a \"{$targetClass}\" record, which cannot be imported here (expected"
                . " \"{$stubClass}\" or a subclass of it)."
            );
        }

        $record = $targetClass === $stubClass ? $stub : $stub->newClassInstance($targetClass);

        $serializer = RecordSerializer::create($assetBundler, true);
        $serializer->import($record, $manifest);

        foreach ($serializer->warnings() as $warning) {
            $this->addMessage($warning, 'WARNING');
        }

        $exportRequest = ExportRequest::create();
        $exportRequest->RecordID = $record->ID;
        $exportRequest->RecordClass = get_class($record);
        $exportRequest->MemberID = $this->memberID;
        $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
        $exportRequest->Origin = ExportRequest::ORIGIN_IMPORT;
        $exportRequest->ResultFileID = $uploadedFile->ID;
        $exportRequest->IncludeAssets = $assetBundler->hasEmbeddedAssets($manifest);
        $exportRequest->QueuedJobDescriptorID = $this->currentJobDescriptorID();
        $exportRequest->write();

        $this->addMessage("Imported record #{$record->ID} successfully.");
    }

    /**
     * On failure, the stub is deliberately kept (not deleted) and, if it has a Title field,
     * re-titled to surface the error directly when an editor opens it.
     */
    private function failStub(Throwable $e): void
    {
        $stubClass = $this->stubClass;

        if (!$stubClass || !class_exists($stubClass)) {
            return;
        }

        $stub = $stubClass::get()->byID($this->stubID);

        if (!$stub || !$stub->exists()) {
            return;
        }

        if ($stub->hasField('Title')) {
            $stub->Title = 'Import failed: ' . $e->getMessage();
            $stub->write();
        }

        $exportRequest = ExportRequest::create();
        $exportRequest->RecordID = $stub->ID;
        $exportRequest->RecordClass = get_class($stub);
        $exportRequest->MemberID = $this->memberID;
        $exportRequest->Status = ExportRequest::STATUS_FAILED;
        $exportRequest->Origin = ExportRequest::ORIGIN_IMPORT;
        $exportRequest->StatusMessage = $e->getMessage();
        $exportRequest->ResultFileID = $this->uploadedFileID;
        $exportRequest->QueuedJobDescriptorID = $this->currentJobDescriptorID();
        $exportRequest->write();
    }

    /**
     * This job doesn't otherwise know its own QueuedJobDescriptor ID while running — nothing in
     * symbiote's AbstractQueuedJob exposes it — so it's looked up by signature instead. Safe here
     * specifically because this job's signature is ID-only (see signaturePrefix()/getSignature())
     * and QueuedJobService::queueJob() itself refuses to double-queue a matching signature, so
     * exactly one descriptor exists for this stub at any moment this job could be running.
     */
    private function currentJobDescriptorID(): ?int
    {
        $descriptor = QueuedJobDescriptor::get()->filter('Signature', $this->getSignature())->first();

        return $descriptor ? (int) $descriptor->ID : null;
    }
}
