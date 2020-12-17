<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Jobs;
use Carbon\Carbon;


class PluginTest extends TestCase
{
    use TestTrait;

    protected function init(bool $fake)
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
    }


    /**
     * test plugin button
     *
     * @return void
     */
    public function testButton()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_value = $custom_table->getValueModel()->where('value->multiples_of_3', '1')->first();

        $plugin = Plugin::where('plugin_name', 'TestPluginDemoButton')->first();
        $pluginClass = $plugin->getClass(PluginType::BUTTON, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
        ]);
        $data = $pluginClass->execute();
        $this->assertTrue(array_get($data, 'result'));
        $this->assertEquals(array_get($data, 'swaltext'), '正常です。');
    }

    /**
     * test plugin event saved
     *
     * @return void
     */
    public function testEventSaved()
    {
        $id = 3;

        DB::beginTransaction();

        try {
            $old_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->getValueModel($id);
            $old_int = $old_value->getValue('integer');

            $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
            $custom_value = $custom_table->getValueModel($id);
            $change_val = $custom_value->getValue('multiples_of_3') == '1'? '0': '1';
            $custom_value->setValue('multiples_of_3', $change_val);
            $custom_value->save();

            $new_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->getValueModel($id);
            $new_int = $new_value->getValue('integer');

            $this->assertEquals($old_int + 100, $new_int);
        } finally {
            DB::rollback();
        }
    }

    /**
     * test plugin event workflow executed
     *
     * @return void
     */
    public function testEventWorkflow()
    {
        $id = 3;

        DB::beginTransaction();

        try {
            $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
            $custom_value = $custom_table->getValueModel($id);

            // get action
            $action = $custom_value->getWorkflowActions()->first();
            $action_user = $action->getAuthorityTargets($custom_value)->first();
            $this->be(LoginUser::find($action_user->id));
            
            $action->executeAction($custom_value, [
                'comment' => 'プラグインのワークフローイベントのテストです。',
            ]);

            $new_text = $custom_value->getValue('init_text');

            $this->assertEquals($new_text, 'workflow executed');
        } finally {
            DB::rollback();
        }
    }

    /**
     * test plugin event notify executed
     *
     * @return void
     */
    public function testEventNotify()
    {
        $id = 3;

        DB::beginTransaction();

        try {
            $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
            $custom_value = $custom_table->getValueModel($id);

            $notify = Notify::where('notify_trigger', NotifyTrigger::BUTTON)->first();
            $target_user = CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

            NotifyService::executeNotifyAction($notify, [
                'custom_value' => $custom_value,
                'subject' => 'プラグインテスト',
                'body' => 'プラグインの通知イベントのテストです。',
                'user' => $target_user,
            ]);

            $new_text = $custom_value->getValue('init_text');

            $this->assertEquals($new_text, 'notify executed');
        } finally {
            DB::rollback();
        }
    }

    /**
     * test plugin batch
     *
     * @return void
     */
    public function testEventBatch()
    {
        $id = 3;

        DB::beginTransaction();

        try {
            $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
            $custom_value = $custom_table->getValueModel($id);
            $custom_value->delete();

            $trash_value = $custom_table->getValueModel()->withTrashed()->find($id);
            $this->assertTrue(isset($trash_value));

            \Artisan::call('exment:batch', ['--name' => 'TestPluginDemoBatch']);

            $trash_value = $custom_table->getValueModel()->withTrashed()->find($id);
            $this->assertTrue(is_null($trash_value));
        } finally {
            DB::rollback();
        }
    }

    /**
     * test plugin validate
     *
     * @return void
     */
    public function testValidate()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_value = $custom_table->getValueModel(1);

        $plugin = Plugin::where('plugin_name', 'TestPluginValidatorTest')->first();
        $pluginClass = $plugin->getClass(PluginType::VALIDATOR, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
            'input_value' => [
                'integer' => 9999999999,
                'currency' => 9999999999,
            ],
        ]);
        $result = $pluginClass->validate();
        $this->assertTrue($result);
    }


}
