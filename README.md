# PagePacker

Pack up a single SilverStripe page (with its Elemental blocks, Userforms definitions, referenced
files/images — including ones embedded as TinyMCE shortcodes) into a downloadable file, and unpack
it elsewhere to create a new page — entirely through the CMS UI, no developer/CLI access needed.
Built for moving content between separate environments (e.g. dev → UAT → prod).

## Requirements

This branch (`cms5`) targets SilverStripe CMS 5. For CMS 6, see the `cms6` branch.

* PHP ^8.1
* silverstripe/framework ^5.4
* silverstripe/cms ^5.4
* silverstripe/versioned ^2.4
* symbiote/silverstripe-queuedjobs ^5.3
* madecurious/silverstripe-record-packer ^0.1.0

Optional, detected automatically if installed:

* dnadesign/silverstripe-elemental
* silverstripe/userforms
* dnadesign/silverstripe-elemental-userforms

## Installation

```
composer require madecurious/silverstripe-page-packer
```

## Usage

See the [full documention](docs/README.md) for more information, but in short:

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

For further information, review the documentation in the [core module](https://github.com/mediasuitenz/silverstripe-record-packer)

## Gotchas

- The Content-Export tab is added via a `CMSMain_Content.ss` override. If you are overriding this
template already, or another module is overriding it, it won't apply - you'll need to create an
override for it in your own project templates (`app/templates/SilverStripe/CMS/Controllers/Includes/CMSMain_Content.ss`) and combine all the changes yourself.
- This also means if the core template changes in any way, this might fall down.

## Licence

BSD-3-Clause. See [LICENSE](LICENSE).
