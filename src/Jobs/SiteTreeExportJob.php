<?php

namespace MadeCurious\SiteTreeImportExport\Jobs;

use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use MadeCurious\SiteTreeImportExport\Serialization\AssetBundler;
use MadeCurious\SiteTreeImportExport\Serialization\ContentTimestampWalker;
use MadeCurious\SiteTreeImportExport\Serialization\SiteTreeExporter;
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
 * Reads a single page's LIVE content and produces a downloadable export zip. See the module's
 * implementation plan for why the acting Member and the read stage are restored/set explicitly
 * rather than relied on ambiently: this job runs headlessly via ddev-cron's
 * `dev/tasks/ProcessJobQueueTask`, which is a PolyCommand, not a Controller, so neither
 * `Security::getCurrentUser()` nor `Versioned::$reading_mode` are populated the way they would be
 * for a real HTTP request.
 */
class SiteTreeExportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(?SiteTree $page = null, bool $includeAssets = true, ?int $exportRequestID = null)
    {
        if ($page) {
            $this->pageID = $page->ID;
            // Captured so getSignature() can embed it (see that method's doc comment) — needed
            // because it's called well after construction (by QueuedJobService::queueJob(), and
            // again on every resume from the persisted job data), with no live $page in scope by
            // then.
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
     * Must produce the exact value SiteTreeLockExtension::pendingJobExists() queries for (via
     * signatureForRecord()) — this is what QueuedJobService::queueJob() persists onto the real
     * QueuedJobDescriptor row, so any mismatch here means the lock silently never engages for a
     * genuinely running export (caught the hard way: canEdit()/canPublish() kept returning true
     * for a page mid-export, because this used to return signatureForRecordId()'s ID-only form
     * while the lock check queried the ID+ClassName form — the two formulas never matched).
     */
    public function getSignature(): string
    {
        return $this->pageClassName !== null
            ? self::signatureForIdAndClass((int) $this->pageID, $this->pageClassName)
            : self::signatureForRecordId((int) $this->pageID);
    }

    /**
     * Deliberately embeds ClassName — safe here because a read-only export never changes the
     * source page's class, unlike SiteTreeImportJob's stub-reclassing case.
     */
    public static function signatureForRecord(DataObject $record): string
    {
        return self::signatureForIdAndClass((int) $record->ID, $record->ClassName);
    }

    public static function signatureForRecordId(int $id): string
    {
        // Class-agnostic fallback, used only when no ClassName is available at all (defensive;
        // getSignature() always has one once constructed with a real $page).
        return md5(sprintf('sitetree-export-%s', $id));
    }

    private static function signatureForIdAndClass(int $id, string $className): string
    {
        return md5(sprintf('sitetree-export-%s-%s', $id, $className));
    }

    public function process(): void
    {
        if ($this->memberID) {
            $member = Member::get()->byID($this->memberID);

            if ($member) {
                Security::setCurrentUser($member);
            }
        }

        $exportRequest = $this->exportRequestID ? ExportRequest::get()->byID($this->exportRequestID) : null;

        try {
            // The whole read+walk+timestamp-capture happens inside ONE withVersionedMode call —
            // not just the initial page fetch — because withVersionedMode restores the prior
            // reading mode as soon as its callback returns. Wrapping only the fetch would leave
            // every subsequent relation read the exporter/walker performs (ElementalArea,
            // Elements, EditableFormField, ...) running under whatever the ambient stage
            // happened to be, silently contradicting "export reads live content only" for
            // anything beyond the root page itself.
            [$file, $sourceContentTimestamp] = Versioned::withVersionedMode(function () {
                Versioned::set_stage(Versioned::LIVE);

                $page = SiteTree::get()->byID($this->pageID);

                if (!$page || !$page->exists()) {
                    throw new RuntimeException(
                        'Page #' . $this->pageID . ' has no published version to export.'
                    );
                }

                $assetBundler = Injector::inst()->create(AssetBundler::class);
                $mismatchBehaviour = SiteTreeExporter::config()->get('mismatch_behaviour');
                $exporter = new SiteTreeExporter($assetBundler, (bool) $this->includeAssets, $mismatchBehaviour);
                $manifest = $exporter->export($page);
                $file = $assetBundler->writeZip($manifest, $page->URLSegment . '-export.zip');
                $sourceContentTimestamp = (new ContentTimestampWalker())->latestTimestamp($page);

                return [$file, $sourceContentTimestamp];
            });

            if ($exportRequest) {
                $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
                $exportRequest->ResultFileID = $file->ID;
                $exportRequest->SourceContentTimestamp = (string) $sourceContentTimestamp;
                $exportRequest->write();
            }

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
        }

        $this->isComplete = true;
    }
}
