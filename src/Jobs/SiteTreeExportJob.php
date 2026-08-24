<?php

namespace MadeCurious\PagePacker\Jobs;

use SilverStripe\CMS\Model\SiteTree;

/**
 * The SiteTree-specific instance of {@see RecordExportJob} — everything about actually reading
 * and packaging content is identical (the base class is already Versioned-aware, and every
 * SiteTree is versioned, so it engages LIVE-stage reading exactly as the original
 * SiteTree-only implementation did). All that differs here is presentation: the queued-job title
 * says "page" rather than "record", and the job's own signature stays namespaced under
 * `sitetree-export-*` rather than the base's `record-export-*` — kept distinct mainly so a
 * page's queued job is unambiguous to identify in the Queued Jobs admin.
 */
class SiteTreeExportJob extends RecordExportJob
{
    public function __construct(?SiteTree $page = null, bool $includeAssets = true, ?int $exportRequestID = null)
    {
        parent::__construct($page, $includeAssets, $exportRequestID);
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Export page (#{ID})', ['ID' => $this->recordID]);
    }

    protected static function signaturePrefix(): string
    {
        return 'sitetree-export';
    }
}
