<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Owns {@see TestStrictValidationChild} via has_many, standing in for a real project shape like
 * Catalogue's owned FieldConditional children — used to prove import survives a child whose
 * validate() requires a has_one relation to already be set (see that class's own doc comment).
 */
class TestStrictValidationParent extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_StrictValidationParent';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $has_many = [
        'Children' => TestStrictValidationChild::class,
    ];
}
