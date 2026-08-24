<?php

namespace MadeCurious\PagePacker\Tests;

use MadeCurious\PagePacker\Serialization\AssetBundler;
use MadeCurious\PagePacker\Serialization\RecordSerializer;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers what happens importing a file whose manifest references something this site doesn't
 * have — a page type that doesn't exist here, or a field that's been removed/renamed since the
 * file was exported. Both are really the same two mechanisms (RecordSerializer::createNode() and
 * ::applyScalarFields(), both funnelled through flagMismatch()) that also cover "exported from a
 * different version of this site/module" — there's no separate "check the CMS version" step;
 * drift between versions only ever shows up as one of these two structural mismatches. The one
 * exception is an unresolvable ROOT class specifically, which SiteTreeImportJob treats as fatal
 * unconditionally — see SiteTreeImportJobTest for that.
 *
 * Deliberately hand-builds manifests rather than round-tripping a real export: nodes are only
 * ever discovered by RecordSerializer::import() by appearing in the manifest's own `nodes` array
 * (see its pass-1 loop, which iterates every node unconditionally) — no real has_many/many_many
 * relation needs to actually reference a node to exercise the "unknown class" path, so a minimal,
 * explicit manifest is both sufficient and clearer than manufacturing a real relation just to
 * trigger it.
 */
class MismatchHandlingTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function importer(string $mismatchBehaviour): RecordSerializer
    {
        RecordSerializer::config()->set('mismatch_behaviour', $mismatchBehaviour);

        return new RecordSerializer(new AssetBundler(), true);
    }

    private function stub(): SiteTree
    {
        $stub = SiteTree::create();
        $stub->write();

        return $stub;
    }

    public function testUnknownChildNodeClassUnderFailModeThrows(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => SiteTree::class,
                    'fields' => ['Title' => 'A page'],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
                '1' => [
                    // Plausible stand-in for "a block type only some sites have installed" or
                    // "a class that existed in an older version of this module/site" — either
                    // way, RecordSerializer has no way to distinguish those causes, and doesn't
                    // need to.
                    'className' => 'DNADesign\\Elemental\\Models\\ElementNoLongerInstalled',
                    'fields' => [],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist on this site');

        $this->importer(RecordSerializer::MISMATCH_FAIL)->import($this->stub(), $manifest);
    }

    public function testUnknownChildNodeClassUnderBestEffortModeSkipsAndWarns(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => SiteTree::class,
                    'fields' => ['Title' => 'A page'],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
                '1' => [
                    'className' => 'DNADesign\\Elemental\\Models\\ElementNoLongerInstalled',
                    'fields' => [],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $importer = $this->importer(RecordSerializer::MISMATCH_BEST_EFFORT);
        $imported = $importer->import($this->stub(), $manifest);

        // The root page itself still imports successfully — only the unresolvable child was
        // dropped, not the whole file.
        $this->assertSame('A page', $imported->Title);
        $this->assertStringContainsString(
            'ElementNoLongerInstalled" does not exist on this site',
            implode(' ', $importer->warnings())
        );
    }

    public function testUnknownFieldUnderFailModeThrows(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => SiteTree::class,
                    'fields' => [
                        'Title' => 'A page',
                        // Stands in for a field removed/renamed since this file was exported —
                        // e.g. by a newer/older version of this module, a theme's custom SiteTree
                        // extension no longer installed, or a genuinely different CMS version's
                        // own core fields.
                        'SomeFieldRemovedInANewerVersion' => 'a value',
                    ],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer exists on this site');

        $this->importer(RecordSerializer::MISMATCH_FAIL)->import($this->stub(), $manifest);
    }

    public function testUnknownFieldUnderBestEffortModeSkipsAndWarns(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => SiteTree::class,
                    'fields' => [
                        'Title' => 'A page',
                        'SomeFieldRemovedInANewerVersion' => 'a value',
                    ],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $importer = $this->importer(RecordSerializer::MISMATCH_BEST_EFFORT);
        $imported = $importer->import($this->stub(), $manifest);

        // Every OTHER field on the same node still applies — one unknown field doesn't sink the
        // rest of the page's own content.
        $this->assertSame('A page', $imported->Title);
        $this->assertStringContainsString(
            'SomeFieldRemovedInANewerVersion" no longer exists on this site',
            implode(' ', $importer->warnings())
        );
    }
}
