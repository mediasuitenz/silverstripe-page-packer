<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Owned child of {@see TestCatalogue}, standing in for e.g. a real "Product"/"Service" belonging
 * to a Catalogue — exercises the owned has_many walk against a genuinely non-page object graph.
 */
class TestProduct extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_Product';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Price' => 'Int',
    ];

    private static $has_one = [
        'Catalogue' => TestCatalogue::class,
    ];
}
