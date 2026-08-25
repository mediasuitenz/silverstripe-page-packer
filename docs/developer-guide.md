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
`RecordSerializer` — the one serialization engine both the SiteTree/CMSMain flow and the generic
DataObject/GridField flow share — controlling what happens when an export/import encounters a
relation shape, class, or field that doesn't match what's expected on the target site:

```yaml
MadeCurious\PagePacker\Serialization\RecordSerializer:
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

`PackableExtension` and `RecordLockExtension` are the **only** two extension classes involved in
"does this record get an Export button + locking" — `SiteTree` uses the exact same two classes
as any other packable DataObject, not a subclass of either. What varies between "a SiteTree
page" and "any other packable DataObject" — which permission/job classes to check, where the
modal form is hosted, where the trigger is placed, the locked-record wording — is captured in one
small collaborator interface, `Support\PackingPolicy`, injected into both extensions via
constructor. Two implementations ship: `RecordPackingPolicy` (the default) and
`SiteTreePackingPolicy`. `_config/extensions.yml` registers both as named `Injector` service
variants (`PackingPolicy.record` / `PackingPolicy.sitetree`) plus a default alias, and wires
`SiteTree` up to two more named variants of the extensions themselves
(`PackableExtension.sitetree` / `RecordLockExtension.sitetree`) that are constructed with the
`.sitetree` policy — **exactly** the pattern `silverstripe/versioned`'s own `Versioned` extension
uses for its Stage/StagedVersioned modes (`versioned/_config/versionedextension.yml`): one
extension class, named Injector variants with different constructor args, a default alias. See
`PackingPolicy`'s own class doc for the full reasoning, including why this — rather than a
SiteTree-specific subclass of either extension — is the idiom used here.

| Policy method | `RecordPackingPolicy` (default) | `SiteTreePackingPolicy` (`.sitetree` variant) |
|---|---|---|
| `permissionCode()` | `RECORD_IMPORT_EXPORT` | `SITETREE_IMPORT_EXPORT` |
| `exportJobClass()` / `importJobClass()` | `RecordExportJob` / `RecordImportJob` | `SiteTreeExportJob` / `SiteTreeImportJob` |
| `getExportModalForm()` | Hosted on `RecordPackerController`'s own route, populates `RecordClassName`+`RecordID` | Hosted on whichever CMSMain-derived controller is rendering the page (`Controller::curr()`), populates `PageID`; returns `null` outside that context |
| `placeExportTrigger()` | Pushed flat onto the action bar | Pushed into `ActionMenus.MoreOptions`, next to Save/Publish/Unpublish/Rollback |
| `lockedWarningMessage()` | "This record is currently being exported/imported…" | "This page is currently being exported/imported…" |

The two job classes (`RecordExportJob`/`SiteTreeExportJob`, `RecordImportJob`/`SiteTreeImportJob`)
remain a plain `extends` pair rather than another policy — see "Jobs" below for why that's a
deliberately different call from the extensions.

### Extensions (`src/Extensions/`)

| Class | Applied to | Responsibility |
|---|---|---|
| `PackableExtension` | Any `DataObject`, including `SiteTree` (opt-in via YAML for a project DataObject; see below) | Adds the `ExportRequests` has_many, the "Export" trigger (`addExportTrigger()`, called from `updateCMSActions()` or directly by `GridFieldRecordActionsExtension`), and hides the auto-scaffolded `ExportRequests` tab. Delegates permission/job-class/form-hosting/placement decisions to its injected `PackingPolicy` |
| `RecordLockExtension` | Any `DataObject`, including `SiteTree` (opt-in via YAML) | Vetoes `canEdit()`/`canPublish()` while an export/import job for that record is in flight (bypassed when `Director::is_cli()`, so the job itself can still write); injects the "currently being exported/imported" banner. Also policy-driven |
| `GridFieldRecordActionsExtension` | `GridFieldDetailForm_ItemRequest` (applied globally, no-op for non-packable records) | Calls `$record->extend('addExportTrigger', $actions)` from `updateFormActions()` — the one extend point `GridFieldDetailForm_ItemRequest` actually fires, since it builds its own action bar and never calls `DataObject::getCMSActions()` at all. Needed because a GridField-edited record never goes through `PackableExtension::updateCMSActions()` on its own |
| `CMSMainExportActionExtension` | `CMSMain` | Builds/handles the Export modal form and its `doExport` action — what a SiteTree page's trigger posts to (via `SiteTreePackingPolicy::getExportModalForm()`) |
| `CMSMainAddFormImportExtension` | `CMSMain` | Adds the upload field to Add-New-Page, the `importPreview` JSON endpoint, and hooks `updateDoAdd()` to divert page creation into a `SiteTreeImportJob` when a file was uploaded |
| `CMSMainContentExportTabExtension` | `CMSMain` | Supplies `$LinkPageContentExport` / `$HasContentExport` to the template that renders the Content Export tab |

### Controller

- `CMSPageContentExportController extends CMSMain` (`src/Controllers/CMSPageContentExportController.php`)
  is a second, hidden top-level CMS admin section (`$ignore_menuitem = true`) reusing CMSMain's
  tree/edit-form machinery, registered at `url_segment = 'pages/contentexport'`. It's what
  actually renders the Content Export tab's GridField of `ExportRequest` records, for a page.
- `RecordPackerController` (`src/Controllers/RecordPackerController.php`) is the generic
  equivalent for any packable DataObject — see "Generalisation to any DataObject" below.

### Model

`ExportRequest extends DataObject` (`src/Model/ExportRequest.php`), table
`PagePacker_ExportRequest` — one row per export or import attempt, for **either** flow:

- `$db`: `Status` (Enum: Queued/Complete/Failed), `Origin` (Enum: Export/Import),
  `SourceContentTimestamp` (Varchar 32, used for stale/fresh detection),
  `StatusMessage` (Text), `Description` (Varchar 255), `IncludeAssets` (Boolean)
- `$has_one`: `Record` → `DataObject` (**polymorphic** — declaring the target as bare
  `DataObject::class` makes SilverStripe add a companion `RecordClass` column alongside
  `RecordID`, so this one table serves a SiteTree page just as well as any other packable
  DataObject), `Member` → `Member`, `ResultFile` → `File`
- `$owns`: `ResultFile`
- `canView()`/`canCreate()`/`canEdit()`/`canDelete()` all resolve to whichever permission
  applies — `SITETREE_IMPORT_EXPORT` if `RecordClass` is a SiteTree subclass, otherwise
  `RECORD_IMPORT_EXPORT` — so the two stay independently grantable even though they share a
  table.

No new column is added to `SiteTree` (or any packable DataObject) itself — `PackableExtension`
only adds the reverse `has_many` relation (backed by `ExportRequest.RecordID`/`RecordClass`).

### Security

`ImportExportPermissions implements PermissionProvider` (`src/Security/ImportExportPermissions.php`)
registers two permissions (category "Content"), independently assignable per Security group:

- `SITETREE_IMPORT_EXPORT` — gates the SiteTree/CMSMain flow.
- `RECORD_IMPORT_EXPORT` — gates the generic DataObject/GridField flow.

### Jobs (`src/Jobs/`)

Both export and import run as `symbiote/silverstripe-queuedjobs` jobs, not synchronous
requests. Unlike the two extensions above, the SiteTree jobs are a plain `extends` pair rather
than another `PackingPolicy`-driven pair, deliberately: a queued job is serialized to the
database as a PHP object and later unserialized to run, on its own, with no `Extensible`/
`Injector` "current owner" context to route a policy lookup through the way an extension has —
and the only things that actually differ (title/error text, signature prefix) are simple,
static, per-class overrides with no runtime collaborator to inject. Ordinary inheritance is the
right tool for that; forcing it through the same policy mechanism as the extensions would add
indirection without buying anything:

- `RecordExportJob extends AbstractQueuedJob` — reads the record's current content and writes
  the result through `AssetBundler` and `RecordSerializer`. Only engages
  `Versioned::withVersionedMode()`/`set_stage(LIVE)` when the target record's class actually
  `hasExtension(Versioned::class)` — true for every SiteTree page, false for most plain
  DataObjects, which have no draft/live distinction to switch between at all.
  `SiteTreeExportJob extends RecordExportJob` (title text + signature prefix only — see the
  table above).
- `RecordImportJob extends AbstractQueuedJob` — reads an uploaded zip and imports it onto a
  stub that's already either the exact target class or a superclass of it (reclassing via
  `newClassInstance()` only when the manifest's root is a more specific subclass); rejects
  anything else with a clear error. For the page tree, the stub starts as a bare, un-typed
  `SiteTree` (created by `CMSMainAddFormImportExtension::updateDoAdd()`), so any SiteTree
  subclass satisfies that rule — exactly the original page-tree behaviour, just expressed as
  the general case. On failure, the stub is kept and (if it has a `Title` field) retitled
  ("Import failed: …") with a `Failed` `ExportRequest`, rather than being silently removed.
  `SiteTreeImportJob extends RecordImportJob` (title/error text + signature prefix only).

### Serialization (`src/Serialization/`)

- `RecordSerializer` — the two-pass export/import engine; owns `mismatch_behaviour` (see
  Configuration above) and `flagMismatch()`, the central hook for how a mismatch is
  reported/handled. Despite living in a module that started out page-only, this class has
  never actually been SiteTree-specific — it walks whatever `RelationSchema` says belongs to
  the root record's class, regardless of what that class is, which is what makes the generic
  DataObject/GridField flow possible without a second serializer.
- `RelationSchema` — shared rules for classifying a class's fields/relations into scalar
  fields, `has_one`, asset relations, and owned `has_many`/`many_many` — used by both the
  export and import paths so they agree on what "belongs" to a record.
- `AssetBundler` — builds/reads the zip container: a `manifest.json` describing the record's
  node graph plus an `assets/<hash>/<name>` folder for embedded file bytes.
- `ContentShortcodeScanner` — finds and rewrites `[image]`/`[file_link]` TinyMCE shortcodes
  inside HTML fields, so files referenced only from within body text are still captured and
  correctly re-linked after import.
- `ContentTimestampWalker` — computes the latest `LastEdited` across a record and everything
  it owns (including nested Elemental blocks), which is what drives the Stale/Fresh badge.

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

### Generalisation to any DataObject

Every class in the tables above (`PackableExtension`, `RecordLockExtension`, `RecordExportJob`,
`RecordImportJob`, the unified `ExportRequest`, `RecordSerializer`) works on any project
`DataObject`, typically one edited through an ordinary GridField rather than the page tree —
apply `RecordLockExtension` + `PackableExtension` to it via YAML, with no extra configuration
needed (see the README); the default `PackingPolicy` variant applies automatically. Two more,
GridField-specific pieces exist only on the generic side, since the page tree has no equivalent
need for them:

| Class | Applied to / used by | Responsibility |
|---|---|---|
| `RecordPackerController` | Its own route (`_config/routes.yml`, `page-packer/…`) | The generic equivalent of `CMSMainExportActionExtension` + `CMSMainAddFormImportExtension`'s server-side actions, but hosted independently rather than attached to CMSMain — there's no single admin controller every packable DataObject is guaranteed to share the way pages share CMSMain. `PackableExtension`'s trigger and `GridFieldRecordImportButton`'s trigger both post here |
| `GridFieldRecordImportButton` | Any `GridFieldConfig`, opt-in per-GridField | The GridField/DataObject equivalent of `CMSMainAddFormImportExtension`'s "Add new page" upload option — creates a new record in that GridField from an uploaded file |
| `GridFieldRecordExportAction` | Any `GridFieldConfig`, opt-in per-GridField | An optional one-click "Export" action per row (alongside `GridFieldDeleteAction` etc.), queuing an export with sane defaults without needing to open the record's detail view first |

Two things this generalisation had to solve that the SiteTree flow never needed to:

- **No guaranteed Versioned staging.** Every `SiteTree` is versioned by definition; a project
  `DataObject` usually isn't (there's no "draft" for a Catalogue). `RecordExportJob`/
  `RecordImportJob` check `hasExtension(Versioned::class)` on the target class before deciding
  whether to engage `Versioned::withVersionedMode()`/`set_stage()` at all — for an unversioned
  class, its current content simply *is* what gets exported/imported, no stage-switching
  involved. `SiteTreeExportJob`/`SiteTreeImportJob` inherit this unchanged; it happens to always
  take the "versioned" branch for them, since SiteTree always has the extension.
- **No dedicated "Add new" screen to piggyback on.** The page tree already has one screen
  (`CMSMainAddFormImportExtension` hooks it) where "import instead of picking a type" makes
  sense. A GridField has no equivalent, so `GridFieldRecordImportButton` is a normal, opt-in
  GridField component instead, and — since there's no single class the uploaded file could be
  (unlike the page tree, where any SiteTree subclass is fair game) — `RecordImportJob` requires
  the manifest's root class to be the stub's own class or a subclass of it, rejecting anything
  else with a clear error rather than silently reclassing to something unrelated. This is the
  exact same rule the page tree already needed (a bare `SiteTree` stub accepting any SiteTree
  subclass), just expressed generically instead of hard-coded to `SiteTree::class`.

Two extend points exist purely to reach a GridField-edited record at all:

- `GridFieldRecordActionsExtension`, applied globally to `GridFieldDetailForm_ItemRequest`
  (harmless no-op for every non-packable record), calls `$record->extend('addExportTrigger', …)`
  from `updateFormActions()` — the one extend point that controller actually fires, since it
  builds its own action bar and never calls `DataObject::getCMSActions()`. Delegating via
  `extend()` rather than fetching the `PackableExtension` instance and calling a method on it
  directly matters here: an `Extension`'s `$owner` is only valid for the duration of a call made
  through `extend()`/`invokeExtension()`, so a direct call would see a null owner.
- `ModalMarkup` (`src/Support/ModalMarkup.php`) is not an extend point but is worth noting here:
  it's the one place the "trigger button carrying its whole modal as a `data-modal` string"
  markup is built, shared by `PackableExtension`'s Export trigger and
  `GridFieldRecordImportButton`'s Import trigger. `ExportQueuer`
  (`src/Support/ExportQueuer.php`) is the equivalent shared helper for the "create the
  `ExportRequest` row and queue the job" step, used by `CMSMainExportActionExtension::doExport()`,
  `RecordPackerController::doExport()`, and `GridFieldRecordExportAction::handleAction()` alike.

Reuses rather than re-implements: `RecordSerializer`, `RelationSchema`, `AssetBundler`, and
`ContentShortcodeScanner` are all `DataObject`-generic internally (see each class's own doc
comment) — none of them needed to change for any of this, and the SiteTree flow uses the exact
same instances. `client/dist/js/export-modal.js` is reused as-is for the same reason (its modal
open/close logic was never SiteTree-specific); only the import *preview* widget needed a
generalised sibling (`record-import-preview.js`), since the original hard-coded a single upload
field name/container id for the one page-tree screen it was written for.

Kept deliberately independent rather than collapsed into one permission/one job pair: the two
permissions (`SITETREE_IMPORT_EXPORT` / `RECORD_IMPORT_EXPORT`), the two `PackingPolicy`
implementations, and the SiteTree-named job classes (`SiteTreeExportJob`/`SiteTreeImportJob`) —
so a site can grant/apply the two flows independently, and a page's queued job/history stays
identifiable as such (by class name and permission) even though the underlying mechanics and the
`ExportRequest` table are now fully shared.

## Data flow summary

One shared pipeline — `RecordExportJob`/`RecordImportJob` (used directly for a generic
DataObject, or via the `SiteTreeExportJob`/`SiteTreeImportJob` subclass for a page) and the one
`ExportRequest` history table throughout:

```
Export:  record (LIVE stage if versioned, else current content)
                          ──RelationSchema──▶ walk owned relations
                          ──ContentShortcodeScanner──▶ capture shortcode-embedded assets
                          ──RecordSerializer──▶ manifest.json
                          ──AssetBundler──▶ zip (manifest.json + assets/<hash>/<name>)
         RecordExportJob (or SiteTreeExportJob) writes the zip to a File,
         updates ExportRequest → Complete

Import:  uploaded zip ──AssetBundler──▶ read manifest.json + assets
                       ──RecordSerializer──▶ reconstruct DataObjects (mismatch_behaviour applies)
         RecordImportJob (or SiteTreeImportJob) writes onto the stub — DRAFT stage if the
         stub's class is versioned, reclassed only if the manifest's root is a more specific
         subclass of it — updates ExportRequest → Complete (or Failed)
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
| `RecordSerializerTest.php` | Full export → import round trip against SiteTree/Elemental/Userforms content |
| `MismatchHandlingTest.php` | `fail` vs. `best_effort` mismatch behaviour |
| `SiteTreeImportJobTest.php` | Root class mismatch is always fatal, regardless of `mismatch_behaviour` |
| `ExportRequestTest.php` | Stale/fresh detection for both a SiteTree page (versioned, `SITETREE_IMPORT_EXPORT`) and a generic record (unversioned, `RECORD_IMPORT_EXPORT`), including nested-owned-child cases for each |
| `SiteTreeExportExtensionTest.php` | UI placement / tab-scaffolding regressions, exercising `PackableExtension` via its `.sitetree` `PackingPolicy` variant |
| `SiteTreeLockExtensionTest.php` | Job-in-flight locking behaviour, exercising `RecordLockExtension` via its `.sitetree` variant |
| `CMSMainAddFormImportExtensionTest.php` | The `importPreview` JSON endpoint |
| `GenericDataObjectRoundTripTest.php` | Proves the core engine round-trips a genuinely non-page, unversioned `DataObject` and its owned `has_many` children (see `tests/Fixtures/`) |
| `PackableExtensionTest.php` | The generic export trigger — mirrors `SiteTreeExportExtensionTest` for a plain DataObject |
| `RecordLockExtensionTest.php` | The generic locking behaviour — mirrors `SiteTreeLockExtensionTest` |
| `RecordImportJobTest.php` | Root-class mismatch is always fatal, *plus* the "wrong GridField" mismatch case that has no SiteTree analogue |
| `GridFieldRecordActionsExtensionTest.php` | The export trigger actually appearing on a GridField-edited record (not just a `getCMSActions()`-based one) |
| `GridFieldRecordImportButtonTest.php` | The opt-in GridField import button's packable/permission gating |
| `GridFieldRecordExportActionTest.php` | The opt-in GridField row export action's gating, and that it actually queues a job |
| `RecordPackerControllerTest.php` | `doExport`/`doImport`/`importPreview` on the standalone controller |

`tests/Fixtures/TestCatalogue.php` + `TestProduct.php` are a deliberately plain, unversioned,
non-SiteTree `DataObject` pair (with `PackableExtension`/`RecordLockExtension` applied directly
via `private static $extensions`) used across all of the above — standing in for a real project
model like this module's own README example.

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
- **The generic DataObject path is opt-in, per class** — `PackableExtension`/
  `RecordLockExtension` only ever do anything once applied via YAML; `GridFieldRecordImportButton`
  only ever does anything once added to a specific `GridFieldConfig`. Nothing here scans for or
  auto-enables itself against project DataObjects.
- **A GridField import is scoped to that GridField's own model class** — see
  `RecordImportJob`'s mismatch rule above; there is no "import creates whatever class the file
  says" behaviour outside the page tree.
