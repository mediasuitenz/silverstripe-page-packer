<?php

namespace MadeCurious\PagePacker\Serialization;

use SilverStripe\ORM\DataObject;

/**
 * Finds the most recent LastEdited across a page and everything it owns — the same "owned"
 * has_one/has_many/many_many relations {@see SiteTreeExporter} walks (ElementalArea and its
 * Elements, Userforms' EditableFormField/EmailRecipient trees, etc.) — for staleness detection.
 *
 * Why this exists rather than reusing the page's own Version number (as
 * {@see \MadeCurious\PagePacker\Model\ExportRequest} originally did): editing and
 * publishing a nested Elemental block bumps THAT BLOCK's own independent version history, not
 * the containing page's — SiteTree, ElementalArea, and BaseElement each have entirely separate
 * Versioned version sequences, linked only by relation, not by any shared counter. A page whose
 * own Version never changed can still have materially different published content. Userforms'
 * EditableFormField/EmailRecipient aren't Versioned at all, so there's no version number to
 * compare there either way — LastEdited is the one signal every DataObject in the graph has in
 * common, versioned or not.
 *
 * Callers are responsible for the Versioned reading mode — call this while
 * `Versioned::withVersionedMode` has the stage set to LIVE if "what's currently published" is
 * the question being asked (matching what SiteTreeExporter itself reads).
 */
class ContentTimestampWalker
{
    /** @var array<string, true> "$class:$id" already visited, guards cycles */
    private array $visited = [];

    public function latestTimestamp(DataObject $record): ?string
    {
        $this->visited = [];

        return $this->walk($record);
    }

    private function walk(DataObject $record): ?string
    {
        if (!$record->exists()) {
            return null;
        }

        $key = "{$record->ClassName}:{$record->ID}";

        if (isset($this->visited[$key])) {
            return null;
        }

        $this->visited[$key] = true;

        $latest = $record->LastEdited;
        $class = $record->ClassName;

        foreach (RelationSchema::ownedHasOneRelations($class) as $relationName => $targetClass) {
            $component = $record->getComponent($relationName);

            if ($component && $component->exists()) {
                $latest = $this->newer($latest, $this->walk($component));
            }
        }

        foreach (RelationSchema::ownedHasManyRelations($class) as $relationName => $targetClass) {
            foreach ($record->{$relationName}() as $child) {
                $latest = $this->newer($latest, $this->walk($child));
            }
        }

        $unsupported = [];

        foreach (RelationSchema::ownedManyManyRelations($class, $unsupported) as $relationName => $targetClass) {
            foreach ($record->{$relationName}() as $child) {
                $latest = $this->newer($latest, $this->walk($child));
            }
        }

        return $latest;
    }

    private function newer(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        // MySQL/ISO "Y-m-d H:i:s" datetime strings compare correctly as plain strings.
        return $a > $b ? $a : $b;
    }
}
