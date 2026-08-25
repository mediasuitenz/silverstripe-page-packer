<?php

namespace MadeCurious\PagePacker\Support;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;

/**
 * Captures "the URL actually being viewed right now" as an absolute URL — used to populate the
 * `BackURL` hidden field on {@see \MadeCurious\PagePacker\Controllers\RecordPackerController}'s
 * Export/Import modal forms at the moment those forms are built (during the record's own edit
 * form/GridField render), rather than relying on the later form-submission request's `Referer`
 * header. A `Referrer-Policy`, browser privacy setting, or extension can omit or strip that
 * header on an otherwise ordinary same-origin form POST, which — before this existed — silently
 * sent every export/import back to the site root instead of back into the CMS.
 */
final class CurrentBackUrl
{
    public static function capture(): string
    {
        $controller = Controller::curr();

        if (!$controller) {
            return '';
        }

        return Director::absoluteURL($controller->getRequest()->getURL(true));
    }
}
