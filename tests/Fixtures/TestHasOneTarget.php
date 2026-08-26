<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Target of {@see TestHasOneOwner}'s `Thing` has_one relation.
 */
class TestHasOneTarget extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_HasOneTarget';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];
}
