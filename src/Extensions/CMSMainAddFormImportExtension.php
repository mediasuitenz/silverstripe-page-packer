<?php

namespace MadeCurious\PagePacker\Extensions;

use MadeCurious\PagePacker\Jobs\SiteTreeImportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\SiteTreeExporter;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Integrates importing into the native "Add new page" screen, as an alternative to picking a
 * page type, rather than a separate tree-tool button: 1) choose top-level or under another page
 * (native, unchanged) → 2) either pick a page type and create it (native, unchanged) OR upload a
 * previously exported file (this extension).
 *
 * CMS5.4's silverstripe/cms still ships the screen as a dedicated controller,
 * `SilverStripe\CMS\Controllers\CMSPageAddController` (`extends CMSPageEditController extends
 * CMSMain` — its own class doc marks it `@deprecated 5.4.0 Will be replaced with
 * SilverStripe\CMS\Forms\CMSMainAddForm in a future major release`, which is exactly the class
 * this module's CMS6 branch targets instead — that class doesn't exist at all in this CMS5 line).
 * Both extension points this class needs actually live on the SAME class here, not two:
 * - `updatePageOptions()` — `CMSPageAddController::AddForm()`'s own hook name for its field list
 *   (CMS6's equivalent `CMSMainAddForm::createFields()` hook is called `updateFields`, and its
 *   page-type chooser field is named `RecordType`; here it's `updatePageOptions`, and the field
 *   is named `PageType`).
 * - `updateDoAdd()` — `CMSPageAddController::doAdd()`'s hook, identical in name and signature to
 *   CMS6's, needing no changes.
 * Attaching this extension once to `CMSMain` (see _config/extensions.yml) reaches both, since
 * `CMSPageAddController` inherits CMSMain's extensions like any other subclass.
 *
 * `updateDoAdd(&$record, $form)` receives $record BY REFERENCE (Extensible::extend()'s variadic
 * arguments are all by-ref) — reassigning it here changes what `doAdd()` goes on to write, even
 * though `getNewItem()` already instantiated it as whatever page type happened to be selected
 * (defaulted, never left blank) by the time this hook runs. This is what makes hooking the
 * native form viable after all — the earlier research flagged the *timing* (target class known
 * only after instantiation) as the reason to avoid this form, but reassignment-by-reference
 * sidesteps that rather than requiring the class to be known upfront.
 *
 * The stub is written a write() call early, inside this hook, rather than deferred — doAdd()
 * unconditionally calls $record->write() again immediately after this hook returns regardless,
 * so that second call is a harmless no-op UPDATE once this hook has already given the stub a
 * real ID; there's no need for a more elaborate "queue after the real write" mechanism.
 */
class CMSMainAddFormImportExtension extends Extension
{
    public function updatePageOptions(FieldList $fields): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        $fields->insertAfter('PageType', LiteralField::create(
            'PagePackerOrDivider',
            '<p class="page-packer-or-divider">'
            . _t(self::class . '.OR', '— or —')
            . '</p>'
        ));

        $fields->insertAfter('PagePackerOrDivider', UploadField::create(
            'PagePackerFile',
            _t(self::class . '.IMPORT_FILE', 'Import a previously exported page (.zip)')
        )->setAllowedExtensions(['zip']));
    }

    public function updateDoAdd(&$record, Form $form): void
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return;
        }

        $uploadField = $form->Fields()->dataFieldByName('PagePackerFile');
        $items = $uploadField ? $uploadField->getItems() : null;
        $uploadedFile = $items ? $items->first() : null;

        if (!$uploadedFile instanceof File) {
            // No file uploaded — leave $record untouched, native "create new" flow proceeds.
            return;
        }

        if (!$record instanceof DataObject || !$record->hasExtension(SiteTreeLockExtension::class)) {
            // Defensive: only page types actually carry the lock extension (applied to
            // SiteTree). Should always be true here since this form only ever creates pages.
            return;
        }

        $parentID = (int) $record->ParentID;

        // Reclass to a bare stub regardless of whichever RecordType happened to be selected —
        // see SiteTreeImportJob's class doc for why this must be plain SiteTree, not Page: a
        // per-site extension (e.g. Elemental applied to Page specifically) can introduce a
        // column on a "safer-looking" subclass just as easily as on a custom one.
        $record = $record->newClassInstance(SiteTree::class);
        $record->Title = _t(self::class . '.IMPORTING_TITLE', 'Importing…');
        $record->ParentID = $parentID;
        $record->write();

        $mismatchBehaviour = SiteTreeExporter::config()->get('mismatch_behaviour');
        $job = new SiteTreeImportJob($record, $uploadedFile, $mismatchBehaviour);
        QueuedJobService::singleton()->queueJob($job);
    }
}
