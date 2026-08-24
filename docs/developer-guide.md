# PagePacker — developer guide

`madecurious/silverstripe-page-packer` (namespace `MadeCurious\PagePacker`) lets an editor
export a single `SiteTree` page — its Elemental blocks, Userforms definitions, and
referenced files/images (including ones embedded as TinyMCE `[image]`/`[file_link]`
shortcodes) — into a downloadable zip, and import that zip elsewhere to recreate the page
as a new draft, entirely through the CMS UI. This branch (`cms5`) targets SilverStripe
CMS 5; a separate `cms6` branch targets CMS 6.

## Requirements

- PHP `^8.1`
- `silverstripe/framework` `^5.4`
- `silverstripe/cms` `^5.4`
- `silverstripe/versioned` `^2.4`
- `symbiote/silverstripe-queuedjobs` `^5.3`

Optional, auto-detected if installed (`composer.json` `suggest`):

- `dnadesign/silverstripe-elemental` — export/import of Elemental content blocks
- `silverstripe/userforms` — export/import of `UserDefinedForm` field/recipient definitions
- `dnadesign/silverstripe-elemental-userforms` — export/import of `ElementForm` blocks

## Installation

```bash
composer require madecurious/silverstripe-page-packer
```

Then flush (`?flush=1`). There is no JS build step — client JS is plain, hand-written
vanilla JS committed directly to `client/dist/js/` and exposed to the CMS via composer's
`extra.expose` config (`composer.json`), so nothing needs compiling.

`symbiote/silverstripe-queuedjobs` must actually be processing its queue (via its cron-driven
`ProcessJobQueueTask`, or an equivalent scheduled runner) on every environment this runs on —
PagePacker does all its work through queued jobs, so exports and imports will sit in
`Queued` forever on an environment where the job queue isn't being processed.

## Configuration

The one setting intended for project-level overriding is `mismatch_behaviour` on
`SiteTreeSerializer`, controlling what happens when an export/import encounters a relation
shape, class, or field that doesn't match what's expected on the target site:

```yaml
MadeCurious\PagePacker\Serialization\SiteTreeSerializer:
  mismatch_behaviour: fail       # or 'best_effort'
```

- `fail` (the module's shipped default, set in this module's own `_config/extensions.yml`)
  — abort with a clear error the moment a mismatch is encountered.
- `best_effort` — skip what doesn't match and record a warning instead, letting the rest of
  the import/export complete.

One exception either way: an **unresolvable root page class** on import is always fatal,
regardless of this setting — enforced separately in `SiteTreeImportJob::doImport()` (see
`tests/SiteTreeImportJobTest.php` for the covering test).

Two further sets of `private static` config are technically overridable per project, though
not documented as a stable public API:

- `RelationSchema::$excluded_system_fields`, `$excluded_relation_classes`,
  `$excluded_relation_names` (`src/Serialization/RelationSchema.php`) — lists of
  fields/classes/relation names PagePacker will never attempt to walk. A project could
  extend these via YAML if it has custom relations that shouldn't be included in exports.
- `AssetBundler::$import_folder` (`src/Serialization/AssetBundler.php`) — the assets folder
  (`page-packer-imports`) used both to store outgoing export zips and to materialise
  incoming imported assets. Note the upload field on the Add-New-Page screen writes to a
  separate, hard-coded folder, `page-packer-uploads`
  (`src/Extensions/CMSMainAddFormImportExtension.php`).

## Architecture

All PHP lives under `src/`, PSR-4-mapped to `MadeCurious\PagePacker\`.

### Extensions (`src/Extensions/`)

| Class | Applied to | Responsibility |
|---|---|---|
| `SiteTreeExportExtension` | `SiteTree` | Adds the `ExportRequests` has_many, the Export button in `ActionMenus.MoreOptions`, and hides the auto-scaffolded `ExportRequests` tab |
| `SiteTreeLockExtension` | `SiteTree` | Vetoes `canEdit()`/`canPublish()` while an export/import job for that page is in flight (bypassed when `Director::is_cli()`, so the job itself can still write); injects the "currently being exported/imported" banner |
| `CMSMainExportActionExtension` | `CMSMain` | Builds/handles the Export modal form and its `doExport` action |
| `CMSMainAddFormImportExtension` | `CMSMain` | Adds the upload field to Add-New-Page, the `importPreview` JSON endpoint, and hooks `updateDoAdd()` to divert page creation into an import job when a file was uploaded |
| `CMSMainContentExportTabExtension` | `CMSMain` | Supplies `$LinkPageContentExport` / `$HasContentExport` to the template that renders the Content Export tab |

### Controller

`CMSPageContentExportController extends CMSMain` (`src/Controllers/CMSPageContentExportController.php`)
is a second, hidden top-level CMS admin section (`$ignore_menuitem = true`) reusing CMSMain's
tree/edit-form machinery, registered at `url_segment = 'pages/contentexport'`. It's what
actually renders the Content Export tab's GridField of `ExportRequest` records.

### Model

`ExportRequest extends DataObject` (`src/Model/ExportRequest.php`), table
`PagePacker_ExportRequest` — one row per export or import attempt:

- `$db`: `Status` (Enum: Queued/Complete/Failed), `Origin` (Enum: Export/Import),
  `SourceContentTimestamp` (Varchar 32, used for stale/fresh detection),
  `StatusMessage` (Text), `Description` (Varchar 255), `IncludeAssets` (Boolean)
- `$has_one`: `Page` → `SiteTree`, `Member` → `Member`, `ResultFile` → `File`
- `$owns`: `ResultFile`

No new column is added to `SiteTree` itself — `SiteTreeExportExtension` only adds the
reverse `has_many` relation (backed by `ExportRequest.PageID`).

### Security

`ImportExportPermissions implements PermissionProvider` (`src/Security/ImportExportPermissions.php`)
registers the `SITETREE_IMPORT_EXPORT` permission (category "Content"), assignable per
Security group. Both export and import are gated on it.

### Jobs (`src/Jobs/`)

Both export and import run as `symbiote/silverstripe-queuedjobs` jobs, not synchronous
requests:

- `SiteTreeExportJob extends AbstractQueuedJob` — reads the page's **published (LIVE)**
  content via `Versioned::withVersionedMode()`/`set_stage()`, walks every owned relation,
  and writes the result through `AssetBundler` and `SiteTreeSerializer`.
- `SiteTreeImportJob extends AbstractQueuedJob` — reads an uploaded zip, re-classes the bare
  `SiteTree` stub created by `CMSMainAddFormImportExtension::updateDoAdd()` to the manifest's
  target class, and recreates every node/relation/asset onto the **draft** stage. On
  failure, the stub is kept and retitled ("Import failed: …") with a `Failed`
  `ExportRequest`, rather than being silently removed.

### Serialization (`src/Serialization/`)

- `SiteTreeSerializer` — the two-pass export/import engine; owns `mismatch_behaviour` (see
  Configuration above) and `flagMismatch()`, the central hook for how a mismatch is
  reported/handled.
- `RelationSchema` — shared rules for classifying a class's fields/relations into scalar
  fields, `has_one`, asset relations, and owned `has_many`/`many_many` — used by both the
  export and import paths so they agree on what "belongs" to a page.
- `AssetBundler` — builds/reads the zip container: a `manifest.json` describing the page
  tree plus an `assets/<hash>/<name>` folder for embedded file bytes.
- `ContentShortcodeScanner` — finds and rewrites `[image]`/`[file_link]` TinyMCE shortcodes
  inside HTML fields, so files referenced only from within body text are still captured and
  correctly re-linked after import.
- `ContentTimestampWalker` — computes the latest `LastEdited` across a page and everything
  it owns (including nested Elemental blocks), which is what drives the Stale/Fresh badge
  on the Content Export tab.

#### Schema

The page is packaged up into a recognisable schema so that it can be rebuilt. An example of
a very simple homepage would be:

```json
{
    "format": 1,
    "rootLocalId": "0",
    "meta": {
        "className": "Page",
        "title": "Home",
        "urlSegment": "home"
    },
    "nodes": [
        {
            "className": "Page",
            "fields": {
                "CanViewType": "Inherit",
                "CanEditType": "Inherit",
                "URLSegment": "home",
                "Title": "Home",
                "MenuTitle": null,
                "Content": "<p>Welcome to Silverstripe! This is the default homepage. You can edit this page by opening <a href=\"admin\/\">the CMS<\/a>.<\/p><p>For comprehensive information on Silverstripe CMS, see the <a target=\"_blank\" href=\"http:\/\/docs.silverstripe.org\">developer documentation<\/a>.<\/p>",
                "MetaDescription": null,
                "ExtraMeta": null,
                "ShowInMenus": 1,
                "ShowInSearch": 1,
                "Sort": 1,
                "HasBrokenFile": 0,
                "HasBrokenLink": 0,
                "ReportClass": null
            },
            "hasOne": {
                "ElementalArea": {
                    "localId": "1",
                    "class": "DNADesign\\Elemental\\Models\\ElementalArea"
                }
            },
            "assetHasOne": [],
            "manyMany": {
                "ViewerGroups": [],
                "EditorGroups": [],
                "ViewerMembers": [],
                "EditorMembers": []
            },
            "shortcodeAssets": []
        },
        {
            "className": "DNADesign\\Elemental\\Models\\ElementalArea",
            "fields": {
                "OwnerClassName": "Page"
            },
            "hasOne": {
                "TopPage": {
                    "localId": "0",
                    "class": "Page"
                }
            },
            "assetHasOne": [],
            "manyMany": [],
            "shortcodeAssets": []
        },
        {
            "className": "DNADesign\\Elemental\\Models\\ElementContent",
            "fields": {
                "Title": "Title",
                "ShowTitle": 1,
                "Sort": 1,
                "ExtraClass": null,
                "Style": null,
                "HTML": "<p>Content<\/p>"
            },
            "hasOne": {
                "TopPage": {
                    "localId": "0",
                    "class": "Page"
                },
                "Parent": {
                    "localId": "1",
                    "class": "DNADesign\\Elemental\\Models\\ElementalArea"
                }
            },
            "assetHasOne": [],
            "manyMany": [],
            "shortcodeAssets": []
        }
    ],
    "assets": [],
    "warnings": []
}
```

In this way, you can theoretically build out content and pages as JSON files and share them
around - this might be useful if developing a theme, for example, so that you can share a
kitchen-sink style page along with it.

### CMS UI integration

- A **template override** of `CMSMain_Content.ss`
  (`templates/SilverStripe/CMS/Controllers/CMSMain_Content.ss`) adds the fourth "Content
  Export" tab beside Content/Settings/History. This is the module's most fragile
  integration point: if your project (or another module) already overrides this template,
  PagePacker's tab silently won't appear. If that's the case, merge the changes from this
  module's override into your own project override at
  `app/templates/SilverStripe/CMS/Controllers/Includes/CMSMain_Content.ss` yourself. Keep an
  eye on this if you upgrade `silverstripe/cms`, since a core template change could break it.
- Client JS (`client/dist/js/`, no build step, exposed via `composer.json`'s
  `extra.expose`):
  - `export-modal.js` — a small hand-rolled Bootstrap-modal shim: shows/hides the Export
    dialog (whose HTML arrives inline via a `data-modal` attribute rendered server-side),
    and renders a dismissible toast from a `?page-packer-toast=` querystring param after a
    post-export redirect, stripping the param via `history.replaceState`.
  - `import-preview.js` — polls (via `MutationObserver` + a 500 ms interval) for the hidden
    upload field to gain a file ID, then fetches the `importPreview` endpoint and renders
    the preview table on the Add-New-Page screen.

## Data flow summary

```
Export:  SiteTree (LIVE) ──RelationSchema──▶ walk owned relations
                          ──ContentShortcodeScanner──▶ capture shortcode-embedded assets
                          ──SiteTreeSerializer──▶ manifest.json
                          ──AssetBundler──▶ zip (manifest.json + assets/<hash>/<name>)
         SiteTreeExportJob writes the zip to a File, updates ExportRequest → Complete

Import:  uploaded zip ──AssetBundler──▶ read manifest.json + assets
                       ──SiteTreeSerializer──▶ reconstruct DataObjects (mismatch_behaviour applies)
         SiteTreeImportJob writes onto the DRAFT stage of the stub SiteTree,
         updates ExportRequest → Complete (or Failed)
```

## Testing

PHPUnit is configured via `phpunit.xml.dist` (bootstrap:
`vendor/silverstripe/framework/tests/bootstrap.php`; test suite: `tests/`; coverage
source: `src/`). Run with:

```bash
vendor/bin/phpunit
```

All tests are `SapphireTest`-based with `$usesDatabase = true`:

| Test | Covers |
|---|---|
| `SiteTreeSerializerTest.php` | Full export → import round trip |
| `MismatchHandlingTest.php` | `fail` vs. `best_effort` mismatch behaviour |
| `SiteTreeImportJobTest.php` | Root class mismatch is always fatal, regardless of `mismatch_behaviour` |
| `ExportRequestTest.php` | Stale/fresh detection, including nested Elemental blocks |
| `SiteTreeExportExtensionTest.php` | UI placement / tab-scaffolding regressions |
| `SiteTreeLockExtensionTest.php` | Job-in-flight locking behaviour |
| `CMSMainAddFormImportExtensionTest.php` | The `importPreview` JSON endpoint |

`squizlabs/php_codesniffer` (`^3.7`) is a dev dependency for coding-standards checks; there
is no `composer test`/`composer cs` script defined, so run `vendor/bin/phpunit` and
`vendor/bin/phpcs` directly.

## Known limitations / gotchas

- **No CLI/`BuildTask`** — everything runs through the two queued jobs above; there is no
  dev/task entry point to trigger an export or import outside the CMS UI.
- **No GraphQL** — the only non-page-request endpoint is the JSON `importPreview` action
  added by `CMSMainAddFormImportExtension`.
- **`CMSMain_Content.ss` override fragility** — see the CMS UI integration note above; this
  is the one integration point likely to need attention on upgrade or in a themed project.
- **Import always creates a new page** — there's no "import into an existing page" path;
  the target site's page class list must include whatever class the export's manifest
  names, or the import will still create a page but as a bare `SiteTree` with none of the
  original class's fields/blocks populated.
