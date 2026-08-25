<?php

namespace MadeCurious\PagePacker\Jobs;

use SilverStripe\CMS\Model\SiteTree;

/**
 * The SiteTree-specific instance of {@see RecordExportJob} — everything about actually reading
 * and packaging content is identical (the base class is already Versioned-aware, and every
 * SiteTree is versioned, so it engages LIVE-stage reading exactly as the original
 * SiteTree-only implementation did), including the queued-job title, which already surfaces the
 * record's actual class (e.g. "Export HomePage (#12)"). All that differs here is that the job's
 * own signature stays namespaced under `sitetree-export-*` rather than the base's
 * `record-export-*` — kept distinct mainly so a page's queued job is unambiguous to identify in
 * the Queued Jobs admin.
 */
class SiteTreeExportJob extends RecordExportJob
{
    public function __construct(?SiteTree $page = null, bool $includeAssets = true, ?int $exportRequestID = null)
    {
        parent::__construct($page, $includeAssets, $exportRequestID);
    }

    protected static function signaturePrefix(): string
    {
        return 'sitetree-export';
    }
}
