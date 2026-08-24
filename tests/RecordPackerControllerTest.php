<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Jobs\RecordImportJob;
use MadeCurious\PagePacker\Model\ExportRequest;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\RecordSerializer;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\ArrayList;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * The generic-DataObject equivalent of the page tree's doExport()/importPreview() coverage —
 * against RecordPackerController, the standalone controller PackableExtension and
 * GridFieldRecordImportButton both post to (see that class's own doc comment for why it's
 * separate from CMSMain).
 */
class RecordPackerControllerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function controller(): RecordPackerController
    {
        $controller = RecordPackerController::create();
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        $controller->setRequest($request);

        return $controller;
    }

    public function testDoExportQueuesAJobAndCreatesAnExportRequest(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $controller = $this->controller();
        $form = $controller->ExportModalForm();

        $response = $controller->doExport([
            'RecordClassName' => TestCatalogue::class,
            'RecordID' => $catalogue->ID,
            'IncludeAssets' => '1',
            'Description' => 'Before the redesign',
        ], $form);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('page-packer-toast=', $response->getHeader('Location'));

        $exportRequest = ExportRequest::get()->filter([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
        ])->first();
        $this->assertNotNull($exportRequest);
        $this->assertSame('Before the redesign', $exportRequest->Description);
        $this->assertSame(ExportRequest::STATUS_QUEUED, $exportRequest->Status);

        $this->assertTrue(QueuedJobDescriptor::get()->filter([
            'Implementation' => RecordExportJob::class,
            'Signature' => RecordExportJob::signatureForRecord($catalogue),
        ])->exists());
    }

    public function testDoExportRejectsANonPackableClass(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $product = TestProduct::create(['Title' => 'A widget']);
        $product->write();

        $controller = $this->controller();
        $response = $controller->doExport([
            'RecordClassName' => TestProduct::class,
            'RecordID' => $product->ID,
        ], $controller->ExportModalForm());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDoExportRequiresPermission(): void
    {
        // Logged in, but with a permission other than RECORD_IMPORT_EXPORT — Security::
        // permissionFailure() redirects an anonymous visitor to the login form (302) rather
        // than 403ing outright, so an authenticated-but-forbidden member is the precise case
        // that isolates PackableExtension's own permission gate.
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $controller = $this->controller();
        $response = $controller->doExport([
            'RecordClassName' => TestCatalogue::class,
            'RecordID' => $catalogue->ID,
        ], $controller->ExportModalForm());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testImportPreviewMarksAPackableClassAsClassExists(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue', 'Description' => 'Desc']);
        $catalogue->write();

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $exporter = new RecordSerializer($assetBundler, true);
        $manifest = $exporter->export($catalogue);
        $file = $assetBundler->writeZip($manifest, 'catalogue-export.zip');

        $controller = $this->controller();
        $request = new HTTPRequest('GET', '/', ['FileID' => $file->ID]);
        $request->setSession(new Session([]));

        $response = $controller->importPreview($request);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(TestCatalogue::class, $data['className']);
        $this->assertTrue($data['classExists']);
    }

    public function testImportPreviewMarksANonPackableClassAsNotClassExists(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $product = TestProduct::create(['Title' => 'A widget']);
        $product->write();

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $exporter = new RecordSerializer($assetBundler, true);
        $manifest = $exporter->export($product);
        $file = $assetBundler->writeZip($manifest, 'product-export.zip');

        $controller = $this->controller();
        $request = new HTTPRequest('GET', '/', ['FileID' => $file->ID]);
        $request->setSession(new Session([]));

        $response = $controller->importPreview($request);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(TestProduct::class, $data['className']);
        $this->assertFalse(
            $data['classExists'],
            'TestProduct is installed but has no PackableExtension applied, so it is not importable on its own.'
        );
    }

    public function testDoImportCreatesAStubAndQueuesAJob(): void
    {
        $this->logInWithPermission(['ADMIN', ImportExportPermissions::RECORD_IMPORT_EXPORT]);

        $source = TestCatalogue::create(['Title' => 'Source catalogue']);
        $source->write();

        $assetBundler = Injector::inst()->create(AssetBundler::class);
        $exporter = new RecordSerializer($assetBundler, true);
        $manifest = $exporter->export($source);
        $file = $assetBundler->writeZip($manifest, 'catalogue-export.zip');

        $controller = $this->controller();
        $form = $controller->ImportModalForm();
        $uploadField = $form->Fields()->dataFieldByName('ImportFile');
        $uploadField->setItems(ArrayList::create([$file]));

        $response = $controller->doImport(['RecordClassName' => TestCatalogue::class], $form);

        $this->assertSame(302, $response->getStatusCode());

        $this->assertTrue(QueuedJobDescriptor::get()->filter([
            'Implementation' => RecordImportJob::class,
        ])->exists());
    }
}
