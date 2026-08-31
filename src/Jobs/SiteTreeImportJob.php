<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\RecordPacker\Jobs\RecordImportJob;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;

class SiteTreeImportJob extends RecordImportJob
{
    public function __construct(?SiteTree $stub = null, ?File $uploadedFile = null)
    {
        parent::__construct($stub, $uploadedFile);
    }

    protected static function signaturePrefix(): string
    {
        return 'sitetree-import';
    }

    protected static function rootClassLabel(): string
    {
        return 'page type';
    }
}
