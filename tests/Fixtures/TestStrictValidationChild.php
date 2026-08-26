<?php

namespace MadeCurious\PagePacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;

/**
 * A deliberately strict child DataObject whose validate() requires its 'Parent' has_one to
 * already be set — mirroring a real project model (e.g. the marketplace app's own
 * FieldConditional, which requires a Field relation) that trips RecordSerializer::import()'s
 * pass 1: every node is written with scalar fields only, before ANY has_one relation is applied
 * (that happens in pass 2, once every node's local ID exists), so a validate() rule like this
 * one always fails on that first write unless the importer explicitly relaxes validation for it.
 */
class TestStrictValidationChild extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_StrictValidationChild';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $has_one = [
        'Parent' => TestStrictValidationParent::class,
    ];

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->ParentID) {
            $result->addError('Parent is required.');
        }

        return $result;
    }
}
