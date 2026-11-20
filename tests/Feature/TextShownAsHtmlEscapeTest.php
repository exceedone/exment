<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Text that users write and that a page prints as HTML is escaped:
 * - the user information in the header (system setting "header_user_info"),
 * - the label of a record selected on a public form (its confirm page).
 */
class TextShownAsHtmlEscapeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const MARK = '<b class="escape-check">x</b>';
    protected const ESCAPED = '&lt;b class=&quot;escape-check&quot;&gt;';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testTheUserInformationInTheHeaderIsText(): void
    {
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $column = CustomColumn::getEloquent('user_name', SystemTableName::USER);
        $this->assertNotNull($column);
        System::header_user_info([$column->id]);
        System::clearCache();

        $base = $user->base_user;
        $base->setValue('user_name', static::MARK)->save();
        $user->refresh();

        $html = $user->getHeaderInfo();
        $this->assertStringNotContainsString('<b class="escape-check">', $html);
        $this->assertStringContainsString(static::ESCAPED, $html);
    }

    public function testTheLabelOfASelectedRecordOnAPublicFormIsText(): void
    {
        $form_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $public_form = PublicForm::where('custom_form_id', CustomForm::getDefault($form_table)->id)->first();
        $this->assertNotNull($public_form);

        $select_column = CustomColumn::where('custom_table_id', $form_table->id)
            ->where('column_type', ColumnType::SELECT_TABLE)
            ->get()
            ->first(function ($column) {
                return $column->select_target_table && $column->select_target_table->table_name == TestDefine::TESTDATA_TABLE_NAME_EDIT;
            });
        $this->assertNotNull($select_column);

        // the label of custom_value_edit records is its "text" column (or the id)
        $target_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $target = $target_table->getValueModel(5);
        foreach ($target_table->custom_columns as $column) {
            if ($column->column_type == ColumnType::TEXT) {
                $target->setValue($column->column_name, static::MARK);
            }
        }
        $target->save();
        $this->assertStringContainsString('escape-check', $target_table->getValueModel(5)->getLabel());

        $record = $form_table->getValueModel();
        $record->setValue($select_column->column_name, 5);

        $item = $select_column->column_item->options(['public_form' => $public_form])->setCustomValue($record);
        $html = (string)$item->html();

        $this->assertStringNotContainsString('<b class="escape-check">', $html);
        $this->assertStringContainsString(static::ESCAPED, $html);
    }
}
