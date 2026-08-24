<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\SiteTreeSerializer;
use MadeCurious\PagePacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\PagePacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;

/**
 * Proves the core packing engine — SiteTreeSerializer, despite its name (see its own class doc)
 * — genuinely works against an arbitrary, unversioned, non-SiteTree DataObject and its owned
 * has_many children, not just pages. This is the load-bearing assumption behind
 * PackableExtension/RecordExportJob/RecordImportJob: they add no serialization logic of their
 * own, they only wire this same engine up to a different (GridField-shaped) CMS surface.
 */
class GenericDataObjectRoundTripTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function export(TestCatalogue $catalogue): array
    {
        $exporter = new SiteTreeSerializer(new AssetBundler(), true);

        return $exporter->export($catalogue);
    }

    public function testPlainDataObjectRoundTrip(): void
    {
        $catalogue = TestCatalogue::create([
            'Title' => 'Home & Garden',
            'Description' => 'Everything for the home and garden.',
        ]);
        $catalogue->write();

        $manifest = $this->export($catalogue);

        $stub = TestCatalogue::create();
        $importer = new SiteTreeSerializer(new AssetBundler());
        $imported = $importer->import($stub, $manifest);

        $this->assertSame('Home & Garden', $imported->Title);
        $this->assertSame('Everything for the home and garden.', $imported->Description);
        $this->assertSame(TestCatalogue::class, $imported->ClassName);
    }

    /**
     * Mirrors SiteTreeSerializerTest's Elemental/Userforms owned-has_many coverage, against a
     * genuinely unrelated has_many (TestCatalogue -> TestProduct) with no tree/page semantics
     * at all — the exact shape a project DataObject like a real "Catalogue -> Products/Services"
     * relation has.
     */
    public function testOwnedHasManyChildrenAreExportedAndRecreated(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Original catalogue']);
        $catalogue->write();

        $product = TestProduct::create(['Title' => 'Widget', 'Price' => 1000]);
        $product->CatalogueID = $catalogue->ID;
        $product->write();

        $manifest = $this->export($catalogue);
        $stub = TestCatalogue::create();
        $importer = new SiteTreeSerializer(new AssetBundler());
        $imported = $importer->import($stub, $manifest);

        $this->assertNotSame($catalogue->ID, $imported->ID, 'Import must always create a new record.');

        $importedProducts = $imported->Products();
        $this->assertSame(1, $importedProducts->count());
        $importedProduct = $importedProducts->first();
        $this->assertSame('Widget', $importedProduct->Title);
        $this->assertSame(1000, $importedProduct->Price);
        $this->assertNotSame($product->ID, $importedProduct->ID, 'The child must be recreated, not reused.');
        $this->assertSame(
            $imported->ID,
            $importedProduct->CatalogueID,
            "The recreated child's has_one must point at the NEW catalogue, not the original."
        );
    }
}
