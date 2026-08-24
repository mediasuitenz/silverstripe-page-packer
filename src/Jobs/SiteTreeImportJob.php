<?php

namespace MadeCurious\PagePacker\Jobs;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;

/**
 * The SiteTree-specific instance of {@see RecordImportJob} — the underlying import mechanics are
 * identical: a bare, un-typed SiteTree stub (created by
 * {@see \MadeCurious\PagePacker\Extensions\CMSMainAddFormImportExtension}) is reclassed to
 * whatever concrete SiteTree subclass the manifest's root node names, which is exactly what the
 * base class's "target must be the stub's class or a subclass of it" rule already produces when
 * the stub's own class is the bare `SiteTree` base. What differs here is presentation: the
 * queued-job title and error wording say "page" rather than "record", and the signature stays
 * namespaced under `sitetree-import-*`.
 */
class SiteTreeImportJob extends RecordImportJob
{
    public function __construct(?SiteTree $stub = null, ?File $uploadedFile = null)
    {
        parent::__construct($stub, $uploadedFile);
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Import page (#{ID})', ['ID' => $this->stubID]);
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
