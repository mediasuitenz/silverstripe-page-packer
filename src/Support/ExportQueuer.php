<?php

namespace MadeCurious\PagePacker\Support;

use MadeCurious\PagePacker\Model\ExportRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Creates the ExportRequest history row and queues the export job for it — the one piece of
 * "start an export" logic every trigger point shares: the SiteTree/CMSMain modal
 * (CMSMainExportActionExtension), the generic-DataObject modal (RecordPackerController), and the
 * one-click GridField row action (GridFieldRecordExportAction). $jobClass lets each caller pass
 * its own job class (SiteTreeExportJob vs the plain RecordExportJob) while sharing everything
 * else.
 */
final class ExportQueuer
{
    public static function queue(
        DataObject $record,
        string $jobClass,
        bool $includeAssets = true,
        string $description = ''
    ): ExportRequest {
        $exportRequest = ExportRequest::create();
        $exportRequest->RecordID = $record->ID;
        $exportRequest->RecordClass = get_class($record);
        $exportRequest->MemberID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : null;
        $exportRequest->Status = ExportRequest::STATUS_QUEUED;
        $exportRequest->Origin = ExportRequest::ORIGIN_EXPORT;
        $exportRequest->Description = $description;
        $exportRequest->IncludeAssets = $includeAssets;
        $exportRequest->write();

        $job = new $jobClass($record, $includeAssets, $exportRequest->ID);
        QueuedJobService::singleton()->queueJob($job);

        return $exportRequest;
    }
}
