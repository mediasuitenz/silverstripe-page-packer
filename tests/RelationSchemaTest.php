<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Serialization\RelationSchema;
use MadeCurious\PagePacker\Tests\Fixtures\TestHasOneOwner;
use MadeCurious\PagePacker\Tests\Fixtures\TestHasOneTarget;
use MadeCurious\PagePacker\Tests\Fixtures\TestThroughJoin;
use MadeCurious\PagePacker\Tests\Fixtures\TestThroughOwner;
use MadeCurious\PagePacker\Tests\Fixtures\TestThroughTarget;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers RelationSchema::ownedManyManyRelations()'s handling of `many_many_through` relations
 * specifically. The module deliberately doesn't support round-tripping a through join's own
 * content (see that method's own doc comment) — but an EXCLUDED target class must still be
 * skipped silently, exactly like any other excluded relation, rather than reported as an
 * unconditional failure regardless of what the relation actually points at. This matters because
 * a project may exclude a class for precisely the reason it reached for `through` in the first
 * place — e.g. real per-environment transactional data with its own workflow-status field on the
 * join row (see `Service::$many_many['Applications']`/`ApplicationService` in the marketplace
 * app, which is exactly this shape).
 */
class RelationSchemaTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestThroughOwner::class,
        TestThroughTarget::class,
        TestThroughJoin::class,
        TestHasOneOwner::class,
        TestHasOneTarget::class,
    ];

    public function testThroughRelationIsFlaggedUnsupportedByDefault(): void
    {
        $unsupported = [];
        $relations = RelationSchema::ownedManyManyRelations(TestThroughOwner::class, $unsupported);

        $this->assertArrayNotHasKey('Targets', $relations);
        $this->assertArrayHasKey('Targets', $unsupported);
        $this->assertStringContainsString('"through" join object', $unsupported['Targets']);
    }

    public function testThroughRelationIsSilentlySkippedWhenItsTargetIsExcluded(): void
    {
        // SapphireTest wraps each test in Config::nest()/unnest(), so this reverts automatically.
        RelationSchema::config()->set('excluded_relation_classes', [TestThroughTarget::class]);

        $unsupported = [];
        $relations = RelationSchema::ownedManyManyRelations(TestThroughOwner::class, $unsupported);

        $this->assertArrayNotHasKey('Targets', $relations);
        $this->assertArrayNotHasKey(
            'Targets',
            $unsupported,
            'An excluded target must be skipped silently, not reported as an unsupported mismatch.'
        );
    }

    public function testThroughRelationIsStillFlaggedWhenSomeOtherClassIsExcluded(): void
    {
        // Regression guard for the fix itself: excluding an UNRELATED class must not accidentally
        // suppress the "unsupported" report for a through relation whose target isn't excluded.
        RelationSchema::config()->set('excluded_relation_classes', [TestThroughOwner::class]);

        $unsupported = [];
        RelationSchema::ownedManyManyRelations(TestThroughOwner::class, $unsupported);

        $this->assertArrayHasKey('Targets', $unsupported);
    }

    public function testHasOneRelationIsIncludedByDefault(): void
    {
        $relations = RelationSchema::hasOneRelations(TestHasOneOwner::class);

        $this->assertArrayHasKey('Thing', $relations);
    }

    public function testHasOneRelationIsDroppedWhenItsTargetIsExcluded(): void
    {
        // Mirrors the real project shape: Catalogue::$has_one['Channel'] points at per-
        // environment data, so it's listed in excluded_relation_classes rather than being
        // captured as a reference that can only ever mismatch on import.
        RelationSchema::config()->set('excluded_relation_classes', [TestHasOneTarget::class]);

        $relations = RelationSchema::hasOneRelations(TestHasOneOwner::class);

        $this->assertArrayNotHasKey('Thing', $relations);
    }

    public function testExcludedHasOneTargetsFkColumnIsStillStrippedFromScalarFields(): void
    {
        // Regression guard: an excluded has_one's FK column must never leak through as a plain
        // scalar field — that would re-apply the source environment's raw row ID on import with
        // no validation at all, which is worse than the mismatch this exclusion exists to avoid.
        RelationSchema::config()->set('excluded_relation_classes', [TestHasOneTarget::class]);

        $fields = RelationSchema::scalarFields(TestHasOneOwner::class);

        $this->assertArrayNotHasKey('ThingID', $fields);
    }
}
