<?php

namespace MadeCurious\PagePacker\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * Adds the packer "Export" button to a GridField-managed record's own edit form, for any
 * DataObject with {@see PackableExtension} applied.
 *
 * Applied globally by default (see this module's _config/extensions.yml) — a harmless no-op for
 * every other GridField-edited record — because GridFieldDetailForm_ItemRequest builds its
 * action bar itself (see its getFormActions(), which never calls DataObject::getCMSActions()),
 * so PackableExtension::updateCMSActions() alone would never fire for a record edited this way.
 * This hooks the one extend point GridFieldDetailForm_ItemRequest actually calls instead
 * (updateFormActions), and delegates straight back to the record's own PackableExtension
 * instance so the button/modal markup only lives in one place.
 *
 * Delegates via $record->extend() rather than fetching and calling the PackableExtension
 * instance directly — an Extension's $owner is only valid for the duration of a call made
 * through extend()/invokeExtension() (see Extension::invokeExtension()'s setOwner()/
 * clearOwner() bracketing), so calling addExportTrigger() straight off a fetched instance
 * would see a null owner.
 */
class GridFieldRecordActionsExtension extends Extension
{
    public function updateFormActions(FieldList $actions): void
    {
        $record = $this->owner->getRecord();

        if (!$record instanceof DataObject
            || !$record->exists()
            || !$record->hasExtension(PackableExtension::class)
        ) {
            return;
        }

        $record->extend('addExportTrigger', $actions);
    }
}
