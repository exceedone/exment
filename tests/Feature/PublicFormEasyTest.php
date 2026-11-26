<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\CustomFormPublicController;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\PublicFormService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The easy setup of a public form (ISS02942): the page, "Save" (the form made through
 * PublicFormService), the page of the tags, and the buttons to them.
 */
class PublicFormEasyTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    protected function tableName(): string
    {
        return TestDefine::TESTDATA_TABLE_NAME_EDIT;
    }

    protected function open(string $path): string
    {
        return strval($this->get(admin_url($path), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent());
    }

    public function testThePage(): void
    {
        $page = $this->open('formpublic/' . $this->tableName() . '/easy');
        $this->assertStringContainsString('name="columns[]"', $page);
        $this->assertStringContainsString('name="required[]"', $page);
        // plain words
        $this->assertStringNotContainsString('iframe', $page);
        $this->assertStringContainsString('value="text"', $page);
        $this->assertStringContainsString(e(exmtrans('custom_form_public.easy.warning_users')), $page);
        $this->assertStringContainsString('name="notify_user_column"', $page);
        // the user picker of Exment, the user who opens it chosen
        $this->assertStringContainsString('class="form-control easy-notify"', $page);
        $this->assertStringContainsString('<option value="' . \Exment::getUserId() . '" selected>', $page);
        // the name of the form is the name of the table (not the last field)
        $this->assertStringContainsString('name="public_form_view_name" class="form-control" maxlength="40" value="' . e(CustomTable::getEloquent($this->tableName())->table_view_name) . '"', $page);
    }

    public function testSaveAndTheTags(): void
    {
        $before = PublicForm::count();
        $response = $this->post(admin_url('formpublic/' . $this->tableName() . '/easy'), [
            'public_form_view_name' => 'Contact (easy)',
            'columns' => ['email', 'no_such_column'],
            'required' => ['email'],
            'complete_text' => 'Thanks!',
            'notify_user_column' => 'email',
            'notify_admin_users' => [strval(\Exment::getUserId())],
            'active' => '1',
        ]);
        $this->assertSame($before + 1, PublicForm::count());
        /** @var PublicForm $form */
        $form = PublicForm::query()->orderByDesc('id')->firstOrFail();
        $response->assertRedirect(admin_url('formpublic/' . $this->tableName() . '/' . $form->id . '/embed'));
        $this->assertStringStartsWith('exment_easy:', strval($form->getOption(PublicFormService::OWNER_KEY)));
        $this->assertSame('exment_easy:', CustomFormPublicController::$easyOwnerPrefix);
        $this->assertSame('Contact (easy)', $form->public_form_view_name);
        $this->assertSame('Thanks!', $form->getOption('complete_text'));
        $this->assertTrue(boolval($form->active_flg));
        $this->assertNotNull($form->notify_complete_user);
        $this->assertNotNull($form->notify_complete_admin);
        // the column the table requires is on the form though not ticked; "must be filled" on this form only
        $block = $form->custom_form->custom_form_blocks->first();
        $required = \Exceedone\Exment\Model\CustomFormColumn::query()->where('custom_form_block_id', $block->id)->orderBy('order')->get()->mapWithKeys(function ($column) {
            return [strval($column->column_item->name()) => boolval(array_get(jsonToArray($column->options), 'required'))];
        })->all();
        $this->assertSame(['text' => false, 'email' => true], $required);

        $page = $this->open('formpublic/' . $this->tableName() . '/' . $form->id . '/embed');
        $this->assertStringContainsString(e(PublicFormService::embedTags($form)['script']), $page);
        $this->assertStringContainsString(e(PublicFormService::embedTags($form)['iframe']), $page);
        $this->assertStringContainsString(e(exmtrans('custom_form_public.easy.copy')), $page);

        // the edit screen has the button to the tags
        $edit = $this->open('formpublic/' . $this->tableName() . '/' . $form->id . '/edit');
        $this->assertStringContainsString(admin_url('formpublic/' . $this->tableName() . '/' . $form->id . '/embed'), $edit);
    }

    public function testNoName(): void
    {
        $before = PublicForm::count();
        $this->post(admin_url('formpublic/' . $this->tableName() . '/easy'), [
            'public_form_view_name' => '  ',
            'columns' => ['email'],
        ])->assertRedirect(admin_url('formpublic/' . $this->tableName() . '/easy'));
        $this->assertSame($before, PublicForm::count());
    }

    public function testTheColumnsTheTableRequiresAreAlwaysOn(): void
    {
        $this->post(admin_url('formpublic/' . $this->tableName() . '/easy'), [
            'public_form_view_name' => 'Nothing ticked',
            'columns' => [],
        ]);
        /** @var PublicForm $form */
        $form = PublicForm::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('Nothing ticked', $form->public_form_view_name);
        $block = $form->custom_form->custom_form_blocks->first();
        $names = \Exceedone\Exment\Model\CustomFormColumn::query()->where('custom_form_block_id', $block->id)->get()->map(function ($column) {
            return strval($column->column_item->name());
        })->all();
        $this->assertSame(['text'], $names);
    }

    public function testAUserWhoMayOnlyViewOpensARecord(): void
    {
        // the menu of the table (with "Create a web form") on the page of a record, for a user who may
        // only view the data: not an error
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $value = $table->getValueModel()->query()->firstOrFail();
        $this->get(admin_url('data/' . $table->table_name . '/' . $value->id), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get(admin_url('data/' . $table->table_name), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    }

    public function testATagsPageOfAnotherTableIsRefused(): void
    {
        $other = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW);
        $form = PublicForm::query()->where('custom_form_id', CustomForm::getDefault(CustomTable::getEloquent($this->tableName()))->id)->firstOrFail();
        $response = $this->get(admin_url('formpublic/' . $other->table_name . '/' . $form->id . '/embed'), ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertStringNotContainsString('embed.js', strval($response->getContent()));
    }
}
