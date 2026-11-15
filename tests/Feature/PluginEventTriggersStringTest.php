<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * #1712: an event plugin installed before the fix keeps options.event_triggers as the comma
 * separated string of its config.json ("saved,deleted"). It runs without being installed again.
 *
 * Uses TestPluginEventSaved of the test data (target: custom_value_edit_all, adds 100 to "integer"
 * of the custom_value_view_all record with the same id), like PluginTest::testEventSaved().
 */
class PluginEventTriggersStringTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testEventPluginRunsWhenEventTriggersIsCommaSeparatedString(): void
    {
        $plugin = Plugin::where('plugin_name', 'TestPluginEventSaved')->first();
        $this->assertNotNull($plugin, 'test data has TestPluginEventSaved');
        $options = $plugin->options;
        $options['event_triggers'] = 'saved,deleted';
        $plugin->options = $options;
        $plugin->save();
        System::clearCache();

        $stored = Plugin::where('plugin_name', 'TestPluginEventSaved')->first();
        $this->assertNotNull($stored);
        $this->assertSame('saved,deleted', array_get($stored->options, 'event_triggers'));

        $id = 3;
        $old_int = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->getValueModel($id)->getValue('integer');

        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel($id);
        $change_val = $custom_value->getValue('multiples_of_3') == '1' ? '0' : '1';
        $custom_value->setValue('multiples_of_3', $change_val);
        $custom_value->save();

        System::clearCache();
        $new_int = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->getValueModel($id)->getValue('integer');

        $this->assertEquals($old_int + 100, $new_int);
    }
}
