<?php

namespace MadeCurious\PagePacker\Serialization;

use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Exports a single DataObject — a SiteTree page, or any other project DataObject with
 * {@see \MadeCurious\PagePacker\Extensions\PackableExtension} applied — into a flat,
 * serializable node graph, and reverses that same graph back into real records on import.
 * Despite living in a module that started out page-only, nothing in here is SiteTree-specific:
 * it walks whatever `$has_one`/`$has_many`/`$many_many` {@see RelationSchema} says belongs to the
 * root record's class, recursively, regardless of what that class is. Both the SiteTree/CMSMain
 * flow ({@see \MadeCurious\PagePacker\Jobs\SiteTreeExportJob}/`SiteTreeImportJob`) and the
 * generic DataObject/GridField flow ({@see \MadeCurious\PagePacker\Jobs\RecordExportJob}/
 * `RecordImportJob`) share this one engine.
 *
 * Export phase 1 ({@see discover()}) walks has_many/many_many to build every node and record its
 * has_one targets as raw {class, id} pairs; phase 2 ({@see resolveReferences()}) converts those
 * raw pairs into local-ID references now that the full node set is known.
 *
 * Import mirrors this; * pass 1 ({@see import()}'s own loop plus {@see createNode()}) creates
 * every node (scalar fields only) so every local ID maps to a real record; pass 2
 * ({@see applyRelations()}) resolves has_one (incl. polymorphic + asset) relations and many_many
 * associations through that local-ID map.
 *
 * File/Image has_one relations are handled separately via {@see AssetBundler}
 */
class RecordSerializer
{
    use Injectable;
    use Configurable;

    /**
     * Mismatch behaviour when a target site is missing a page type/field/relation
     * referenced by an import file 
     * — 'fail' (abort with a clear error)
     * - 'best_effort' (skip what doesn't match and warn)
     *
     * @var string
     */
    private static $mismatch_behaviour = self::MISMATCH_FAIL;

    public const MISMATCH_FAIL = 'fail';
    public const MISMATCH_BEST_EFFORT = 'best_effort';

    private AssetBundler $assetBundler;

    private bool $includeAssets;

    /** @var array<string, array> localId => node, in discovery order (export) */
    private array $nodes = [];

    /** @var array<string, string> "$class:$id" => localId (export) */
    private array $idMap = [];

    /** @var array<string, DataObject> localId => created record (import) */
    private array $created = [];

    /** @var string[] human-readable warnings accumulated during the most recent export/import */
    private array $warnings = [];

    public function __construct(
        AssetBundler $assetBundler,
        bool $includeAssets = true,
    ) {
        $this->assetBundler = $assetBundler;
        $this->includeAssets = $includeAssets;
    }

    /**
     * @return array The full manifest: format, rootLocalId, meta, nodes, assets, warnings.
     */
    public function export(DataObject $record): array
    {
        $this->nodes = [];
        $this->idMap = [];
        $this->warnings = [];

        $rootLocalId = $this->discover($record);
        $this->resolveReferences();

        return [
            'format' => 1,
            'rootLocalId' => $rootLocalId,
            // meta populates the preview on import so we don't have to unzip first
            'meta' => [
                'className' => $record->ClassName,
                'title' => $record->Title,
                'urlSegment' => $record->URLSegment,
            ],
            'nodes' => $this->nodes,
            'assets' => $this->assetBundler->manifest(),
            'warnings' => $this->warnings,
        ];
    }

    public function import(DataObject $root, array $manifest): DataObject
    {
        $this->created = [];
        $this->warnings = [];

        $nodes = $manifest['nodes'] ?? [];
        $rootLocalId = (string) ($manifest['rootLocalId'] ?? '0');

        if (!isset($nodes[$rootLocalId])) {
            throw new RuntimeException('Import file is missing its root node.');
        }

        $assetsManifest = (array) ($manifest['assets'] ?? []);

        // Pass 1: create every node (scalar fields only) so every local ID maps to a real record
        // before any relation — including lateral/sibling ones — gets resolved.
        $this->created[$rootLocalId] = $root;
        $this->applyScalarFields($root, $nodes[$rootLocalId], $assetsManifest);
        $root->write();

        foreach ($nodes as $localId => $node) {
            // Normalize to string at every use.
            $localId = (string) $localId;

            if ($localId === $rootLocalId) {
                continue;
            }

            $record = $this->createNode($node, $assetsManifest);

            if ($record !== null) {
                $this->created[$localId] = $record;
            }
        }

        // Pass 2: now that every node exists, resolve has_one (incl. polymorphic + asset)
        // relations and many_many associations through the local-ID map built above.
        foreach ($nodes as $localId => $node) {
            $localId = (string) $localId;

            if (!isset($this->created[$localId])) {
                continue;
            }

            $this->applyRelations($this->created[$localId], $node, $manifest);
        }

        return $root;
    }

    /**
     * @return string[] Warnings accumulated during the most recent {@see export()} or
     *     {@see import()} call.
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Phase 1: create (or return the existing) node for $record, recursing into every "owned"
     * has_many/many_many relation. Returns the record's local ID.
     */
    private function discover(DataObject $record): string
    {
        $key = $this->key($record);

        if (isset($this->idMap[$key])) {
            return $this->idMap[$key];
        }

        $localId = (string) count($this->idMap);
        $this->idMap[$key] = $localId;

        $class = $record->ClassName;
        $node = [
            'className' => $class,
            'fields' => [],
            'hasOne' => [],
            'assetHasOne' => [],
            'manyMany' => [],
            'shortcodeAssets' => [],
        ];

        foreach (RelationSchema::scalarFields($class) as $fieldName => $spec) {
            $value = $record->getField($fieldName);
            $node['fields'][$fieldName] = $value;

            if (is_string($value) && $value !== '' && ContentShortcodeScanner::isHtmlFieldSpec($spec)) {
                $fieldAssetKeys = $this->captureShortcodeAssets($value);

                if ($fieldAssetKeys) {
                    $node['shortcodeAssets'][$fieldName] = $fieldAssetKeys;
                }
            }
        }

        foreach (RelationSchema::assetHasOneRelations($class) as $relationName => $targetClass) {
            $node['assetHasOne'][$relationName] = $this->captureAssetReference($record, $relationName);
        }

        $hasOneRelations = RelationSchema::hasOneRelations($class);

        foreach ($hasOneRelations as $relationName => $targetClass) {
            $node['hasOne'][$relationName] = $this->rawHasOneReference($record, $relationName);
        }

        // Record node now (before recursing) so cyclical/self-referential graphs terminate via
        // the idMap lookup above rather than recursing forever.
        $this->nodes[$localId] = $node;

        // has_one relations declared in $owns must be discovered too
        $ownedHasOne = array_intersect_key($hasOneRelations, RelationSchema::ownedHasOneRelations($class));

        foreach ($ownedHasOne as $relationName => $targetClass) {
            $component = $record->getComponent($relationName);

            if ($component && $component->exists()) {
                $this->discover($component);
            }
        }

        foreach (RelationSchema::ownedHasManyRelations($class) as $relationName => $targetClass) {
            foreach ($record->{$relationName}() as $child) {
                $this->discover($child);
            }
        }

        $unsupported = [];

        foreach (RelationSchema::ownedManyManyRelations($class, $unsupported) as $relationName => $targetClass) {
            $childRefs = [];

            foreach ($record->{$relationName}() as $child) {
                $childLocalId = $this->discover($child);
                $childRefs[] = ['class' => $child->ClassName, 'id' => (int) $child->ID, '_localId' => $childLocalId];
            }

            $this->nodes[$localId]['manyMany'][$relationName] = $childRefs;
        }

        foreach ($unsupported as $relationName => $reason) {
            $this->flagMismatch("Relation \"{$class}.{$relationName}\" {$reason}; it was not exported.");
        }

        return $localId;
    }

    private function rawHasOneReference(DataObject $record, string $relationName): ?array
    {
        $id = (int) $record->getField("{$relationName}ID");

        if (!$id) {
            return null;
        }

        // uses getComponent() to capture any has_one declared against an abstract/base class
        $component = $record->getComponent($relationName);

        if (!$component || !$component->exists()) {
            return null;
        }

        return ['class' => $component->ClassName, 'id' => (int) $component->ID];
    }

    /**
     * Always records which file was referenced (hash/filename/mime), even when "include assets"
     * is off, so an importer can still attempt to match an existing file with the same content
     * on the target site; only embeds the actual bytes into the export zip when includeAssets
     * is true.
     */
    private function captureAssetReference(DataObject $record, string $relationName): ?string
    {
        $file = $record->getComponent($relationName);

        if (!$file || !$file->exists()) {
            return null;
        }

        return $this->assetBundler->captureAsset($file, $this->includeAssets);
    }

    private function captureShortcodeAssets(string $htmlValue): array
    {
        $scanner = new ContentShortcodeScanner();
        $assetKeys = [];

        foreach ($scanner->extractReferences($htmlValue) as $reference) {
            $file = File::get()->byID($reference['id']);

            if (!$file || !$file->exists()) {
                continue;
            }

            $assetKeys[$reference['id']] = $this->assetBundler->captureAsset($file, $this->includeAssets);
        }

        return $assetKeys;
    }

    /**
     * Phase 2: now that every reachable node has been discovered, convert every raw {class, id}
     * has_one/manyMany reference into either a local-ID reference or an "external" marker. External
     * references are dropped on import; we keep enough information to warn about what was lost.
     */
    private function resolveReferences(): void
    {
        foreach ($this->nodes as $localId => &$node) {
            foreach ($node['hasOne'] as $relationName => $raw) {
                $node['hasOne'][$relationName] = $this->resolveRawReference($raw, $node['className'], $relationName);
            }

            foreach ($node['manyMany'] as $relationName => $rawList) {
                $resolved = [];

                foreach ($rawList as $raw) {
                    $ref = $this->resolveRawReference(
                        ['class' => $raw['class'], 'id' => $raw['id']],
                        $node['className'],
                        $relationName
                    );

                    if ($ref !== null) {
                        $resolved[] = $ref;
                    }
                }

                $node['manyMany'][$relationName] = $resolved;
            }
        }
    }

    private function resolveRawReference(?array $raw, string $ownerClass, string $relationName): ?array
    {
        if ($raw === null) {
            return null;
        }

        $key = "{$raw['class']}:{$raw['id']}";

        if (isset($this->idMap[$key])) {
            return ['localId' => $this->idMap[$key], 'class' => $raw['class']];
        }

        $this->flagMismatch(
            "\"{$ownerClass}.{$relationName}\" referenced a {$raw['class']} (#{$raw['id']}) outside"
            . ' the exported page; that reference will be dropped on import.'
        );

        return ['external' => true, 'class' => $raw['class']];
    }

    private function key(DataObject $record): string
    {
        if (!$record->exists()) {
            throw new RuntimeException('Cannot export an unsaved ' . $record->ClassName);
        }

        return "{$record->ClassName}:{$record->ID}";
    }

    private function createNode(array $node, array $assetsManifest): ?DataObject
    {
        $class = $node['className'] ?? '';

        if (!is_a($class, DataObject::class, true)) {
            $this->flagMismatch("Class \"{$class}\" does not exist on this site; its content was skipped.");

            return null;
        }

        /** @var DataObject $record */
        $record = $class::create();
        $this->applyScalarFields($record, $node, $assetsManifest);
        $record->write();

        return $record;
    }

    /**
     * Sets every plain scalar field, additionally rewriting any shortcode-embedded File/Image
     * references a HTML field's raw value might contain to point at the new asset
     */
    private function applyScalarFields(DataObject $record, array $node, array $assetsManifest): void
    {
        $validFields = RelationSchema::scalarFields($record->ClassName);
        $scanner = new ContentShortcodeScanner();

        foreach ((array) ($node['fields'] ?? []) as $fieldName => $value) {
            if (!array_key_exists($fieldName, $validFields)) {
                $this->flagMismatch(
                    "Field \"{$record->ClassName}.{$fieldName}\" no longer exists on this site; its value was"
                    . ' skipped.'
                );

                continue;
            }

            $shortcodeAssetKeys = (array) ($node['shortcodeAssets'][$fieldName] ?? []);

            if ($shortcodeAssetKeys && is_string($value)) {
                $value = $scanner->rewriteReferences(
                    $value,
                    $this->materializeShortcodeAssets($shortcodeAssetKeys, $assetsManifest, $record, $fieldName)
                );
            }

            $record->setField($fieldName, $value);
        }
    }

    /**
     * @param array<int, string> $shortcodeAssetKeys oldFileID => assetKey
     * @return array<int, int> oldFileID => newFileID, omitting any that couldn't be recreated
     */
    private function materializeShortcodeAssets(
        array $shortcodeAssetKeys,
        array $assetsManifest,
        DataObject $record,
        string $fieldName
    ): array {
        $idMap = [];

        foreach ($shortcodeAssetKeys as $oldFileID => $assetKey) {
            $asset = $this->assetBundler->materializeAsset($assetKey, $assetsManifest);

            if ($asset === null) {
                $this->flagMismatch(
                    "Could not recreate a file referenced inside \"{$record->ClassName}.{$fieldName}\"; that"
                    . ' shortcode was left pointing at its original (likely nonexistent) ID.'
                );

                continue;
            }

            $idMap[(int) $oldFileID] = (int) $asset->ID;
        }

        return $idMap;
    }

    private function applyRelations(DataObject $record, array $node, array $manifest): void
    {
        $changed = false;

        foreach ((array) ($node['assetHasOne'] ?? []) as $relationName => $assetKey) {
            if ($assetKey === null) {
                continue;
            }

            $asset = $this->assetBundler->materializeAsset($assetKey, $manifest['assets'] ?? []);

            if ($asset === null) {
                $this->flagMismatch(
                    "Could not recreate the file referenced by \"{$record->ClassName}.{$relationName}\"; that"
                    . ' relation was left empty.'
                );

                continue;
            }

            $record->setComponent($relationName, $asset);
            $changed = true;
        }

        foreach ((array) ($node['hasOne'] ?? []) as $relationName => $ref) {
            $target = $this->resolveReference($ref);
            $record->setComponent($relationName, $target);
            $changed = true;
        }

        foreach ((array) ($node['manyMany'] ?? []) as $relationName => $refs) {
            $list = $record->{$relationName}();
            $list->removeAll();

            foreach ((array) $refs as $ref) {
                $target = $this->resolveReference($ref);

                if ($target !== null) {
                    $list->add($target);
                }
            }
        }

        if ($changed) {
            $record->write();
        }
    }

    private function resolveReference(?array $ref): ?DataObject
    {
        if ($ref === null || !empty($ref['external'])) {
            return null;
        }

        $localId = (string) ($ref['localId'] ?? '');

        return $this->created[$localId] ?? null;
    }

    private function flagMismatch(string $message): void
    {
        $this->warnings[] = $message;
        $mismatchBehaviour = static::config()->get('mismatch_behaviour');
        if ($mismatchBehaviour === self::MISMATCH_FAIL) {
            throw new RuntimeException($message);
        }
    }
}
