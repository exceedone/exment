<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The quick add button beside a select-table field is switched on by the column option
 * quickadd_enabled. The column settings form has to offer that option, or the button can
 * never be turned on from the screen (the option posted without a field is dropped).
 */
class SelectTableQuickAddOptionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testColumnSettingOffersQuickAddForSelectTable(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $response = $this->get(admin_url("column/{$tableKey}/columnTypeHtml") . '?val=select_table&form_type=option&form_uniqueName=claude_quickadd_form')
            ->assertOk();
        $this->assertStringContainsString('quickadd_enabled', (string) $response->json('body'));

        $column = CustomColumn::getEloquent('select_table', $tableKey);
        $response = $this->get(admin_url("column/{$tableKey}/{$column->id}/columnTypeHtml") . '?val=select_table&form_type=option&form_uniqueName=claude_quickadd_form')
            ->assertOk();
        $this->assertStringContainsString('quickadd_enabled', (string) $response->json('body'));
    }

    public function testColumnSettingDoesNotOfferQuickAddForUser(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        // the button only exists for select_table, so the user column does not offer it
        $response = $this->get(admin_url("column/{$tableKey}/columnTypeHtml") . '?val=user&form_type=option&form_uniqueName=claude_quickadd_form')
            ->assertOk();
        $this->assertStringNotContainsString('quickadd_enabled', (string) $response->json('body'));
    }

    public function testQuickAddButtonFollowsTheOption(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $column = CustomColumn::getEloquent('select_table', $tableKey);

        $this->get(admin_url("data/{$tableKey}/create"))
            ->assertOk()
            ->assertDontSee('data-quickadd-url', false);

        // written past the model, so saving the column does not alter the database schema
        DB::table('custom_columns')->where('id', $column->id)->update([
            'options' => json_encode(array_merge($column->options ?? [], ['quickadd_enabled' => '1'])),
        ]);
        System::clearCache();

        $this->get(admin_url("data/{$tableKey}/create"))
            ->assertOk()
            ->assertSee('data-quickadd-url', false);
    }
}
