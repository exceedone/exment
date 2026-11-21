<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ScreenDefinition;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Studio, phase 4 (ISS00231): a button of a screen sets an item when it is pressed.
 *
 * Each test deletes the tables it made (their buttons go with them).
 */
class StudioActionTest extends FeatureTestBase
{
    use TestTrait;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    /**
     * @var array<int, string>
     */
    protected $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $name) {
            System::clearRequestSession();
            $table = CustomTable::where('table_name', $name)->first();
            if (isset($table)) {
                $table->delete();
            }
        }
        parent::tearDown();
    }

    /**
     * A screen of a new table with a status (未着手 / 完了) and a memo.
     *
     * @param array<int, array<string, mixed>> $actions
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postScreen(array $actions)
    {
        $this->made[] = 'studio_test_action';
        System::clearRequestSession();
        return $this->post(admin_url('studio'), [
            'table_view_name' => 'ボタンのテスト',
            'table_name' => 'studio_test_action',
            'fields' => json_encode([
                ['type' => ColumnType::TEXT, 'label' => '件名'],
                ['type' => ColumnType::SELECT, 'label' => '状態', 'choices' => "未着手\n完了"],
                ['type' => ColumnType::DATE, 'label' => '期日'],
            ]),
            'actions' => json_encode($actions),
        ]);
    }

    public function testTheBuilderPageOffersButtons(): void
    {
        $response = $this->get(admin_url('studio/create'));
        $response->assertOk();
        $response->assertSee('id="exm-builder-buttons-box"', false);
        $response->assertSee('name="actions"', false);
        $response->assertSee(exmtrans('studio.add_button'));
    }

    public function testAButtonSetsTheItemAndIsNotShownOnceSet(): void
    {
        $this->postScreen([['label' => '完了にする', 'target' => 1, 'value' => '完了']])->assertRedirect();

        System::clearRequestSession();
        $table = CustomTable::getEloquent('studio_test_action');
        $this->assertNotNull($table);
        $operations = CustomOperation::where('custom_table_id', $table->id)->get();
        $this->assertCount(1, $operations);
        /** @var CustomOperation $operation */
        $operation = $operations->first();
        $this->assertTrue($operation->matchOperationType([CustomOperationType::BUTTON]));
        $this->assertSame('完了にする', $operation->getOption('button_label'));
        $this->assertTrue(boolval($operation->active_flg));

        // a data not done yet shows the button on its page
        $status = $table->custom_columns()->where('column_view_name', '状態')->firstOrFail();
        $data = $table->getValueModel();
        $data->setValue([$status->column_name => '未着手', $table->custom_columns()->orderBy('id')->firstOrFail()->column_name => 'テスト']);
        $data->save();
        $this->assertTrue($operation->isOperationTarget($data, [CustomOperationType::BUTTON]));
        System::clearRequestSession();
        $this->get(admin_urls('data', 'studio_test_action', $data->id))->assertOk()
            ->assertSee('id="menu_button_' . $operation->suuid . '"', false)->assertSee('完了にする');

        // pressed: the status is done
        System::clearRequestSession();
        $this->post(admin_urls('data', 'studio_test_action', $data->id, 'operationClick'), ['suuid' => $operation->suuid], static::AJAX)
            ->assertOk()->assertJson(['result' => true]);
        /** @var \Exceedone\Exment\Model\CustomValue $data */
        $data = $table->getValueModel()->query()->findOrFail($data->id);
        $this->assertSame('完了', $data->getValue($status->column_name));

        // and the button is not shown on a data that is done
        System::clearRequestSession();
        /** @var CustomOperation $operation */
        $operation = CustomOperation::query()->findOrFail($operation->id);
        $this->assertFalse($operation->isOperationTarget($data, [CustomOperationType::BUTTON]));
        $this->get(admin_urls('data', 'studio_test_action', $data->id))->assertOk()
            ->assertDontSee('id="menu_button_' . $operation->suuid . '"', false);
    }

    public function testTheScreenJsonListsTheButtons(): void
    {
        $this->postScreen([['label' => '完了にする', 'target' => 1, 'value' => '完了']])->assertRedirect();

        System::clearRequestSession();
        $table = CustomTable::getEloquent('studio_test_action');
        $form = CustomForm::getDefault($table);
        $operation = CustomOperation::where('custom_table_id', $table->id)->firstOrFail();
        $status = $table->custom_columns()->where('column_view_name', '状態')->firstOrFail();

        $screen = ScreenDefinition::of($form);
        $this->assertSame([[
            'id' => strval($operation->suuid),
            'label' => '完了にする',
            'kind' => 'update',
            'set' => [['column' => strval($status->column_name), 'value' => '完了']],
        ]], $screen['actions']);

        // a button turned off on the setting page is not given out
        $operation->setOption('active_flg', 0);
        $operation->save();
        System::clearRequestSession();
        $this->assertSame([], ScreenDefinition::of(CustomForm::getDefault(CustomTable::getEloquent('studio_test_action')))['actions']);
    }

    public function testMistakesOfAButtonAndNothingIsMade(): void
    {
        $response = $this->postScreen([
            ['label' => '', 'target' => 1, 'value' => '完了'],
            ['label' => '終わり', 'target' => 1, 'value' => '終わった'],
            ['label' => '期日', 'target' => 2, 'value' => '2026-11-21'],
            ['label' => '空', 'target' => 0, 'value' => ''],
            ['label' => 'ない項目', 'target' => 9, 'value' => 'x'],
        ]);
        $response->assertRedirect();
        $errors = session('studio_errors');
        $this->assertSame(exmtrans('studio.error.required'), $errors['actions.0.label'] ?? null);
        $this->assertSame(exmtrans('studio.error.action_choice'), $errors['actions.1.value'] ?? null);
        $this->assertSame(exmtrans('studio.error.action_target'), $errors['actions.2.target'] ?? null);
        $this->assertSame(exmtrans('studio.error.action_value'), $errors['actions.3.value'] ?? null);
        $this->assertSame(exmtrans('studio.error.action_target'), $errors['actions.4.target'] ?? null);
        $this->assertFalse(CustomTable::where('table_name', 'studio_test_action')->exists());

        $response = $this->postScreen(array_fill(0, 6, ['label' => '完了にする', 'target' => 1, 'value' => '完了']));
        $this->assertArrayHasKey('actions', session('studio_errors'));
        $this->assertFalse(CustomTable::where('table_name', 'studio_test_action')->exists());
    }

    public function testAScreenOfATableThatExistsMakesButtonsToo(): void
    {
        $this->made[] = 'studio_test_action_base';
        $base = BlankFormBuilder::build(BlankFormBuilder::normalize([
            'table_view_name' => 'action base',
            'table_name' => 'studio_test_action_base',
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => 'title'],
                ['type' => ColumnType::YESNO, 'label' => 'checked'],
                ['type' => ColumnType::INTEGER, 'label' => 'level'],
            ],
        ]));
        $columns = $base->custom_columns()->orderBy('id')->get();

        System::clearRequestSession();
        $this->post(admin_url('studio'), [
            'mode' => 'existing',
            'table_view_name' => '確認の画面',
            'table_id' => $base->id,
            'fields' => json_encode([
                ['column_id' => intval($columns[0]->id)],
                ['column_id' => intval($columns[1]->id)],
                ['column_id' => intval($columns[2]->id)],
            ]),
            'actions' => json_encode([
                ['label' => '確認した', 'target' => 1, 'value' => '1'],
                ['label' => 'レベル 3', 'target' => 2, 'value' => '3'],
            ]),
        ])->assertRedirect();
        $this->assertSame([], session('studio_errors', []));

        System::clearRequestSession();
        $operations = CustomOperation::where('custom_table_id', $base->id)->orderBy('id')->get();
        $this->assertSame(['確認した', 'レベル 3'], $operations->pluck('operation_name')->all());

        $data = $base->getValueModel();
        $data->setValue(['title' => 'a', 'checked' => 0, 'level' => 1]);
        $data->save();
        $this->assertTrue($operations[0]->isOperationTarget($data, [CustomOperationType::BUTTON]));
        $this->assertTrue($operations[1]->isOperationTarget($data, [CustomOperationType::BUTTON]));

        $data->setValue(['checked' => 1, 'level' => 3]);
        $data->save();
        System::clearRequestSession();
        $operations = CustomOperation::where('custom_table_id', $base->id)->orderBy('id')->get();
        $this->assertFalse($operations[0]->isOperationTarget($data, [CustomOperationType::BUTTON]));
        $this->assertFalse($operations[1]->isOperationTarget($data, [CustomOperationType::BUTTON]));

        // a number that is not one is a mistake
        System::clearRequestSession();
        $this->post(admin_url('studio'), [
            'mode' => 'existing',
            'table_view_name' => '確認の画面 2',
            'table_id' => $base->id,
            'fields' => json_encode([['column_id' => intval($columns[2]->id)]]),
            'actions' => json_encode([['label' => 'x', 'target' => 0, 'value' => 'three']]),
        ])->assertRedirect();
        $this->assertSame(exmtrans('studio.error.action_integer'), session('studio_errors')['actions.0.value'] ?? null);
        $this->assertFalse(CustomForm::where('form_view_name', '確認の画面 2')->exists());
    }

    public function testAUserWhoMayNotSetUpTheTableCannotMakeAButton(): void
    {
        $table = CustomTable::getEloquent('parent_table');
        $this->assertNotNull($table);
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->be($user);
        System::clearRequestSession();
        if (!$table->hasPermission(\Exceedone\Exment\Enums\Permission::EDIT_CUSTOM_FORM)
            || $table->hasPermission(\Exceedone\Exment\Enums\Permission::CUSTOM_TABLE)) {
            $this->markTestSkipped('user2 may not make a form of the table, or may set it up, in this test data');
        }
        $column = $table->custom_columns()->where('column_type', ColumnType::TEXT)->firstOrFail();
        $operations = CustomOperation::count();

        System::clearRequestSession();
        $this->post(admin_url('studio'), [
            'mode' => 'existing',
            'table_view_name' => '権限のない画面',
            'table_id' => $table->id,
            'fields' => json_encode([['column_id' => intval($column->id)]]),
            'actions' => json_encode([['label' => 'x', 'target' => 0, 'value' => 'y']]),
        ])->assertRedirect();
        $this->assertSame(exmtrans('studio.error.action_permission'), session('studio_errors')['actions'] ?? null);
        $this->assertSame($operations, CustomOperation::count());
        $this->assertFalse(CustomForm::where('form_view_name', '権限のない画面')->exists());
    }
}
