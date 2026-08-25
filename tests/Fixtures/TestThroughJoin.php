<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * The `many_many_through` join class for {@see TestThroughOwner}'s `Targets` relation — carries
 * its own data field (`Note`), mirroring the real-world shape (e.g. `ApplicationService`'s
 * `Status`) that makes a project reach for `through` instead of a plain many_many in the first
 * place.
 */
class TestThroughJoin extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_ThroughJoin';

    private static $db = [
        'Note' => 'Varchar(255)',
    ];

    private static $has_one = [
        'Owner' => TestThroughOwner::class,
        'Target' => TestThroughTarget::class,
    ];
}
