<?php

namespace MadeCurious\SiteTreeImportExport\Extensions;

use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeExportJob;
use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeImportJob;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Locks a SiteTree record while an export or import job for it is in flight, ported from
 * andrewandante/silverstripe-async-publisher's signature + canEdit/canPublish veto pattern —
 * with two fixes made for this module's use case (see the module's implementation plan for the
 * full research trail):
 *
 * 1. The pending-job status filter includes STATUS_RUN. The original pattern's list
 *    (NEW/INIT/WAIT) omits it, but QueuedJobService::runJob() sets exactly that status for the
 *    entire duration a job is actively executing — the highest-risk window to leave unlocked.
 *
 * 2. Export locking reuses the original ID+ClassName signature formula (safe: a read-only
 *    export never changes the source page's class). Import locking uses an ID-only signature
 *    (see SiteTreeImportJob::getSignature()) because the stub record's ClassName changes
 *    mid-job via newClassInstance() — a signature that embeds ClassName would stop matching the
 *    running job's QueuedJobDescriptor row at exactly the point the lock matters most.
 */
class SiteTreeLockExtension extends Extension
{
    public function canEdit($member = null)
    {
        if (!Director::is_cli() && $this->pendingJobExists()) {
            return false;
        }

        return null;
    }

    public function canPublish($member = null)
    {
        if (!Director::is_cli() && $this->pendingJobExists()) {
            return false;
        }

        return null;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        if (!$this->pendingJobExists()) {
            return;
        }

        $message = _t(
            self::class . '.LOCKED_WARNING',
            'This page is currently being exported/imported by MadeCurious SiteTree Import/Export.'
            . ' Please try again in a minute or so.'
        );
        $fields->addFieldToTab(
            'Root.Main',
            LiteralField::create(
                'SiteTreeImportExportLockedWarning',
                '<div class="alert alert-warning">' . nl2br($message ?? '') . '</div>'
            ),
            'Title'
        );
    }

    public function updateSettingsFields(FieldList $fields): void
    {
        $this->updateCMSFields($fields);
    }

    /**
     * @param string[] $jobClasses Defaults to both job classes; callers that only care about one
     *     (e.g. the Settings tab export button only needs to dedupe against export jobs, not
     *     import jobs, since a page being exported is never also mid-import) can narrow this.
     */
    public function pendingJobExists(array $jobClasses = [SiteTreeExportJob::class, SiteTreeImportJob::class]): bool
    {
        if (!$this->owner->exists()) {
            return false;
        }

        if (in_array(SiteTreeExportJob::class, $jobClasses, true) && $this->pendingJobMatches(
            [SiteTreeExportJob::class],
            SiteTreeExportJob::signatureForRecord($this->owner)
        )) {
            return true;
        }

        if (in_array(SiteTreeImportJob::class, $jobClasses, true) && $this->pendingJobMatches(
            [SiteTreeImportJob::class],
            SiteTreeImportJob::signatureForRecordId((int) $this->owner->ID)
        )) {
            return true;
        }

        return false;
    }

    private function pendingJobMatches(array $jobClasses, string $signature): bool
    {
        return QueuedJobDescriptor::get()->filter([
            'Implementation' => $jobClasses,
            'Signature' => $signature,
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
                QueuedJob::STATUS_RUN,
                QueuedJob::STATUS_WAIT,
            ],
        ])->exists();
    }
}
