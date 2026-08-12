<?php

namespace MadeCurious\SiteTreeImportExport\Serialization;

use RuntimeException;
use SilverStripe\ORM\DataObject;

/**
 * Reverses {@see SiteTreeExporter}'s node graph back into real DataObjects. Two-pass for the
 * same reason the exporter is two-pass: has_one references (including lateral/sibling ones, not
 * just tree edges) can point at a node that hasn't been created yet, so every node must exist
 * before any relation is wired up.
 *
 * The root node is special: it's supplied already created (a stub page pre-written by the
 * caller so the queued job/lock has a real ID to work with, then reclassed via
 * `newClassInstance()` to the manifest's resolved target class) rather than created fresh here.
 */
class SiteTreeImporter
{
    private AssetBundler $assetBundler;

    private string $mismatchBehaviour;

    /** @var array<string, DataObject> localId => created record */
    private array $created = [];

    /** @var string[] */
    private array $warnings = [];

    public function __construct(
        AssetBundler $assetBundler,
        string $mismatchBehaviour = SiteTreeExporter::MISMATCH_FAIL
    ) {
        $this->assetBundler = $assetBundler;
        $this->mismatchBehaviour = $mismatchBehaviour;
    }

    /**
     * @param DataObject $root An already-created (written) record — the reclassed stub — that
     *     will receive the manifest's root node's fields/relations.
     * @param array $manifest As produced by {@see SiteTreeExporter::export()}.
     * @return DataObject The same $root instance, fully populated and written.
     */
    public function import(DataObject $root, array $manifest): DataObject
    {
        $this->created = [];
        $this->warnings = [];

        $nodes = $manifest['nodes'] ?? [];
        $rootLocalId = (string) ($manifest['rootLocalId'] ?? '0');

        if (!isset($nodes[$rootLocalId])) {
            throw new RuntimeException('Import file is missing its root node.');
        }

        // Pass 1: create every node (scalar fields only) so every local ID maps to a real record
        // before any relation — including lateral/sibling ones — gets resolved.
        $this->created[$rootLocalId] = $root;
        $this->applyScalarFields($root, $nodes[$rootLocalId]);
        $root->write();

        foreach ($nodes as $localId => $node) {
            // PHP casts numeric-string array keys to int, so $localId here won't reliably match
            // $rootLocalId (a string) or the string localIds stored inside reference arrays
            // (e.g. ['localId' => '2']) by ===. Normalize to string at every use.
            $localId = (string) $localId;

            if ($localId === $rootLocalId) {
                continue;
            }

            $record = $this->createNode($node);

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
     * @return string[] Warnings accumulated during the most recent {@see import()} call.
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function createNode(array $node): ?DataObject
    {
        $class = $node['className'] ?? '';

        if (!is_a($class, DataObject::class, true)) {
            $this->flagMismatch("Page type \"{$class}\" does not exist on this site; its content was skipped.");

            return null;
        }

        /** @var DataObject $record */
        $record = $class::create();
        $this->applyScalarFields($record, $node);
        $record->write();

        return $record;
    }

    private function applyScalarFields(DataObject $record, array $node): void
    {
        $validFields = RelationSchema::scalarFields($record->ClassName);

        foreach ((array) ($node['fields'] ?? []) as $fieldName => $value) {
            if (!array_key_exists($fieldName, $validFields)) {
                $this->flagMismatch(
                    "Field \"{$record->ClassName}.{$fieldName}\" no longer exists on this site; its value was"
                    . ' skipped.'
                );

                continue;
            }

            $record->setField($fieldName, $value);
        }
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

        if ($this->mismatchBehaviour === SiteTreeExporter::MISMATCH_FAIL) {
            throw new RuntimeException($message);
        }
    }
}
