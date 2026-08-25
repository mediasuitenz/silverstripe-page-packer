<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Support\PackingPolicy;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Locks a DataObject (one with {@see PackableExtension} applied, or a SiteTree page) while an
 * export or import job for it is in flight.
 *
 * Which job classes to check, which permission gates it, and the locked-record warning's
 * wording all come from an injected {@see PackingPolicy} — `SiteTree` applies this exact same
 * extension class as any other packable DataObject, just wired to the `.sitetree` policy
 * variant in this module's `_config/extensions.yml` rather than via a SiteTree-specific
 * subclass. See {@see PackingPolicy}'s own doc comment for why.
 */
class RecordLockExtension extends Extension
{
    private PackingPolicy $policy;

    public function __construct(?PackingPolicy $policy = null)
    {
        parent::__construct();

        $this->policy = $policy ?? Injector::inst()->get(PackingPolicy::class);
    }

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
            '<div class="alert alert-warning">' . nl2br($this->policy->lockedWarningMessage()) . '</div>'
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
     * @param string[] $jobClasses Defaults to both of the policy's job classes; callers that
     *     only care about one (e.g. a GridField's own export button only needs to dedupe
     *     against export jobs, not import jobs) can narrow this.
     */
    public function pendingJobExists(?array $jobClasses = null): bool
    {
        $jobClasses ??= [$this->policy->exportJobClass(), $this->policy->importJobClass()];

        if (!$this->owner->exists()) {
            return false;
        }

        $exportJobClass = $this->policy->exportJobClass();
        $importJobClass = $this->policy->importJobClass();

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
