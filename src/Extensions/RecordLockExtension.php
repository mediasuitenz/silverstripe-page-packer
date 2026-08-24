<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Jobs\RecordImportJob;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Locks a DataObject (one with {@see PackableExtension} applied, or a SiteTree page — see
 * {@see SiteTreeLockExtension}) while an export or import job for it is in flight.
 *
 * The job classes to check against, and the locked-record warning's wording, are both
 * overridable ({@see exportJobClass()}/{@see importJobClass()}/{@see lockedWarningMessage()}) —
 * SiteTreeLockExtension overrides all three to point at SiteTreeExportJob/SiteTreeImportJob and
 * restore the original page-specific wording, since a page's queued job is literally an instance
 * of that subclass, not this class's own default pair.
 */
class RecordLockExtension extends Extension
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

        $warning = LiteralField::create(
            'PagePackerLockedWarning',
            '<div class="alert alert-warning">' . nl2br($this->lockedWarningMessage() ?? '') . '</div>'
        );

        // A plain DataObject's scaffolded fields aren't guaranteed to be a TabSet the way
        // SiteTree's always are, so fall back to a flat unshift().
        if ($fields->hasTabSet()) {
            $fields->addFieldToTab('Root.Main', $warning);
        } else {
            $fields->unshift($warning);
        }
    }

    /**
     * @param string[] $jobClasses Defaults to both job classes; callers that only care about one
     *     (e.g. a GridField's own export button only needs to dedupe against export jobs, not
     *     import jobs) can narrow this.
     */
    public function pendingJobExists(?array $jobClasses = null): bool
    {
        $jobClasses ??= [$this->exportJobClass(), $this->importJobClass()];

        if (!$this->owner->exists()) {
            return false;
        }

        $exportJobClass = $this->exportJobClass();
        $importJobClass = $this->importJobClass();

        if (in_array($exportJobClass, $jobClasses, true) && $this->pendingJobMatches(
            [$exportJobClass],
            $exportJobClass::signatureForRecord($this->owner)
        )) {
            return true;
        }

        if (in_array($importJobClass, $jobClasses, true) && $this->pendingJobMatches(
            [$importJobClass],
            $importJobClass::signatureForRecordId((int) $this->owner->ID)
        )) {
            return true;
        }

        return false;
    }

    /**
     * The job class whose signatureForRecord() this checks by default — overridden by
     * SiteTreeLockExtension to SiteTreeExportJob, since that (not this class's own
     * RecordExportJob) is what actually gets queued for a page.
     */
    public function exportJobClass(): string
    {
        return RecordExportJob::class;
    }

    public function importJobClass(): string
    {
        return RecordImportJob::class;
    }

    protected function lockedWarningMessage(): string
    {
        return (string) _t(
            self::class . '.LOCKED_WARNING',
            'This record is currently being exported/imported by PagePacker.'
            . ' Please try again in a minute or so.'
        );
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
