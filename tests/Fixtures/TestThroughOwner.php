<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A deliberately plain DataObject whose only relation of interest is a `many_many_through` —
 * stands in for a real project shape like `Service::$many_many['Applications']`
 * (`ApplicationService` join, carrying a `Status` field) — used to prove
 * RelationSchema::ownedManyManyRelations() correctly distinguishes "unsupported, report it" from
 * "target is excluded, skip it silently" for a through relation.
 */
class TestThroughOwner extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_ThroughOwner';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $many_many = [
        'Targets' => [
            'through' => TestThroughJoin::class,
            'from' => 'Owner',
            'to' => 'Target',
        ],
    ];
}
