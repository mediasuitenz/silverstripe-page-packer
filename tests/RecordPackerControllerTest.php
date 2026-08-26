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
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
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

    /**
     * Regression test: this used to trust the submitting request's Referer header as the sole
     * source of "where to redirect back to", which a Referrer-Policy, browser privacy setting,
     * or extension can omit entirely on an otherwise ordinary same-origin form POST — silently
     * sending every export back to the site root instead of the CMS page it was triggered from.
     * The BackURL hidden field (populated by the caller at modal-build time, not at submission
     * time) must take priority over Referer.
     */
    public function testDoExportRedirectsToBackURLRatherThanTheSiteRoot(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $backURL = Controller::join_links(
            Director::absoluteBaseURL(),
            'admin/lead-agencies/Catalogue/EditForm/field/Catalogue/item/' . $catalogue->ID . '/edit'
        );

        $controller = $this->controller();
        // Deliberately no Referer header set on the request at all.
        $response = $controller->doExport([
            'RecordClassName' => TestCatalogue::class,
            'RecordID' => $catalogue->ID,
            'BackURL' => $backURL,
        ], $controller->ExportModalForm());

        $this->assertStringStartsWith($backURL, $response->getHeader('Location'));
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

    /**
     * The GridField/DataObject equivalent of "Add new page" landing you straight on the new
     * page's own edit view — see GridFieldRecordImportButton/ImportModalForm's own comments on
     * why GridFieldLink (not BackURL, which is only the grid's *list* URL) is what makes this
     * possible here.
     */
    public function testDoImportRedirectsIntoTheNewStubsOwnEditViewWhenGridFieldLinkIsGiven(): void
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
        $form->Fields()->dataFieldByName('ImportFile')->setItems(ArrayList::create([$file]));

        $gridFieldLink = Controller::join_links(
            Director::absoluteBaseURL(),
            'admin/lead-agencies/Catalogue/EditForm/field/Catalogue'
        );

        $response = $controller->doImport([
            'RecordClassName' => TestCatalogue::class,
            'GridFieldLink' => $gridFieldLink,
        ], $form);

        $stub = TestCatalogue::get()->sort('ID', 'DESC')->first();

        $this->assertSame('Importing…', $stub->Title, 'The new stub gets a placeholder Title while the job runs.');

        $location = $response->getHeader('Location');
        $this->assertStringStartsWith(
            Controller::join_links($gridFieldLink, 'item', $stub->ID),
            $location,
            'Must redirect straight into the new stub\'s own edit view, not back to the grid list.'
        );
        $this->assertStringContainsString('page-packer-toast-title=Import', $location);
    }

    public function testDoImportFallsBackToBackURLWithoutAGridFieldLink(): void
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
        $form->Fields()->dataFieldByName('ImportFile')->setItems(ArrayList::create([$file]));

        $backURL = Controller::join_links(Director::absoluteBaseURL(), 'admin/lead-agencies');

        // Deliberately no GridFieldLink — e.g. an older cached copy of the button's markup.
        $response = $controller->doImport([
            'RecordClassName' => TestCatalogue::class,
            'BackURL' => $backURL,
        ], $form);

        $this->assertStringStartsWith($backURL, $response->getHeader('Location'));
    }
}
