<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\SiteTreeExporter;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers importPreview() — a read-only endpoint the Add-New-Page screen's JS calls once a file
 * finishes uploading (see CMSMainAddFormImportExtension's class doc), letting an editor see what
 * page is actually in a zip before clicking "Create" and committing to the import.
 */
class CMSMainAddFormImportExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function request(int $fileID): HTTPRequest
    {
        $request = new HTTPRequest('GET', '/', ['FileID' => $fileID]);
        $request->setSession(new Session([]));

        return $request;
    }

    private function controller(): CMSMain
    {
        $controller = CMSMain::create();
        $controller->setRequest($this->request(0));

        return $controller;
    }

    public function testValidExportZipReturnsItsMeta(): void
    {
        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $page = SiteTree::create(['Title' => 'A page', 'URLSegment' => 'a-page']);
        $page->write();

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $exporter = new SiteTreeExporter($assetBundler, true, SiteTreeExporter::MISMATCH_FAIL);
        $manifest = $exporter->export($page);
        $file = $assetBundler->writeZip($manifest, 'preview-test.zip');

        $response = $this->controller()->importPreview($this->request($file->ID));
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(SiteTree::class, $data['className']);
        $this->assertSame('A page', $data['title']);
        $this->assertSame('a-page', $data['urlSegment']);
        $this->assertTrue($data['classExists'], 'SiteTree is always installed, so this must be true.');
    }

    /**
     * The single most useful thing to surface before an import actually runs: whether the
     * exported page type is even installed on this site at all.
     */
    public function testUnknownClassIsReportedButNotFatal(): void
    {
        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'meta' => ['className' => 'SomeClassThatDoesNotExist', 'title' => 'A page', 'urlSegment' => 'a-page'],
            'nodes' => ['0' => ['className' => 'SomeClassThatDoesNotExist', 'fields' => [], 'hasOne' => [], 'assetHasOne' => [], 'manyMany' => [], 'shortcodeAssets' => []]],
            'assets' => [],
            'warnings' => [],
        ];
        $file = $assetBundler->writeZip($manifest, 'bogus-class.zip');

        $response = $this->controller()->importPreview($this->request($file->ID));
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('SomeClassThatDoesNotExist', $data['className']);
        $this->assertFalse($data['classExists']);
    }

    public function testFileThatIsNotAValidZipReturnsAnError(): void
    {
        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $file = File::create();
        $file->setFromString('this is not a zip', 'not-a-zip.zip');
        $file->write();

        $response = $this->controller()->importPreview($this->request($file->ID));
        $data = json_decode($response->getBody(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('error', $data);
    }

    public function testMissingFileReturnsAnError(): void
    {
        $this->logInWithPermission(ImportExportPermissions::SITETREE_IMPORT_EXPORT);

        $response = $this->controller()->importPreview($this->request(999999));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testWithoutPermissionRequestIsDenied(): void
    {
        $this->logOut();

        $page = SiteTree::create(['Title' => 'A page']);
        $page->write();

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $exporter = new SiteTreeExporter($assetBundler, true, SiteTreeExporter::MISMATCH_FAIL);
        $manifest = $exporter->export($page);
        $file = $assetBundler->writeZip($manifest, 'preview-test.zip');

        $response = $this->controller()->importPreview($this->request($file->ID));

        $this->assertSame(403, $response->getStatusCode());
    }
}
