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

    public function getSignature(): string
    {
        return self::signatureForRecordId((int) $this->pageID);
    }

    /**
     * Deliberately embeds ClassName — safe here because a read-only export never changes the
     * source page's class, unlike SiteTreeImportJob's stub-reclassing case.
     */
    public static function signatureForRecord(DataObject $record): string
    {
        return md5(sprintf('sitetree-export-%s-%s', $record->ID, $record->ClassName));
    }

    public static function signatureForRecordId(int $id): string
    {
        // Class-agnostic form of the above, used by SiteTreeLockExtension when it only has an
        // ID to hand (e.g. checking a freshly-created record before we know for certain which
        // formula produced the running job's stored signature).
        return md5(sprintf('sitetree-export-%s', $id));
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
