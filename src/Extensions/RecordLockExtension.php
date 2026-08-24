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
 * The generic, any-DataObject equivalent of {@see SiteTreeLockExtension} — locks a project
 * DataObject (one with {@see PackableExtension} applied) while a {@see RecordExportJob} or
 * {@see RecordImportJob} for it is in flight.
 *
 * A deliberately independent, near-identical class rather than a shared base with
 * SiteTreeLockExtension, so the SiteTree/CMSMain lock behaviour this was generalised from stays
 * completely untouched.
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

        $message = _t(
            self::class . '.LOCKED_WARNING',
            'This record is currently being exported/imported by PagePacker.'
            . ' Please try again in a minute or so.'
        );
        $warning = LiteralField::create(
            'PagePackerLockedWarning',
            '<div class="alert alert-warning">' . nl2br($message ?? '') . '</div>'
        );

        // A plain DataObject's scaffolded fields aren't guaranteed to be a TabSet the way
        // SiteTree's always are (see SiteTreeLockExtension), so fall back to a flat unshift().
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
    public function pendingJobExists(array $jobClasses = [RecordExportJob::class, RecordImportJob::class]): bool
    {
        if (!$this->owner->exists()) {
            return false;
        }

        if (in_array(RecordExportJob::class, $jobClasses, true) && $this->pendingJobMatches(
            [RecordExportJob::class],
            RecordExportJob::signatureForRecord($this->owner)
        )) {
            return true;
        }

        if (in_array(RecordImportJob::class, $jobClasses, true) && $this->pendingJobMatches(
            [RecordImportJob::class],
            RecordImportJob::signatureForRecordId((int) $this->owner->ID)
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
