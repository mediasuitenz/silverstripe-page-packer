<?php

namespace MadeCurious\PagePacker\Forms\GridField;

use MadeCurious\PagePacker\Extensions\PackableExtension;
use MadeCurious\PagePacker\Extensions\RecordLockExtension;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Support\ExportQueuer;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Permission;

/**
 * An optional, opt-in GridField per-row action — add it to a GridFieldConfig (alongside
 * GridFieldDeleteAction etc., following the same GridField_ColumnProvider/GridField_ActionProvider
 * shape) to let an editor queue an export for a single row directly from the list, with no need
 * to open its detail view first. This is deliberately one-click/immediate with sane defaults
 * (referenced assets included, no description) — the detail view's own Export button (see
 * PackableExtension) remains where an editor chooses those options explicitly.
 *
 * Only ever renders for a GridField whose model class has {@see PackableExtension} applied,
 * mirroring {@see GridFieldRecordImportButton}.
 */
class GridFieldRecordExportAction implements GridField_ColumnProvider, GridField_ActionProvider
{
    private const ACTION_NAME = 'pagepackerexport';

    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('Actions', $columns, true)) {
            $columns[] = 'Actions';
        }
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return $columnName === 'Actions' ? ['title' => ''] : [];
    }

    public function getColumnsHandled($gridField)
    {
        return ['Actions'];
    }

    public function getActions($gridField)
    {
        return [self::ACTION_NAME];
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        $field = $this->getExportAction($gridField, $record);

        return $field ? $field->Field() : null;
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== self::ACTION_NAME) {
            return;
        }

        $record = $gridField->getList()->byID($arguments['RecordID']);

        if (!$record) {
            return;
        }

        if (!$this->canExport($record)) {
            throw new ValidationException(
                _t(self::class . '.EXPORT_PERMISSION_FAILURE', 'No permission to export this record')
            );
        }

        ExportQueuer::queue($record, RecordExportJob::class, true);
    }

    private function getExportAction(GridField $gridField, $record): ?GridField_FormAction
    {
        if (!$this->canExport($record)) {
            return null;
        }

        $title = _t(self::class . '.EXPORT', 'Export');

        return GridField_FormAction::create(
            $gridField,
            'PagePackerExport' . $record->ID,
            false,
            self::ACTION_NAME,
            ['RecordID' => $record->ID]
        )
            ->addExtraClass('btn--icon-md font-icon-share btn--no-text grid-field__icon-action action-menu--handled')
            ->setAttribute('classNames', 'font-icon-share')
            ->setDescription($title)
            ->setAttribute('aria-label', $title);
    }

    private function canExport($record): bool
    {
        if (!$record->hasExtension(PackableExtension::class)) {
            return false;
        }

        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return false;
        }

        if (!$record->hasMethod('canView') || !$record->canView()) {
            return false;
        }

        if ($record->hasExtension(RecordLockExtension::class)
            && $record->pendingJobExists([RecordExportJob::class])
        ) {
            return false;
        }

        return true;
    }
}
