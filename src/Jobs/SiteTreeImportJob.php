<?php

namespace MadeCurious\PagePacker\Jobs;

use MadeCurious\RecordPacker\Jobs\RecordImportJob;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;

/**
 * The SiteTree-specific instance of {@see RecordImportJob} — the underlying import mechanics are
 * identical: a bare, un-typed SiteTree stub (created by
 * {@see \MadeCurious\PagePacker\Extensions\CMSMainAddFormImportExtension}) is reclassed to
 * whatever concrete SiteTree subclass the manifest's root node names, which is exactly what the
 * base class's "target must be the stub's class or a subclass of it" rule already produces when
 * the stub's own class is the bare `SiteTree` base. What differs here is presentation: the
 * error wording says "page type" rather than "record type", and the signature stays namespaced
 * under `sitetree-import-*`. The queued-job title is inherited as-is from the base — since the
 * stub here always starts as bare `SiteTree` (the concrete page type isn't known until the
 * manifest is read mid-job), it reads "Import SiteTree (#12)" rather than a specific page type;
 * that's an accurate reflection of what's actually queued, not a bug.
 */
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
