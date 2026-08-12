<?php

namespace MadeCurious\SiteTreeImportExport\Serialization;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

/**
 * Shared relation/field classification rules used by both {@see SiteTreeExporter} and
 * {@see SiteTreeImporter}, so the two stay in lockstep about what counts as a plain scalar
 * field, an asset relation, an in-scope object-graph relation, or something to leave alone.
 */
class RelationSchema
{
    use Configurable;

    /**
     * $db field names that are never exported as plain scalar fields, either because they're
     * managed entirely by the ORM/Versioned on write (ID, ClassName, Created, LastEdited,
     * Version) or because they're the raw FK column behind a has_one relation, which is handled
     * separately via {@see hasOneRelations()} instead of being dumped as a bare integer.
     *
     * @var string[]
     */
    private static $excluded_system_fields = [
        'ID',
        'ClassName',
        'Created',
        'LastEdited',
        // Excluded here too, not only via hasOneRelations() skipping "Parent" (see
        // isTreePositionRelation()) — scalarFields() only strips a has_one's raw FK column by
        // cross-referencing hasOneRelations()'s output, so excluding "Parent" there on its own
        // just made ParentID fall through and get treated as an ordinary scalar field instead
        // of being excluded — reported live as the source page's original parent leaking into
        // freshly imported pages regardless of which parent the editor actually chose.
        'ParentID',
        'Version',
        'RecordClassName',
    ];

    /**
     * DataObject classes that are never walked into via has_many/many_many, regardless of the
     * relation name pointing at them. These are visitor/runtime DATA, not CONFIG — e.g.
     * silverstripe/userforms' own UserForm::$scaffold_cms_fields_settings['ignoreRelations']
     * excludes 'Submissions' for the same reason. Matching by class (not relation name) means a
     * subclass renaming the relation still gets it excluded.
     *
     * @var string[]
     */
    private static $excluded_relation_classes = [
        'SilverStripe\\UserForms\\Model\\Submission\\SubmittedForm',
        'SilverStripe\\UserForms\\Model\\Submission\\SubmittedFormField',
        'SilverStripe\\UserForms\\Model\\Submission\\SubmittedFileField',
    ];

    /**
     * many_many relation NAMES excluded regardless of what class declares them or what they
     * point at — for relations that aren't sensibly identified by target class alone.
     * LinkTracking/FileTracking are SilverStripe core's own automatic content-link-scanning
     * bookkeeping (SiteTreeLinkTracking/FileLinkTracking, applied to every SiteTree/DataObject
     * respectively via linktracking.yml/filetracking.yml) — derived data recomputed by the
     * framework itself whenever content is saved (via syncLinkTracking()), not authored content,
     * and declared as many_many_through besides (see ownedManyManyRelations()'s handling of
     * "through" relations) so there's nothing meaningful to export here regardless.
     *
     * @var string[]
     */
    private static $excluded_relation_names = [
        'LinkTracking',
        'FileTracking',
    ];

    /**
     * Plain (non-relation, non-system) $db fields declared on $class, own + inherited, with the
     * raw FK column for every has_one relation stripped out (those are handled by
     * {@see hasOneRelations}, not dumped as bare integers).
     *
     * @return array<string, string> fieldName => field spec
     */
    public static function scalarFields(string $class): array
    {
        $schema = DataObject::getSchema();
        $fields = $schema->fieldSpecs($class, DataObjectSchema::DB_ONLY);

        foreach (array_keys(static::hasOneRelations($class)) as $relationName) {
            unset($fields["{$relationName}ID"]);
        }

        foreach ((array) static::config()->get('excluded_system_fields') as $systemField) {
            unset($fields[$systemField]);
        }

        return $fields;
    }

    /**
     * has_one relations declared on $class (own + inherited, incl. via applied extensions),
     * excluding relations to File/Image (those are asset relations, see {@see isFileRelation})
     * and excluding SiteTree's own tree-position `Parent` has_one (see below).
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

            $relations[$name] = $targetClass;
        }

        return $relations;
    }

    /**
     * Every SiteTree (sub)class automatically gets a `Parent` has_one via the Hierarchy trait
     * (`'has_one' => ['Parent' => $class]`, framework/src/ORM/Hierarchy/Hierarchy.php) — this is
     * WHERE THE PAGE LIVES IN THE TREE, not page content, and the import flow already has its
     * own explicit mechanism for that (the "top-level or under another page" step, which sets
     * the stub's ParentID before the importer ever runs). Treating it like ordinary content
     * would capture the source page's OWN original parent at export time and then blindly
     * overwrite whatever parent the editor chose during import with it. This is deliberately
     * scoped to SiteTree specifically (via is_a, so it covers every page subclass) rather than
     * excluding any has_one merely NAMED "Parent" — Elemental's BaseElement.Parent and
     * Userforms' EditableFormField.Parent are both genuine owned-content relations that must
     * NOT be excluded, and they happen to reuse the same relation name on unrelated classes.
     */
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
     * has_one relations declared on $class (excluding File/Image ones — see hasOneRelations())
     * that are ALSO listed in the class's $owns config — SilverStripe's own signal (used by
     * RecursivePublishable::findOwned() for publish/duplicate cascading) for "this points at
     * something the record exclusively owns and is responsible for the lifecycle of", e.g.
     * ElementalPageExtension's ElementalArea. These must be recursed into during export
     * discovery like a tree edge, not just resolved as a reference — unlike an arbitrary has_one
     * (say, to a Member), most of which are NOT ownership and must NOT be recursed into.
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
     * has_many relations declared on $class that should be walked as owned content, i.e. every
     * has_many except ones pointing at an excluded (visitor-submission-style) class.
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
     * fail/best-effort mismatch config rather than losing extra-field/join data quietly.
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
                // 'through' many_many: the array form's 'through' key names a join DataObject.
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
