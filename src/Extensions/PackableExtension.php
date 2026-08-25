<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Support\ModalMarkup;
use MadeCurious\PagePacker\Support\PackingPolicy;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Apply this (plus {@see RecordLockExtension}) to a project DataObject to get an "Export"
 * button + export history. `SiteTree` gets exactly the same capability by applying the very
 * same two extension classes — see this module's `_config/extensions.yml` — just wired to the
 * `.sitetree` {@see PackingPolicy} Injector variant instead of the default one, rather than via
 * a SiteTree-specific subclass of this class. See {@see PackingPolicy}'s own doc comment for why
 * that's the idiom used here (it mirrors `silverstripe/versioned`'s own `Versioned` extension).
 *
 * Two hosting contexts are supported:
 * - A record with its own LeftAndMain-style getCMSActions() (rare for a plain DataObject, but
 *   the same shape SiteTree/CMSMain uses) gets the trigger via updateCMSActions() below.
 * - A record edited through an ordinary GridField (the common case — see the developer guide)
 *   instead gets it via {@see GridFieldRecordActionsExtension}, which calls addExportTrigger()
 *   directly, because GridFieldDetailForm_ItemRequest builds its action bar itself and never
 *   calls DataObject::getCMSActions() at all.
 */
class PackableExtension extends Extension
{
    private static $has_many = [
        'ExportRequests' => ExportRequest::class,
    ];

    private PackingPolicy $policy;

    public function __construct(?PackingPolicy $policy = null)
    {
        parent::__construct();

        $this->policy = $policy ?? Injector::inst()->get(PackingPolicy::class);
    }

    public function updateCMSFields(FieldList $fields): void
    {
        // hide the export requests default — an editor sees this history through the module's
        // own UI, not the raw scaffolded relation.
        $fields->removeByName('ExportRequests');
    }

    public function updateCMSActions(FieldList $actions): void
    {
        $this->addExportTrigger($actions);
    }

    /**
     * Builds the "Export" trigger button (carrying the whole modal as a `data-modal` HTML
     * string) and places it onto $actions — unless the current member lacks permission, the
     * record hasn't been saved yet, an export/import for it is already in flight, or (for a
     * SiteTree page) no CMSMain-hosted form is available to build.
     *
     * Public (rather than folded into updateCMSActions()) so GridFieldRecordActionsExtension can
     * call it directly against the same extension instance already attached to the record.
     */
    public function addExportTrigger(FieldList $actions): void
    {
        if (!Permission::check($this->policy->permissionCode())) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        $locked = $this->owner->hasExtension(RecordLockExtension::class)
            && $this->owner->pendingJobExists([$this->policy->exportJobClass()]);

        if ($locked) {
            return;
        }

        $form = $this->policy->getExportModalForm($this->owner);

        if (!$form) {
            return;
        }

        // Reused as-is: this modal's open/close behaviour is generic (keyed off
        // data-toggle="modal"/data-modal), nothing SiteTree-specific about it.
        Requirements::javascript('madecurious/silverstripe-page-packer: client/dist/js/export-modal.js');

        $modalId = 'PackerExportModal' . $this->owner->ID;
        $modalHtml = ModalMarkup::modal(
            $modalId,
            (string) _t(self::class . '.MODAL_TITLE', 'Export record'),
            $form->forTemplate()
        );
        $trigger = LiteralField::create(
            'PackerExportModalTrigger',
            ModalMarkup::trigger(
                $modalId,
                (string) _t(self::class . '.EXPORT_BUTTON', 'Export'),
                'font-icon-share',
                $modalHtml
            )
        );

        $this->policy->placeExportTrigger($actions, $trigger);
    }
}
