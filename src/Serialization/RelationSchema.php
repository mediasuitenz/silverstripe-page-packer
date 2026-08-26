<?php

namespace MadeCurious\PagePacker\Serialization;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

/**
 * Shared relation/field classification rules used by {@see RecordSerializer}'s export and
 * import directions, so the two stay in lockstep about what counts as a plain scalar
 * field, an asset relation, an in-scope object-graph relation, or something to leave alone.
 */
class RelationSchema
{
    use Configurable;

    /**
     * $db field names that are never exported as plain scalar fields, either because they're
     * managed entirely by the ORM/Versioned on write (ID, ClassName, Created, LastEdited,
     * Version) or because they're the raw column behind a has_one relation, which is handled
     * separately via {@see hasOneRelations()} instead of being dumped as a bare integer.
     *
     * @var string[]
     */
    private static $excluded_system_fields = [
        'ID',
        'ClassName',
        'Created',
        'LastEdited',
        'ParentID',
        'Version',
        'RecordClassName',
    ];

    /**
     * DataObject classes that are never walked into via has_one/has_many/many_many, regardless
     * of the relation name pointing at them.
     *
     * @var string[]
     */
    private static $excluded_relation_classes = [
        'SilverStripe\\UserForms\\Model\\Submission\\SubmittedForm',
        'SilverStripe\\UserForms\\Model\\Submission\\SubmittedFormField',
        'SilverStripe\\UserForms\\Model\\Submission\\SubmittedFileField',
        'MadeCurious\\PagePacker\\Model\\ExportRequest',
    ];

    /**
     * many_many relation NAMES excluded regardless of what class declares them or what they
     * point at — for relations that aren't sensibly identified by target class alone.
     *
     * @var string[]
     */
    private static $excluded_relation_names = [
        'LinkTracking',
        'FileTracking',
    ];

    /**
     * Plain $db fields declared on $class, own + inherited
     *
     * @return array<string, string> fieldName => field spec
     */
    public static function scalarFields(string $class): array
    {
        $schema = DataObject::getSchema();
        $fields = $schema->fieldSpecs($class, DataObjectSchema::DB_ONLY);

        // Strip every has_one FK column using the RAW declared list, not hasOneRelations()'s
        // filtered one — an excluded/asset/tree-position has_one is still a relation column, not
        // a plain field, and must never fall through to being re-applied on import as a bare,
        // unresolved integer (see hasOneRelations()'s own doc comment).
        foreach (array_keys(DataObject::singleton($class)->hasOne()) as $relationName) {
            unset($fields["{$relationName}ID"]);
        }

        foreach ((array) static::config()->get('excluded_system_fields') as $systemField) {
            unset($fields[$systemField]);
        }

        return $fields;
    }

    /**
     * has_one relations declared on $class (own + inherited, incl. via applied extensions),
     * excluding relations to File/Image (those are asset relations) and to any class listed in
     * $excluded_relation_classes — an excluded target is dropped from the relation graph
     * entirely, the same as it is for has_many/many_many, rather than still being captured as a
     * reference that (for a genuinely per-environment target, the reason it was excluded in the
     * first place) can only ever mismatch on import.
     *
     * @return array<string, string> relationName => target class (DataObject::class for
     *     polymorphic relations, resolved per-row at read time)
     */
    public static function hasOneRelations(string $class): array
    {
        $singleton = DataObject::singleton($class);
        $relations = [];

        foreach ($singleton->hasOne() as $name => $targetClass) {
            if (static::isFileRelation($targetClass)) {
                continue;
            }

            if (static::isTreePositionRelation($class, $name)) {
                continue;
            }

            if (static::isExcludedClass($targetClass)) {
                continue;
            }

            $relations[$name] = $targetClass;
        }

        return $relations;
    }

    private static function isTreePositionRelation(string $class, string $relationName): bool
    {
        return $relationName === 'Parent' && is_a($class, SiteTree::class, true);
    }

    /**
     * has_one relations declared on $class that point at File (or a subclass, e.g. Image) —
     * handled by AssetBundler rather than as object-graph nodes.
     *
     * @return string[] relationName => target class
     */
    public static function assetHasOneRelations(string $class): array
    {
        $singleton = DataObject::singleton($class);
        $relations = [];

        foreach ($singleton->hasOne() as $name => $targetClass) {
            if (static::isFileRelation($targetClass)) {
                $relations[$name] = $targetClass;
            }
        }

        return $relations;
    }

    public static function isFileRelation(string $targetClass): bool
    {
        return $targetClass !== DataObject::class
            && class_exists($targetClass)
            && is_a($targetClass, File::class, true);
    }

    /**
     * has_one relations declared on $class (excluding File/Image ones) that are also listed 
     * in the class's $owns config
     *
     * @return array<string, string> relationName => target class
     */
    public static function ownedHasOneRelations(string $class): array
    {
        $singleton = DataObject::singleton($class);
        $owns = (array) $singleton->config()->get('owns');

        return array_intersect_key(static::hasOneRelations($class), array_flip($owns));
    }

    /**
     * has_many relations declared on $class that should be walked as owned content
     *
     * @return array<string, string> relationName => target class
     */
    public static function ownedHasManyRelations(string $class): array
    {
        $singleton = DataObject::singleton($class);

        return array_filter(
            $singleton->hasMany(),
            fn (string $targetClass): bool => !static::isExcludedClass($targetClass)
        );
    }

    /**
     * many_many/belongs_many_many relations declared on $class that should be walked as owned
     * content. Relations using `many_many_extraFields` or a `through` join object are reported
     * back separately (in $unsupported) rather than silently dropped, so callers can honour the
     * fail/best-effort mismatch config rather than losing extra-field/join data quietly — UNLESS
     * a `through` relation's actual target class is itself excluded (see isExcludedClass()), in
     * which case it's skipped silently just like any other excluded relation, rather than being
     * reported as an unconditional failure regardless of what it points at. This matters because
     * a project may have entirely legitimate reasons to exclude a class that happens to only be
     * reachable via a `through` relation — e.g. real per-environment transactional/PII data that
     * a `through` join was used to model in the first place (a workflow-status field on the join
     * row, say) is exactly the shape of content that most needs excluding, not a fatal mismatch.
     *
     * @param array $unsupported Populated with relationName => reason for anything skipped
     * @return array<string, string> relationName => target class
     */
    public static function ownedManyManyRelations(string $class, array &$unsupported = []): array
    {
        $singleton = DataObject::singleton($class);
        $schema = DataObject::getSchema();
        $relations = [];

        foreach ($singleton->manyMany() as $name => $targetClass) {
            if (in_array($name, (array) static::config()->get('excluded_relation_names'), true)) {
                continue;
            }

            if (is_array($targetClass)) {
                // 'through' many_many: resolve the actual target class via the schema (the raw
                // $targetClass here is just ['through' => JoinClass, 'from' => ..., 'to' => ...],
                // not a class name) rather than trusting the raw spec, so an excluded target is
                // recognised as such before falling through to the unconditional "unsupported"
                // report below.
                $resolvedTarget = $schema->manyManyComponent($class, $name)['childClass'] ?? null;

                if ($resolvedTarget && static::isExcludedClass($resolvedTarget)) {
                    continue;
                }

                $unsupported[$name] = 'uses a "through" join object, which this module does not support';

                continue;
            }

            if (static::isExcludedClass($targetClass)) {
                continue;
            }

            $extraFields = $schema->manyManyExtraFieldsForComponent($class, $name);

            if (!empty($extraFields)) {
                $unsupported[$name] = 'declares many_many_extraFields, which this module does not support';

                continue;
            }

            $relations[$name] = $targetClass;
        }

        return $relations;
    }

    public static function isExcludedClass(string $class): bool
    {
        foreach ((array) static::config()->get('excluded_relation_classes') as $excluded) {
            if (class_exists($excluded) && is_a($class, $excluded, true)) {
                return true;
            }
        }

        return false;
    }
}
