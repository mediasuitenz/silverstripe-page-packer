<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\RecordSerializer;
use MadeCurious\PagePacker\Tests\Fixtures\TestStrictValidationChild;
use MadeCurious\PagePacker\Tests\Fixtures\TestStrictValidationParent;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers RecordSerializer::import()'s two-pass write: pass 1 creates every node with scalar
 * fields only (no has_one relations set yet, by design — see import()'s own doc comment), pass 2
 * applies every relation once all nodes exist. A project's validate() may legitimately require a
 * has_one to already be set (TestStrictValidationChild here; FieldConditional requiring its Field
 * relation is the real shape that surfaced this in the marketplace app itself), which pass 1 can
 * never satisfy regardless of node creation order — so pass 1's writes must run with validation
 * relaxed, relying on pass 2's write (once relations are actually in place) to validate for real.
 */
class ImportValidationOrderingTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestStrictValidationParent::class,
        TestStrictValidationChild::class,
    ];

    public function testImportSucceedsWhenAChildsValidateRequiresItsParentRelation(): void
    {
        $parent = TestStrictValidationParent::create(['Title' => 'Original parent']);
        $parent->write();

        $child = TestStrictValidationChild::create(['Title' => 'Original child']);
        $child->ParentID = $parent->ID;
        $child->write();

        $exporter = new RecordSerializer(new AssetBundler(), true);
        $manifest = $exporter->export($parent);

        $stub = TestStrictValidationParent::create();
        $importer = new RecordSerializer(new AssetBundler());
        $imported = $importer->import($stub, $manifest);

        $importedChildren = $imported->Children();
        $this->assertSame(1, $importedChildren->count());
        $importedChild = $importedChildren->first();
        $this->assertSame('Original child', $importedChild->Title);
        $this->assertSame(
            $imported->ID,
            $importedChild->ParentID,
            "The recreated child's required Parent relation must point at the NEW parent."
        );
    }

    /**
     * Regression guard for the fix itself: pass 1 relaxing validation must not leak past import()
     * — a genuinely invalid child (never gets its required relation satisfied even after pass 2,
     * because the reference was external/unresolvable) should still be caught by pass 2's real,
     * validated write rather than silently persisting.
     */
    public function testValidationIsStillEnforcedOnceRelationsAreApplied(): void
    {
        $parent = TestStrictValidationParent::create(['Title' => 'Parent']);
        $parent->write();

        $child = TestStrictValidationChild::create(['Title' => 'Child']);
        $child->ParentID = $parent->ID;
        $child->write();

        $exporter = new RecordSerializer(new AssetBundler(), true);
        $manifest = $exporter->export($parent);

        // Sabotage the manifest so the child's Parent reference can never resolve on import,
        // simulating a relation that's still missing after pass 2 (e.g. an external reference).
        foreach ($manifest['nodes'] as $localId => &$node) {
            if ($node['className'] === TestStrictValidationChild::class) {
                $node['hasOne']['Parent'] = ['external' => true, 'class' => TestStrictValidationParent::class];
            }
        }
        unset($node);

        $stub = TestStrictValidationParent::create();
        $importer = new RecordSerializer(new AssetBundler());

        $this->expectException(\SilverStripe\ORM\ValidationException::class);
        $importer->import($stub, $manifest);
    }
}
