# PagePacker

Pack up a single SilverStripe page (with its Elemental blocks, Userforms definitions, referenced
files/images — including ones embedded as TinyMCE shortcodes) into a downloadable file, and unpack
it elsewhere to create a new page — entirely through the CMS UI, no developer/CLI access needed.
Built for moving content between separate environments (e.g. dev → UAT → prod).

## Requirements

* PHP ^8.3
* silverstripe/framework ^6
* silverstripe/cms ^6
* silverstripe/versioned ^3
* symbiote/silverstripe-queuedjobs ^6.2

Optional, detected automatically if installed:

* dnadesign/silverstripe-elemental — exports/imports Elemental content blocks
* silverstripe/userforms — exports/imports UserDefinedForm field/recipient definitions
* dnadesign/silverstripe-elemental-userforms — exports/imports ElementForm blocks

## Installation

```
composer require madecurious/silverstripe-page-packer
```

Then, to get the "Content Export" tab appearing as a genuine peer of Content/Settings/History
(rather than tucked away elsewhere), **copy the template override** into your project:

```
cp vendor/madecurious/silverstripe-page-packer/docs/templates/CMSMain_Content.ss \
   app/templates/SilverStripe/CMS/Controllers/Includes/CMSMain_Content.ss
```

This is required because Content/Settings/History are three entirely separate CMS controllers,
switched between via a tab strip that's a hardcoded 3-item list in `silverstripe/cms`'s own
template — there's no config-driven extension point to add a 4th tab, only a template override,
and a module can't ship that override "live" itself (project templates always take priority over
a module's). See `docs/templates/CMSMain_Content.ss`'s own header comment for details, and
reconcile it by hand if `silverstripe/cms` changes that template in a future release.

Run `dev/build flush=1` after installing.

## Usage

**Export**: open a page in the CMS, click the **More options** (⋯) menu next to Save/Publish,
then **Export** — a modal lets you choose whether to include referenced files/images and add a
short description, then queues the export as a background job. The page's "Content Export" tab
shows every past export (and the file originally used to *import* the page, if it was created
that way), each with a download link once complete, a delete button, and a "stale" badge once the
page's published content has changed since that export was captured.

**Import**: go to **Add new page**, choose where to create it (top-level or under another page),
then upload a previously exported file instead of picking a page type. Once the file finishes
uploading, a summary appears showing the page type, title, and URL it contains (and a warning if
that page type isn't installed on this site) — a chance to check the file before committing to
anything. Clicking **Create** then runs the import as a background job, and you land on the new
(locked while importing) draft page as soon as it's queued.

Both directions require the `SITETREE_IMPORT_EXPORT` permission, manageable per group under
Security in the CMS.

## Configuration

```yaml
MadeCurious\PagePacker\Serialization\SiteTreeExporter:
  # 'fail' (default): abort with a clear error the moment an unsupported relation shape or a
  # class/field missing on the target site is encountered. 'best_effort': skip what doesn't
  # match and record a warning instead.
  mismatch_behaviour: best_effort
```

## Licence

BSD-3-Clause. See [LICENSE](LICENSE).
