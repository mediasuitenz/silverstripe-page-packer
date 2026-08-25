<?php

namespace MadeCurious\PagePacker\Support;

use MadeCurious\PagePacker\Model\ExportRequest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\ORM\DataObject;

/**
 * Builds the read-only "Export history" GridField shown for a packable record's past
 * export/import attempts — a plain list + delete (no add/edit, since a history row is never
 * hand-authored), with {@see ExportRequest}'s `StaleBadge`/`DownloadLinkHtml` explicitly
 * formatted so they render as HTML rather than being escaped as plain text.
 *
 * Shared by {@see \MadeCurious\PagePacker\Controllers\CMSPageContentExportController} (the
 * SiteTree/CMSMain "Content Export" tab) and {@see \MadeCurious\PagePacker\Extensions\PackableExtension}
 * (added inline to a generic packable DataObject's own edit form, in place of the raw
 * auto-scaffolded relation field it removes — see that extension's own doc comment for why a
 * generic record needs this at all when SiteTree gets it from a dedicated tab instead).
 */
final class ExportHistoryField
{
    public static function create(DataObject $owner): GridField
    {
        $config = GridFieldConfig_Base::create();
        $config->addComponent(GridFieldDeleteAction::create());

        // using setFieldFormatting() to ensure we get rendered HTML
        $config->getComponentByType(GridFieldDataColumns::class)->setFieldFormatting([
            'StaleBadge' => fn ($value, $item) => $item->StaleBadge,
            'DownloadLinkHtml' => fn ($value, $item) => $item->DownloadLinkHtml,
            'IncludeAssets' => fn ($value, $item) => $item->IncludeAssets ? 'Yes' : 'No',
        ]);

        return GridField::create(
            'ExportRequests',
            _t(self::class . '.HISTORY_TITLE', 'Export history'),
            $owner->ExportRequests(),
            $config
        );
    }
}
