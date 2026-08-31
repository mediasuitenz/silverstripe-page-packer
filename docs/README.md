# PagePacker documentation

PagePacker lets a CMS editor pack a single SilverStripe page — its Elemental blocks,
Userforms definitions, and any referenced files or images (including ones embedded as
TinyMCE shortcodes) — into a downloadable file, and unpack it elsewhere to recreate the
page as a new draft. It's built for moving content between environments (e.g. dev → UAT →
production) entirely through the CMS UI, with no developer or CLI access required.

This `docs/` folder covers two audiences:

- **[Developer guide](developer-guide.md)** — for developers installing, configuring,
  extending, or maintaining this module: architecture, extension points, data model,
  background jobs, and how to run the test suite.
- **[User guide](user-guide.md)** — for CMS editors/authors who want to export a page or
  import one, understand the "stale" badge, and know what permission they need.

For a quick summary and the installation one-liner, see the [module README](../README.md)
in the repository root.
