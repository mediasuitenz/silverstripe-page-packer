<?php

namespace MadeCurious\PagePacker\Serialization;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Finds the most recent LastEdited across a page and everything it owns for staleness detection.
 *
 * Callers are responsible for the Versioned reading mode
 */
class ContentTimestampWalker
{
    use Injectable;

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
