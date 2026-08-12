<?php

namespace MadeCurious\SiteTreeImportExport\Extensions;

use MadeCurious\SiteTreeImportExport\Jobs\SiteTreeExportJob;
use MadeCurious\SiteTreeImportExport\Model\ExportRequest;
use MadeCurious\SiteTreeImportExport\Security\ImportExportPermissions;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Handles the "Export"/"Export with Assets" actions added to the page edit form by
 * {@see SiteTreeExportExtension::updateCMSActions()}, mirroring
 * andrewandante/silverstripe-async-publisher's AsyncCMSMain::asyncSave() response pattern
 * (X-Status header + PJAX response negotiator) so the CMS surfaces a normal toast rather than a
 * full page reload.
 */
class CMSMainExportActionExtension extends Extension
{
    private static $allowed_actions = [
        'doExport',
        'doExportWithAssets',
    ];

    public function doExport(array $data, Form $form): HTTPResponse
    {
        return $this->export($data, false);
    }

    public function doExportWithAssets(array $data, Form $form): HTTPResponse
    {
        return $this->export($data, true);
    }

    private function export(array $data, bool $includeAssets): HTTPResponse
    {
        if (!Permission::check(ImportExportPermissions::SITETREE_IMPORT_EXPORT)) {
            return Security::permissionFailure($this->owner);
        }

        $id = (int) ($data['ID'] ?? 0);
        /** @var SiteTree|null $record */
        $record = DataObject::get(SiteTree::class)->byID($id);

        if (!$record || !$record->exists()) {
            return Security::permissionFailure($this->owner);
        }

        if (!$record->canView()) {
            return Security::permissionFailure($this->owner);
        }

        $exportRequest = ExportRequest::create();
        $exportRequest->PageID = $record->ID;
        $exportRequest->MemberID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : null;
        $exportRequest->Status = ExportRequest::STATUS_QUEUED;
        $exportRequest->Origin = ExportRequest::ORIGIN_EXPORT;
        $exportRequest->write();

        $job = new SiteTreeExportJob($record, $includeAssets, $exportRequest->ID);
        QueuedJobService::singleton()->queueJob($job);

        $message = _t(
            self::class . '.QUEUED_FOR_EXPORT',
            "Queued '{title}' for export. Check the Content Export list below shortly.",
            ['title' => $record->Title]
        );

        $this->owner->getResponse()->addHeader('X-Status', rawurlencode($message ?? ''));
        $response = $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
        $response->addHeader('X-Reload', true);

        return $response;
    }
}
