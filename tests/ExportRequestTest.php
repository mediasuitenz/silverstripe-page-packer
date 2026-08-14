<?php

namespace MadeCurious\PagePacker\Tests;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Models\ElementContent;
use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\ContentTimestampWalker;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class ExportRequestTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();

        parent::tearDown();
    }

    public function testNeverPublishedPageIsNeverStale(): void
    {
        $page = SiteTree::create(['Title' => 'Draft only']);
        $page->write();

        // Origin=Export normally always has a real SourceContentTimestamp, but even an
        // (unrealistic) Export-origin entry against a since-unpublished page must not be
        // treated as stale — there's no newer live content to be behind.
        $request = ExportRequest::create([
            'PageID' => $page->ID,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => '2020-01-01 00:00:00',
        ]);
        $request->write();

        $this->assertFalse($request->isStale());
    }

    public function testImportOriginEntryIsStaleOnceThePageIsPublished(): void
    {
        $page = SiteTree::create(['Title' => 'Imported page']);
        $page->write();

        $request = ExportRequest::create([
            'PageID' => $page->ID,
            'Origin' => ExportRequest::ORIGIN_IMPORT,
            // Deliberately left at its default ('') — see ExportRequest::isStale()'s doc comment.
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale before the page has ever been published.');

        $page->publishRecursive();

        $this->assertTrue($request->isStale(), 'Stale as soon as the page is published at all.');
    }

    public function testExportOriginEntryIsStaleOnlyAfterANewerPublish(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $page = SiteTree::create(['Title' => 'Published page']);
        $page->write();
        $page->publishRecursive();

        $request = ExportRequest::create([
            'PageID' => $page->ID,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $page->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing the current live content.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        $page->Title = 'Published page, edited';
        $page->write();
        $page->publishRecursive();

        $this->assertTrue($request->isStale(), 'Stale after a newer publish of the page itself.');
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
            'PageID' => $page->ID,
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

    public function testDeletePermissionIsGatedByTheModulesPermission(): void
    {
        $page = SiteTree::create(['Title' => 'Owner of an export']);
        $page->write();
        $request = ExportRequest::create(['PageID' => $page->ID, 'Origin' => ExportRequest::ORIGIN_EXPORT]);
        $request->write();

        $this->logOut();
        $this->assertFalse(
            (bool) $request->canDelete(),
            'A visitor with no permission at all must not be able to delete an export.'
        );

        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);
        $this->assertTrue(
            (bool) $request->canDelete(),
            'A member with the module\'s permission must be able to delete an export — this is'
            . ' exactly what GridFieldDeleteAction checks before allowing the history'
            . ' GridField\'s per-row delete button to do anything.'
        );
    }

    public function testDeletingAnExportRequestRemovesItFromThePagesHistory(): void
    {
        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $page = SiteTree::create(['Title' => 'Owner of two exports']);
        $page->write();

        $keep = ExportRequest::create(['PageID' => $page->ID, 'Origin' => ExportRequest::ORIGIN_EXPORT]);
        $keep->write();
        $delete = ExportRequest::create(['PageID' => $page->ID, 'Origin' => ExportRequest::ORIGIN_EXPORT]);
        $delete->write();

        $this->assertSame(2, $page->ExportRequests()->count());

        // Mirrors exactly what GridFieldDeleteAction::handleAction() does server-side for the
        // 'deleterecord' action: check canDelete(), then delete() outright (not a mere
        // remove-from-relation) — see the has_many wiring on SiteTreeExportExtension.
        $this->assertTrue((bool) $delete->canDelete());
        $delete->delete();

        $remaining = $page->ExportRequests();
        $this->assertSame(1, $remaining->count());
        $this->assertSame($keep->ID, $remaining->first()->ID);
    }

    public function testDescriptionIsPersistedAndShownInSummary(): void
    {
        $page = SiteTree::create(['Title' => 'Described export']);
        $page->write();

        $request = ExportRequest::create([
            'PageID' => $page->ID,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Description' => 'Before the homepage redesign',
        ]);
        $request->write();

        $reloaded = ExportRequest::get()->byID($request->ID);
        $this->assertSame('Before the homepage redesign', $reloaded->Description);
    }
}
