# PagePacker — developer guide

`madecurious/silverstripe-page-packer` (namespace `MadeCurious\PagePacker`) lets an editor
export a single `SiteTree` page — its Elemental blocks, Userforms definitions, and
referenced files/images (including ones embedded as TinyMCE `[image]`/`[file_link]`
shortcodes) — into a downloadable zip, and import that zip elsewhere to recreate the page
as a new draft, entirely through the CMS UI.

## Requirements

- PHP `^8.1`
- `silverstripe/framework` `^5.4`
- `silverstripe/cms` `^5.4`
- `silverstripe/versioned` `^2.4`
- `symbiote/silverstripe-queuedjobs` `^5.3`
- `madecurious/silverstripe-record-packer` `^0.1.0`

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

## Schema

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

## CMS UI integration

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

## Known limitations / gotchas

- **`CMSMain_Content.ss` override fragility** — see the CMS UI integration note above; this
  is the one integration point likely to need attention on upgrade or in a themed project.
- **Import always creates a new page** — there's no "import into an existing page" path;
  the target site's page class list must include whatever class the export's manifest
  names, or the import will still create a page but as a bare `SiteTree` with none of the
  original class's fields/blocks populated.
