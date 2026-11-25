<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The public form API (publicformapi/{form_key}/data/...) is called by a visitor who is not
 * logged in; the records are read as the form's user. It answers only what the form needs:
 * the id and label of a record for a select box, and the columns the form copies from a
 * selected record ("changedata") - not the whole record, nor its related records.
 */
class PublicFormApiLimitTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * @return array{0: string, 1: string} [form_key, tableKey of a table the form selects from]
     */
    protected function publicFormAndTable(): array
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_form = CustomForm::getDefault($custom_table);
        $public_form = PublicForm::where('custom_form_id', $custom_form->id)->first();
        $this->assertNotNull($public_form);

        return [$public_form->uuid, TestDefine::TESTDATA_TABLE_NAME_EDIT];
    }

    public function testARecordComesWithoutTheColumnsTheFormDoesNotCopy(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $json = $this->get("publicformapi/{$formKey}/data/{$tableKey}/5")->assertStatus(200)->json();
        $this->assertSame(5, $json['id']);
        $this->assertArrayNotHasKey('index_text', $json['value'] ?? []);
        $this->assertArrayNotHasKey('text', $json['value'] ?? []);
        $this->assertArrayNotHasKey('created_user_id', $json);
    }

    public function testTheColumnTheFormCopiesComesWithTheRecord(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();
        $select_table = CustomTable::getEloquent($tableKey);

        // a form column copies "text" of the selected custom_value_edit record
        $custom_form = CustomForm::getDefault(CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST));
        $select_column = null;
        $copy_to = null;
        foreach ($custom_form->custom_form_blocks as $block) {
            foreach ($block->custom_form_columns as $form_column) {
                $column = $form_column->custom_column_cache;
                if (!$column) {
                    continue;
                }
                if (!$select_column && $column->select_target_table && $column->select_target_table->id == $select_table->id) {
                    $select_column = $column;
                } elseif (!$copy_to && $column->column_type == 'text') {
                    $copy_to = $form_column;
                }
            }
        }
        $this->assertNotNull($select_column, 'the form has a column that selects from ' . $tableKey);
        $this->assertNotNull($copy_to, 'the form has a text column');
        $copy_to->setOption('changedata_target_column_id', $select_column->id);
        $copy_to->setOption('changedata_column_id', CustomColumn::getEloquent('text', $select_table)->id);
        $copy_to->save();

        $json = $this->get("publicformapi/{$formKey}/data/{$tableKey}/5")->assertStatus(200)->json();
        $this->assertArrayHasKey('text', $json['value']);
        $this->assertSame(CustomTable::getEloquent($tableKey)->getValueModel(5)->getValue('text'), $json['value']['text']);
        $this->assertArrayNotHasKey('index_text', $json['value']);
    }

    public function testARecordComesWithoutItsRelatedRecords(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $json = $this->get("publicformapi/{$formKey}/data/{$tableKey}/5?children=1&parents=1")->assertStatus(200)->json();
        $this->assertArrayNotHasKey('children', $json);
        $this->assertArrayNotHasKey('parents', $json);
    }

    public function testTheLabelIsStillGivenForTheSelectBox(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $json = $this->get("publicformapi/{$formKey}/data/{$tableKey}/5?label=1")->assertStatus(200)->json();
        $this->assertSame(5, $json['id']);
        $this->assertNotEmpty($json['label']);
    }

    public function testTheLinkageDoesNotListATableTheFormDoesNotUse(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();
        $other = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NO_PERMISSION);

        $this->get("publicformapi/{$formKey}/data/{$tableKey}/relatedLinkage?" . http_build_query([
            'parent_select_table_id' => CustomTable::getEloquent($tableKey)->id,
            'child_select_table_id' => $other->id,
            'child_column_id' => 1,
            'search_type' => 'select_table',
            'q' => 5,
        ]))->assertStatus(403);
    }
}
