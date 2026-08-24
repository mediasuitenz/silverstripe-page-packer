<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\SiteTreeExportJob;
use MadeCurious\PagePacker\Jobs\SiteTreeImportJob;

/**
 * The SiteTree-specific instance of {@see RecordLockExtension} — same canEdit()/canPublish()
 * veto and locked-fields warning, just pointed at SiteTreeExportJob/SiteTreeImportJob (the job
 * classes actually queued for a page — see CMSMainExportActionExtension/
 * CMSMainAddFormImportExtension) instead of the base's own RecordExportJob/RecordImportJob, and
 * with the original page-specific wording restored.
 */
class SiteTreeLockExtension extends RecordLockExtension
{
    public function exportJobClass(): string
    {
        return SiteTreeExportJob::class;
    }

    public function importJobClass(): string
    {
        return SiteTreeImportJob::class;
    }

    protected function lockedWarningMessage(): string
    {
        return (string) _t(
            self::class . '.LOCKED_WARNING',
            'This page is currently being exported/imported by PagePacker.'
            . ' Please try again in a minute or so.'
        );
    }
}
