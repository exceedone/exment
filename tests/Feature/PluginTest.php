<?php

namespace Exceedone\Exment\Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
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
     * test plugin trigger
     *
     * @return void
     */
    public function testTrigger()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_value = $custom_table->getValueModel(3);

        $plugin = Plugin::where('plugin_name', 'TestPluginDemoTrigger')->first();
        $pluginClass = $plugin->getClass(PluginType::TRIGGER, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
        ]);
        $result = $pluginClass->execute();
        $this->assertTrue($result);
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
