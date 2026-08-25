<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * The "to" side of {@see TestThroughOwner}'s many_many_through relation.
 */
class TestThroughTarget extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_ThroughTarget';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];
}
