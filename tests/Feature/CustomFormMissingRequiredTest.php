<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ExistingTableFormBuilder;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The form setting page tells the required columns a form does not have (ISS00374):
 * data entered with that form is saved with them empty.
 *
 * Making a table creates its data table (DDL), which a test transaction cannot
 * roll back, so the test deletes the table it made.
 */
class CustomFormMissingRequiredTest extends FeatureTestBase
{
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        CustomTable::where('table_name', 'form_missing_required')->first()?->delete();
        parent::tearDown();
    }

    public function testAFormWithoutARequiredColumnIsWarned(): void
    {
        $table = BlankFormBuilder::build(BlankFormBuilder::normalize([
            'table_view_name' => 'missing required',
            'table_name' => 'form_missing_required',
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => '件名<b>', 'column_name' => 'title', 'required' => true],
                ['type' => ColumnType::TEXT, 'label' => 'メモ', 'column_name' => 'memo'],
            ],
        ]));
        System::clearRequestSession();
        $memo = $table->custom_columns()->where('column_name', 'memo')->firstOrFail();
        $partial = ExistingTableFormBuilder::build([
            'table_view_name' => 'memo only',
            'table_id' => intval($table->id),
            'fields' => [['column_id' => intval($memo->id)]],
        ]);
        $default = CustomForm::where('custom_table_id', $table->id)->where('default_flg', true)->firstOrFail();

        System::clearRequestSession();
        $page = $this->get(admin_urls('form', 'form_missing_required', $partial->id, 'edit'));
        $page->assertOk();
        $page->assertSee(exmtrans('custom_form.missing_required_title'));
        // the name of the column is escaped
        $page->assertSee(sprintf(exmtrans('custom_form.name_quote'), '件名&lt;b&gt;'), false);
        $page->assertDontSee('件名<b>', false);

        // the default form has every column: no warning
        System::clearRequestSession();
        $this->get(admin_urls('form', 'form_missing_required', $default->id, 'edit'))
            ->assertOk()
            ->assertDontSee(exmtrans('custom_form.missing_required_title'));
    }

    /**
     * A form may ask to check the required columns it does not have: a new record made with it is
     * then not saved while they are empty. Off (the default), it is saved with them empty.
     */
    public function testAFormMayCheckTheRequiredColumnsItDoesNotHave(): void
    {
        $table = BlankFormBuilder::build(BlankFormBuilder::normalize([
            'table_view_name' => 'missing required',
            'table_name' => 'form_missing_required',
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => '件名', 'column_name' => 'title', 'required' => true],
                ['type' => ColumnType::TEXT, 'label' => 'メモ', 'column_name' => 'memo'],
            ],
        ]));
        System::clearRequestSession();
        $memo = $table->custom_columns()->where('column_name', 'memo')->firstOrFail();
        $partial = ExistingTableFormBuilder::build([
            'table_view_name' => 'memo only',
            'table_id' => intval($table->id),
            'fields' => [['column_id' => intval($memo->id)]],
        ]);
        $this->assertSame(['title'], $partial->missingRequiredColumns()->pluck('column_name')->all());
        $this->assertFalse($partial->check_missing_required, 'off by default');

        $count = function () use ($table) {
            System::clearRequestSession();
            return CustomTable::getEloquent($table->id)->getValueModel()->newQuery()->count();
        };
        $post = function (string $memoText) use ($partial) {
            System::clearRequestSession();
            return $this->post(admin_urls('data', 'form_missing_required'), ['formid' => $partial->suuid, 'value' => ['memo' => $memoText]]);
        };

        // off: saved with the title empty, as before
        $before = $count();
        $post('saved');
        $this->assertSame($before + 1, $count());

        // on: not saved, and said why
        $partial->check_missing_required = true;
        $partial->save();
        $saved = CustomForm::find($partial->id);
        $this->assertInstanceOf(CustomForm::class, $saved);
        $this->assertTrue($saved->check_missing_required);
        $before = $count();
        $response = $post('refused');
        $this->assertSame($before, $count(), 'not saved while the required column is empty');
        $response->assertSessionHasErrors('value.title');
    }
}
