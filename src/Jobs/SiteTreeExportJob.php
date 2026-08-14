<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\ContentTimestampWalker;
use MadeCurious\PagePacker\Serialization\SiteTreeSerializer;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use RuntimeException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;

/**
 * Reads a single page's LIVE content and produces a downloadable export zip
 */
class SiteTreeExportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(?SiteTree $page = null, bool $includeAssets = true, ?int $exportRequestID = null)
    {
        if ($page) {
            $this->pageID = $page->ID;
            // Captured so getSignature() can embed it
            $this->pageClassName = get_class($page);
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
        return _t(self::class . '.TITLE', 'Export page (#{ID})', ['ID' => $this->pageID]);
    }

    /**
     * Must produce the exact value SiteTreeLockExtension::pendingJobExists() queries for
     */
    public function getSignature(): string
    {
        return $this->pageClassName !== null
            ? self::signatureForIdAndClass((int) $this->pageID, $this->pageClassName)
            : self::signatureForRecordId((int) $this->pageID);
    }

    public static function signatureForRecord(DataObject $record): string
    {
        return self::signatureForIdAndClass((int) $record->ID, $record->ClassName);
    }

    public static function signatureForRecordId(int $id): string
    {
        // Class-agnostic fallback, used only when no ClassName is available at all
        return md5(sprintf('sitetree-export-%s', $id));
    }

    private static function signatureForIdAndClass(int $id, string $className): string
    {
        return md5(sprintf('sitetree-export-%s-%s', $id, $className));
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
                throw new RuntimeException(
                    'No Export Request found for job.'
                );
            }
            // The whole read+walk+timestamp-capture happens inside one withVersionedMode call
            // because withVersionedMode restores the prior reading mode as soon as its 
            // callback returns.
            [$file, $sourceContentTimestamp] = Versioned::withVersionedMode(function () {
                Versioned::set_stage(Versioned::LIVE);

                $page = SiteTree::get()->byID($this->pageID);

                if (!$page || !$page->exists()) {
                    throw new RuntimeException(
                        'Page #' . $this->pageID . ' has no published version to export.'
                    );
                }

                $assetBundler = Injector::inst()->create(AssetBundler::class);
                $mismatchBehaviour = SiteTreeSerializer::config()->get('mismatch_behaviour');
                $serializer = SiteTreeSerializer::create($assetBundler, (bool) $this->includeAssets, $mismatchBehaviour);
                $manifest = $serializer->export($page);
                $file = $assetBundler->writeZip($manifest, $page->URLSegment . '-export.zip');
                $sourceContentTimestamp = ContentTimestampWalker::create()->latestTimestamp($page);

                return [$file, $sourceContentTimestamp];
            });

            $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
            $exportRequest->ResultFileID = $file->ID;
            $exportRequest->SourceContentTimestamp = (string) $sourceContentTimestamp;
            $exportRequest->write();

            $this->addMessage("Exported page #{$this->pageID} successfully.");
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
}
