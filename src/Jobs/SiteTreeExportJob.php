<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\RecordPacker\Jobs\RecordExportJob;
use SilverStripe\CMS\Model\SiteTree;

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
