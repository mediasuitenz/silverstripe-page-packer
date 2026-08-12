<?php

namespace MadeCurious\SiteTreeImportExport\Serialization;

use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;

/**
 * Walks a single DataObject (normally a SiteTree page) into a flat, serializable node graph:
 * every object reachable via "owned" has_many/many_many relations becomes its own node with a
 * sequential local ID, and every has_one relation (tree edges AND lateral/sibling references
 * alike, including polymorphic ones) is captured as a reference to another node's local ID.
 *
 * Two-phase by necessity, not by choice: some has_one relations point sideways at another node
 * discovered later in the walk (e.g. UserForms' EmailRecipient::SendEmailFromField points at a
 * sibling EditableFormField, not an ancestor), so references can't be resolved as we go — every
 * node has to exist first. Phase 1 ({@see discover()}) walks has_many/many_many to build every
 * node and record its has_one targets as raw {class, id} pairs; phase 2 ({@see resolveReferences()})
 * converts those raw pairs into local-ID references now that the full node set is known.
 *
 * File/Image has_one relations are handled separately via {@see AssetBundler} — they're assets to
 * embed/reference, not further graph nodes to recurse into.
 */
class SiteTreeExporter
{
    use Configurable;

    public const MISMATCH_FAIL = 'fail';

    public const MISMATCH_BEST_EFFORT = 'best_effort';

    /**
     * Default mismatch behaviour when a target site is missing a page type/field/relation
     * referenced by an import file — 'fail' (abort with a clear error) or 'best_effort' (skip
     * what doesn't match and warn). Shared by both SiteTreeExportJob (which reads it to decide
     * how strictly to treat unsupported relation shapes during export, e.g.
     * many_many_extraFields) and CMSMainImportToolExtension (which reads it to configure
     * SiteTreeImportJob) — set on this class, not duplicated, so there's one source of truth.
     *
     * @var string
     */
    private static $mismatch_behaviour = self::MISMATCH_FAIL;

    private AssetBundler $assetBundler;

    private bool $includeAssets;

    private string $mismatchBehaviour;

    /** @var array<string, array> localId => node, in discovery order */
    private array $nodes = [];

    /** @var array<string, string> "$class:$id" => localId */
    private array $idMap = [];

    /** @var string[] human-readable warnings accumulated during export */
    private array $warnings = [];

    public function __construct(
        AssetBundler $assetBundler,
        bool $includeAssets = true,
        string $mismatchBehaviour = self::MISMATCH_FAIL
    ) {
        $this->assetBundler = $assetBundler;
        $this->includeAssets = $includeAssets;
        $this->mismatchBehaviour = $mismatchBehaviour;
    }

    /**
     * @return array The full manifest: format, rootLocalId, nodes, assets, warnings.
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
            'nodes' => $this->nodes,
            'assets' => $this->assetBundler->manifest(),
            'warnings' => $this->warnings,
        ];
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
        ];

        foreach (RelationSchema::scalarFields($class) as $fieldName => $spec) {
            $node['fields'][$fieldName] = $record->getField($fieldName);
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

        // has_one relations declared in $owns (e.g. ElementalPageExtension's ElementalArea) are
        // genuine ownership edges, not mere references — the target must be discovered too, or
        // it's wrongly treated as lying outside the exported graph in resolveReferences() below.
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

    /**
     * Reads a has_one relation's raw target without fetching the related object, resolving the
     * polymorphic companion {Relation}Class column where relevant. Returns null if unset.
     */
    /**
     * Uses getComponent() rather than hand-reading the raw {Relation}ID/{Relation}Class columns:
     * for a plain (non-polymorphic) has_one declared against an abstract/base class (e.g.
     * EmailRecipient::SendEmailFromField => EditableFormField::class, where the actual row is
     * always some concrete subclass like EditableTextField), the declared target class is NOT
     * the referenced row's real ClassName — only getComponent() resolves that correctly, the
     * same way it already has to for genuinely polymorphic relations. Using the wrong class here
     * would silently fail to match the idMap key recorded when that same row was discovered via
     * whatever path actually owns it.
     */
    private function rawHasOneReference(DataObject $record, string $relationName): ?array
    {
        $id = (int) $record->getField("{$relationName}ID");

        if (!$id) {
            return null;
        }

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

    /**
     * Phase 2: now that every reachable node has been discovered, convert every raw {class, id}
     * has_one/manyMany reference into either a local-ID reference (the target is part of this
     * export) or an "external" marker (the target lies outside the exported graph — e.g. a
     * has_one added by a custom subclass pointing at a Member or an unrelated page). External
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

    private function flagMismatch(string $message): void
    {
        $this->warnings[] = $message;

        if ($this->mismatchBehaviour === self::MISMATCH_FAIL) {
            throw new RuntimeException($message);
        }
    }
}
