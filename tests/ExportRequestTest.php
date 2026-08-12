<?php

namespace MadeCurious\SiteTreeImportExport\Tests;

use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class ExportRequestTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testNeverPublishedPageIsNeverStale(): void
    {
        $page = SiteTree::create(['Title' => 'Draft only']);
        $page->write();

        // Origin=Export normally always has a real SourceLiveVersion, but even an
        // (unrealistic) Export-origin entry against a since-unpublished page must not be
        // treated as stale — there's no newer live content to be behind.
        $request = ExportRequest::create([
            'PageID' => $page->ID,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceLiveVersion' => 1,
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
            // Deliberately left at its default (0) — see ExportRequest::isStale()'s doc comment.
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale before the page has ever been published.');

        $page->publishRecursive();

        $this->assertTrue($request->isStale(), 'Stale as soon as the page is published at all.');
    }

    public function testExportOriginEntryIsStaleOnlyAfterANewerPublish(): void
    {
        $page = SiteTree::create(['Title' => 'Published page']);
        $page->write();
        $page->publishRecursive();

        $liveVersion = Versioned::get_versionnumber_by_stage(SiteTree::class, Versioned::LIVE, $page->ID);

        $request = ExportRequest::create([
            'PageID' => $page->ID,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceLiveVersion' => $liveVersion,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing the current live version.');

        $page->Title = 'Published page, edited';
        $page->write();
        $page->publishRecursive();

        $this->assertTrue($request->isStale(), 'Stale after a newer publish.');
    }
}
