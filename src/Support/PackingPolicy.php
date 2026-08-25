<?php

namespace MadeCurious\PagePacker\Support;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * Everything {@see \MadeCurious\PagePacker\Extensions\PackableExtension} and
 * {@see \MadeCurious\PagePacker\Extensions\RecordLockExtension} need that varies between "a
 * SiteTree page" and "an arbitrary packable DataObject" — injected into both extensions as a
 * constructor-supplied collaborator rather than hard-coded, so the two extension classes serve
 * both cases without a SiteTree-specific subclass of either.
 *
 * Two implementations ship: {@see RecordPackingPolicy} (the default, for any project DataObject)
 * and {@see SiteTreePackingPolicy} (for `SiteTree`, wired up as a named Injector service variant
 * in this module's own `_config/extensions.yml` — the same pattern
 * `silverstripe/versioned`'s own `Versioned` extension uses for its Stage/StagedVersioned modes:
 * one extension class, two named `Injector` service variants with different constructor args,
 * and a default alias).
 */
interface PackingPolicy
{
    /**
     * The Permission code gating this record's export/import.
     */
    public function permissionCode(): string;

    /**
     * The job class actually queued for this record's export — what
     * {@see RecordLockExtension::pendingJobExists()} checks the queue for by default, and what
     * {@see PackableExtension::addExportTrigger()} checks isn't already in flight.
     */
    public function exportJobClass(): string;

    /**
     * The job class actually queued for this record's import.
     */
    public function importJobClass(): string;

    /**
     * Wording for the "this record is currently being exported/imported" banner
     * RecordLockExtension::updateCMSFields() shows.
     */
    public function lockedWarningMessage(): string;

    /**
     * Builds and pre-populates the Export modal's form for $owner, or returns null if there's
     * nowhere to host it right now (e.g. the SiteTree policy needs a CMSMain-derived controller
     * currently rendering the page, and returns null outside of that context).
     */
    public function getExportModalForm(DataObject $owner): ?Form;

    /**
     * Places the built trigger button onto the record's CMS actions — e.g. pushed flat for a
     * generic record, or into `ActionMenus.MoreOptions` for a SiteTree page.
     */
    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void;
}
