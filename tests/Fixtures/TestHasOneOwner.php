<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A plain has_one owner, standing in for a real project shape like
 * `Catalogue::$has_one['Channel']` — a reference to another record that is emphatically NOT owned
 * content (not listed in $owns) and, in the real project, points at per-environment data that can
 * never be meaningfully carried across an export. Used to prove
 * RelationSchema::hasOneRelations() drops an excluded target's relation entirely (and that its
 * raw FK column is still correctly stripped from scalarFields()), rather than exporting it as a
 * reference that's guaranteed to mismatch on import (or worse, leaking through as a raw,
 * unresolved integer).
 */
class TestHasOneOwner extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_HasOneOwner';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $has_one = [
        'Thing' => TestHasOneTarget::class,
    ];
}
