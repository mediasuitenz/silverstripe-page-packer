<?php

namespace MadeCurious\PagePacker\Tests;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Models\ElementContent;
use MadeCurious\RecordPacker\Serialization\AssetBundler;
use MadeCurious\RecordPacker\Serialization\RecordSerializer;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;
use SilverStripe\UserForms\Model\Recipient\EmailRecipientCondition;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;
use SilverStripe\UserForms\Model\UserDefinedForm;

/**
 * Round-trip tests for RecordSerializer against SiteTree/Elemental/Userforms-shaped content
 * specifically — the page-tree-only edge cases that don't apply to a plain DataObject (see
 * madecurious/silverstripe-record-packer's own RecordSerializerTest, which covers everything
 * RecordSerializer does generically, plus GenericDataObjectRoundTripTest for the basic round trip
 * itself).
 */
class RecordSerializerTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function export(SiteTree $page, bool $includeAssets = true): array
    {
        $exporter = new RecordSerializer(new AssetBundler(), $includeAssets);

        return $exporter->export($page);
    }

    /**
     * Mirrors the production flow exactly: a bare SiteTree stub is written first (with whatever
     * parent the editor chose in the Add-New-Page UI — see CMSMainAddFormImportExtension), then
     * reclassed via newClassInstance() to the manifest's target class, then handed to the
     * importer — see SiteTreeImportJob::doImport().
     */
    private function importAsNewPage(array $manifest, int $chosenParentID = 0): SiteTree
    {
        $stub = SiteTree::create(['ParentID' => $chosenParentID]);
        $stub->write();

        $targetClass = $manifest['nodes'][$manifest['rootLocalId']]['className'];
        $record = $stub->newClassInstance($targetClass);

        $importer = new RecordSerializer(new AssetBundler());

        return $importer->import($record, $manifest);
    }

    /**
     * Regression test: SiteTree's own `Parent` has_one (added to every page subclass by the
     * Hierarchy trait) is tree POSITION, not page content — see RelationSchema's
     * structural_has_one_relations config, populated for SiteTree/Parent by this module's own
     * _config/extensions.yml. A naive generic has_one walk would capture the source page's own
     * original parent at export time and then overwrite whatever parent the editor chose during
     * import with it — reported live as "the page still went into the top level" despite
     * explicitly choosing to create it under another page.
     */
    public function testImportPreservesTheChosenParentNotTheSourcesOriginalParent(): void
    {
        $originalParent = SiteTree::create(['Title' => 'Original parent']);
        $originalParent->write();

        $chosenParent = SiteTree::create(['Title' => 'Chosen parent']);
        $chosenParent->write();

        $source = SiteTree::create(['Title' => 'Child page', 'ParentID' => $originalParent->ID]);
        $source->write();

        $manifest = $this->export($source);
        $imported = $this->importAsNewPage($manifest, $chosenParent->ID);

        $this->assertSame(
            $chosenParent->ID,
            $imported->ParentID,
            'Import must place the new page under the parent chosen in the Add-New-Page UI.'
        );
        $this->assertNotSame(
            $originalParent->ID,
            $imported->ParentID,
            "The source page's own original parent must not leak into the import."
        );
    }

    public function testElementalContentBlockRoundTrip(): void
    {
        // Uses the global \Page class rather than bare SiteTree: this dev site's own
        // app/_config/mysite.yml applies ElementalPageExtension to Page specifically (not
        // SiteTree — see CMSMainAddFormImportExtension's stub-class doc comment for why that
        // distinction matters). Applying the extension dynamically at runtime instead would be
        // too late for SapphireTest's temp test database, whose schema is built once at boot
        // from the statically-configured extension list.
        if (!class_exists('Page') || !\Page::has_extension(ElementalPageExtension::class)) {
            $this->markTestSkipped('Page does not have ElementalPageExtension applied in this environment.');
        }

        $page = \Page::create(['Title' => 'Page with blocks']);
        $page->write();

        // ElementalPageExtension declares the has_one but doesn't auto-create/write it on
        // write() — that's done by the CMS edit form, which isn't in play for this test.
        $area = ElementalArea::create();
        $area->write();
        $page->ElementalAreaID = $area->ID;
        $page->write();

        $element = ElementContent::create(['HTML' => '<p>Block content</p>']);
        $element->ParentID = $area->ID;
        $element->write();

        $manifest = $this->export($page);
        $imported = $this->importAsNewPage($manifest);

        $elements = $imported->ElementalArea()->Elements();
        $this->assertSame(1, $elements->count());
        $this->assertSame('<p>Block content</p>', $elements->first()->HTML);
        $this->assertNotSame($element->ID, $elements->first()->ID, 'Blocks must be recreated, not reused.');
    }

    /**
     * Exercises both edge cases the architecture stress-test identified as breaking a naive
     * recursive walk: EditableFormField/EmailRecipient's polymorphic has_one (Parent/Form ->
     * DataObject::class, resolved per-row via a companion {Relation}Class column), and
     * EmailRecipient/EmailRecipientCondition's LATERAL has_one references to sibling
     * EditableFormField rows — SendEmailFromField and ConditionField are both fixed-class
     * has_ones (not polymorphic), but they're still not tree edges: they point sideways at a
     * sibling row discovered elsewhere in the same walk, which is exactly the shape a naive
     * "resolve has_one as we go" walk can't handle (the sibling may not exist yet).
     */
    public function testUserDefinedFormPolymorphicAndLateralRelationsRoundTrip(): void
    {
        if (!class_exists(UserDefinedForm::class)) {
            $this->markTestSkipped('silverstripe/userforms is not installed.');
        }

        $form = UserDefinedForm::create(['Title' => 'Contact us']);
        $form->write();

        $field = EditableTextField::create(['Title' => 'Email address', 'Name' => 'EmailAddress']);
        $field->ParentID = $form->ID;
        $field->ParentClass = UserDefinedForm::class;
        $field->write();

        $recipient = EmailRecipient::create([
            'EmailAddress' => 'someone@example.com',
            'EmailFrom' => 'forms@example.com',
        ]);
        $recipient->FormID = $form->ID;
        $recipient->FormClass = UserDefinedForm::class;
        // Lateral reference: points at a sibling EditableFormField, not an ancestor/descendant.
        $recipient->SendEmailFromFieldID = $field->ID;
        $recipient->write();

        $condition = EmailRecipientCondition::create(['ConditionOption' => 'IsBlank']);
        $condition->ParentID = $recipient->ID;
        $condition->ConditionFieldID = $field->ID;
        $condition->write();

        $manifest = $this->export($form);
        /** @var UserDefinedForm $imported */
        $imported = $this->importAsNewPage($manifest);

        $importedFields = $imported->Fields();
        $this->assertSame(1, $importedFields->count());
        $importedField = $importedFields->first();
        $this->assertSame('Email address', $importedField->Title);
        // The polymorphic has_one resolved correctly, including its companion Class column.
        $this->assertSame($imported->ID, $importedField->ParentID);
        $this->assertSame(UserDefinedForm::class, $importedField->ParentClass);

        $importedRecipients = $imported->EmailRecipients();
        $this->assertSame(1, $importedRecipients->count());
        $importedRecipient = $importedRecipients->first();
        $this->assertSame('someone@example.com', $importedRecipient->EmailAddress);
        // The lateral reference re-resolved to the NEW EditableTextField's ID, not the old one.
        $this->assertSame($importedField->ID, $importedRecipient->SendEmailFromFieldID);
        $this->assertNotSame($field->ID, $importedRecipient->SendEmailFromFieldID);

        $importedConditions = $importedRecipient->CustomRules();
        $this->assertSame(1, $importedConditions->count());
        $this->assertSame($importedField->ID, $importedConditions->first()->ConditionFieldID);
    }

    public function testVisitorSubmissionDataIsNeverExported(): void
    {
        if (!class_exists(UserDefinedForm::class)) {
            $this->markTestSkipped('silverstripe/userforms is not installed.');
        }

        $form = UserDefinedForm::create(['Title' => 'Survey']);
        $form->write();

        $field = EditableTextField::create(['Title' => 'Name', 'Name' => 'Name']);
        $field->ParentID = $form->ID;
        $field->ParentClass = UserDefinedForm::class;
        $field->write();

        $submission = SubmittedForm::create();
        $submission->ParentID = $form->ID;
        $submission->ParentClass = UserDefinedForm::class;
        $submission->write();

        $submittedField = SubmittedFormField::create(['Title' => 'Name', 'Value' => 'A real visitor']);
        $submittedField->ParentID = $submission->ID;
        $submittedField->write();

        $manifest = $this->export($form);

        foreach ($manifest['nodes'] as $node) {
            $this->assertNotSame(SubmittedForm::class, $node['className']);
            $this->assertNotSame(SubmittedFormField::class, $node['className']);
        }

        $imported = $this->importAsNewPage($manifest);
        $this->assertSame(0, SubmittedForm::get()->filter('ParentID', $imported->ID)->count());
    }
}
