<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Controllers\RecordPackerController;
use MadeCurious\PagePacker\Forms\GridField\GridFieldRecordExportAction;
use MadeCurious\PagePacker\Jobs\RecordExportJob;
use MadeCurious\PagePacker\Security\ImportExportPermissions;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\ValidationException;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Covers the optional, opt-in GridField row action — the one-click alternative to opening a
 * packable record's detail view just to click its own Export button (see PackableExtension).
 */
class GridFieldRecordExportActionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function gridFieldFor(string $modelClass): GridField
    {
        $gridField = GridField::create('Records', 'Records', $modelClass::get());
        // GridField_FormAction::Field() needs a hosting Form to compute its Link() — a bare
        // GridField (as used everywhere else in this test file) has none by default.
        Form::create(RecordPackerController::create(), 'TestForm', FieldList::create($gridField), FieldList::create());

        return $gridField;
    }

    public function testColumnContentRendersForAPackableRecordWithPermission(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestCatalogue::class), $catalogue, 'Actions');

        $this->assertNotNull($content);
        $this->assertStringContainsString('id="action_PagePackerExport' . $catalogue->ID . '"', $content);
    }

    public function testColumnContentIsAbsentForANonPackableRecord(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $product = TestProduct::create(['Title' => 'A widget']);
        $product->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestProduct::class), $product, 'Actions');

        $this->assertNull($content);
    }

    public function testColumnContentIsAbsentWithoutPermission(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestCatalogue::class), $catalogue, 'Actions');

        $this->assertNull($content);
    }

    public function testColumnContentIsAbsentWhileAnExportIsInFlight(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        QueuedJobDescriptor::create([
            'Implementation' => RecordExportJob::class,
            'Signature' => RecordExportJob::signatureForRecord($catalogue),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ])->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestCatalogue::class), $catalogue, 'Actions');

        $this->assertNull($content);
    }

    public function testHandleActionQueuesAnExportForTheRow(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $gridField = $this->gridFieldFor(TestCatalogue::class);
        $component = new GridFieldRecordExportAction();
        $component->handleAction($gridField, 'pagepackerexport', ['RecordID' => $catalogue->ID], []);

        $this->assertTrue(QueuedJobDescriptor::get()->filter([
            'Implementation' => RecordExportJob::class,
            'Signature' => RecordExportJob::signatureForRecord($catalogue),
        ])->exists());
    }

    public function testHandleActionThrowsWithoutPermission(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $gridField = $this->gridFieldFor(TestCatalogue::class);
        $component = new GridFieldRecordExportAction();

        $this->expectException(ValidationException::class);
        $component->handleAction($gridField, 'pagepackerexport', ['RecordID' => $catalogue->ID], []);
    }
}
