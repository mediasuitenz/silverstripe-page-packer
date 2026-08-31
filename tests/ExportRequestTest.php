<?php

namespace MadeCurious\PagePacker\Tests;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Models\ElementContent;
use MadeCurious\PagePacker\Security\SiteTreeImportExportPermissions;
use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Serialization\ContentTimestampWalker;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Covers the page-tree-specific slice of ExportRequest's behaviour: permission gating on
 * SITETREE_IMPORT_EXPORT (kept independently grantable from
 * madecurious/silverstripe-record-packer's own RECORD_IMPORT_EXPORT — see
 * SiteTreeImportExportPermissions' own doc comment), and staleness against a page with a nested
 * Elemental block. Everything else (the shared isStale()/history mechanics against a versioned or
 * unversioned record, the Status/QueuedJobDescriptor link) is covered generically by
 * madecurious/silverstripe-record-packer's own ExportRequestTest — this one deliberately doesn't
 * repeat it.
 */
class ExportRequestTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();

        parent::tearDown();
    }

    /**
     * The actual bug this mechanism exists to catch: publishing a nested Elemental block bumps
     * that block's own independent version history, not the page's — a page whose own Version
     * (or, before this fix, LastEdited alone) never changed could still have materially
     * different published content underneath it.
     */
    public function testStaleAfterPublishingANestedBlockEvenWhenThePageItselfIsUntouched(): void
    {
        if (!class_exists('Page') || !\Page::has_extension(ElementalPageExtension::class)) {
            $this->markTestSkipped('Page does not have ElementalPageExtension applied in this environment.');
        }

        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $page = \Page::create(['Title' => 'Page with a block']);
        $page->write();

        $area = ElementalArea::create();
        $area->write();
        $page->ElementalAreaID = $area->ID;
        $page->write();

        $element = ElementContent::create(['HTML' => '<p>Original</p>']);
        $element->ParentID = $area->ID;
        $element->write();
        $page->publishRecursive();

        $request = ExportRequest::create([
            'RecordID' => $page->ID,
            'RecordClass' => get_class($page),
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => (new ContentTimestampWalker())->latestTimestamp($page),
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        // Edit and publish ONLY the block — the page record itself is never written again.
        $element->HTML = '<p>Updated</p>';
        $element->write();
        $element->publishRecursive();

        $this->assertTrue(
            $request->isStale(),
            'Must be stale after publishing a change to a nested block, even though the page'
            . ' record itself was never re-saved.'
        );
    }

    public function testDeletePermissionIsGatedByTheSiteTreePermissionForAPage(): void
    {
        $page = SiteTree::create(['Title' => 'Owner of an export']);
        $page->write();
        $request = ExportRequest::create([
            'RecordID' => $page->ID,
            'RecordClass' => SiteTree::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
        ]);
        $request->write();

        $this->logOut();
        $this->assertFalse(
            (bool) $request->canDelete(),
            'A visitor with no permission at all must not be able to delete an export.'
        );

        $this->logInWithPermission(SiteTreeImportExportPermissions::SITETREE_IMPORT_EXPORT);
        $this->assertTrue(
            (bool) $request->canDelete(),
            'A member with the module\'s permission must be able to delete an export — this is'
            . ' exactly what GridFieldDeleteAction checks before allowing the history'
            . ' GridField\'s per-row delete button to do anything.'
        );
    }

    public function testDeletingAnExportRequestRemovesItFromThePagesHistory(): void
    {
        $this->logInWithPermission(SiteTreeImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $page = SiteTree::create(['Title' => 'Owner of two exports']);
        $page->write();

        $keep = ExportRequest::create([
            'RecordID' => $page->ID,
            'RecordClass' => SiteTree::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
        ]);
        $keep->write();
        $delete = ExportRequest::create([
            'RecordID' => $page->ID,
            'RecordClass' => SiteTree::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
        ]);
        $delete->write();

        $this->assertSame(2, $page->ExportRequests()->count());

        $this->assertTrue((bool) $delete->canDelete());
        $delete->delete();

        $remaining = $page->ExportRequests();
        $this->assertSame(1, $remaining->count());
        $this->assertSame($keep->ID, $remaining->first()->ID);
    }
}
