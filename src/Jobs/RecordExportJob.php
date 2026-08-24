<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\ContentTimestampWalker;
use MadeCurious\PagePacker\Serialization\RecordSerializer;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;

/**
 * Reads a single record's current content and produces a downloadable export zip. Works for any
 * DataObject — {@see SiteTreeExportJob} is a thin subclass used for SiteTree pages specifically
 * (see its own doc comment for the little that's actually different).
 *
 * Only engages Versioned's LIVE mode when the target record's class is actually versioned — an
 * ordinary, unversioned DataObject (e.g. a catalogue/config record edited via a plain GridField)
 * has no draft/live distinction to switch between at all, so its current content simply IS what
 * gets exported.
 */
class RecordExportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(?DataObject $record = null, bool $includeAssets = true, ?int $exportRequestID = null)
    {
        if ($record) {
            $this->recordID = $record->ID;
            // Captured so getSignature() can embed it
            $this->recordClass = get_class($record);
            $this->includeAssets = $includeAssets;
            $this->exportRequestID = $exportRequestID;

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
        return _t(self::class . '.TITLE', 'Export record (#{ID})', ['ID' => $this->recordID]);
    }

    /**
     * Must produce the exact value RecordLockExtension::pendingJobExists() queries for
     */
    public function getSignature(): string
    {
        return $this->recordClass !== null
            ? self::signatureForIdAndClass((int) $this->recordID, $this->recordClass)
            : self::signatureForRecordId((int) $this->recordID);
    }

    public static function signatureForRecord(DataObject $record): string
    {
        return static::signatureForIdAndClass((int) $record->ID, get_class($record));
    }

    public static function signatureForRecordId(int $id): string
    {
        // Class-agnostic fallback, used only when no ClassName is available at all
        return md5(sprintf('%s-%s', static::signaturePrefix(), $id));
    }

    private static function signatureForIdAndClass(int $id, string $className): string
    {
        return md5(sprintf('%s-%s-%s', static::signaturePrefix(), $id, $className));
    }

    /**
     * Overridden by SiteTreeExportJob so a page's signature stays namespaced separately from a
     * generic record's, even though both otherwise compute the same way.
     */
    protected static function signaturePrefix(): string
    {
        return 'record-export';
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

        $exportRequest = $this->exportRequestID ? ExportRequest::get()->byID($this->exportRequestID) : null;

        try {
            if (!$exportRequest) {
                throw new RuntimeException('No Export Request found for job.');
            }

            $class = $this->recordClass;

            if (!$class || !class_exists($class) || !is_a($class, DataObject::class, true)) {
                throw new RuntimeException("Record #{$this->recordID} has an unresolvable class \"{$class}\".");
            }

            $recordID = (int) $this->recordID;
            $includeAssets = (bool) $this->includeAssets;

            $read = function () use ($class, $recordID, $includeAssets) {
                $record = $class::get()->byID($recordID);

                if (!$record || !$record->exists()) {
                    throw new RuntimeException("Record #{$recordID} no longer exists.");
                }

                $assetBundler = Injector::inst()->create(AssetBundler::class);
                $serializer = RecordSerializer::create($assetBundler, $includeAssets);
                $manifest = $serializer->export($record);
                $file = $assetBundler->writeZip($manifest, $this->exportFilenameFor($record));
                $sourceContentTimestamp = ContentTimestampWalker::create()->latestTimestamp($record);

                return [$file, $sourceContentTimestamp];
            };

            // The whole read+walk+timestamp-capture happens inside one withVersionedMode call
            // because withVersionedMode restores the prior reading mode as soon as its callback
            // returns — but only when there's a stage to switch to in the first place.
            [$file, $sourceContentTimestamp] = DataObject::singleton($class)->hasExtension(Versioned::class)
                ? Versioned::withVersionedMode(function () use ($read) {
                    Versioned::set_stage(Versioned::LIVE);

                    return $read();
                })
                : $read();

            $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
            $exportRequest->ResultFileID = $file->ID;
            $exportRequest->SourceContentTimestamp = (string) $sourceContentTimestamp;
            $exportRequest->write();

            $this->addMessage("Exported record #{$this->recordID} successfully.");
        } catch (Throwable $e) {
            if ($exportRequest) {
                $exportRequest->Status = ExportRequest::STATUS_FAILED;
                $exportRequest->StatusMessage = $e->getMessage();
                $exportRequest->write();
            }

            $this->addMessage('Export failed: ' . $e->getMessage(), 'ERROR');
            $this->isComplete = true;

            throw $e;
        } finally {
            Security::setCurrentUser($currentMember);
        }

        $this->isComplete = true;
    }

    private function exportFilenameFor(DataObject $record): string
    {
        $slug = $record->hasField('URLSegment') ? (string) $record->URLSegment : '';

        if ($slug === '') {
            $title = $record->hasField('Title') ? (string) $record->Title : get_class($record);
            $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-');
        }

        return ($slug !== '' ? $slug : 'record') . '-export.zip';
    }
}
