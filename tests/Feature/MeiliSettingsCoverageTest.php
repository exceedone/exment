<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\MeiliDictionary;
use Exceedone\Exment\Model\MeiliFilterSetting;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Save, edit and delete of the Meilisearch setting screens (admin/meili-dictionary and
 * admin/meili-filter). A save also schedules the index settings / reindex jobs; they run after
 * commit and this test's transaction never commits, so no Meilisearch server is needed.
 */
class MeiliSettingsCoverageTest extends FeatureTestBase
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

    public function testMeiliDictionaryEntryIsSavedEditedAndDeleted(): void
    {
        $this->post(admin_url('meili-dictionary'), [
            'type' => MeiliDictionary::TYPE_SYNONYM,
            'word' => 'claude-dictionary-word',
            'synonyms' => 'claude-synonym-a, claude-synonym-b',
            'order' => 7,
            'enabled' => 1,
        ])->assertRedirect(admin_url('meili-dictionary'))->assertSessionHasNoErrors();

        $entry = MeiliDictionary::where('word', 'claude-dictionary-word')->first();
        $this->assertNotNull($entry);
        $this->assertSame(MeiliDictionary::TYPE_SYNONYM, $entry->type);
        $this->assertSame('claude-synonym-a, claude-synonym-b', $entry->synonyms);
        $this->assertSame(7, $entry->order);
        $this->assertTrue($entry->enabled);

        $html = (string) $this->get(admin_url("meili-dictionary/{$entry->id}/edit"))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="word")(?=[^>]*value="claude-dictionary-word")[^>]*>/', $html);

        $this->put(admin_url("meili-dictionary/{$entry->id}"), [
            'type' => MeiliDictionary::TYPE_STOPWORD,
            'word' => 'claude-dictionary-put',
            'synonyms' => '',
            'order' => 8,
            'enabled' => 1,
        ])->assertRedirect(admin_url('meili-dictionary'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meili_dictionaries', [
            'id' => $entry->id,
            'type' => MeiliDictionary::TYPE_STOPWORD,
            'word' => 'claude-dictionary-put',
            'order' => 8,
        ]);

        $this->patch(admin_url("meili-dictionary/{$entry->id}"), [
            'type' => MeiliDictionary::TYPE_STOPWORD,
            'word' => 'claude-dictionary-patch',
            'synonyms' => '',
            'order' => 9,
            'enabled' => 0,
        ])->assertRedirect(admin_url('meili-dictionary'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meili_dictionaries', [
            'id' => $entry->id,
            'word' => 'claude-dictionary-patch',
            'order' => 9,
            'enabled' => 0,
        ]);

        $this->delete(admin_url("meili-dictionary/{$entry->id}"))
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('meili_dictionaries', ['id' => $entry->id]);
    }

    public function testMeiliFilterSettingIsSavedEditedAndDeleted(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $this->assertNotNull($table);

        // the columns are named after their type in this table: "select" is an equality filter
        // axis, "integer" can be a range one (MeiliFilterController::columnsForTable())
        $this->post(admin_url('meili-filter'), [
            'custom_table_id' => $table->id,
            'column_name' => 'select',
            'filter_type' => 'equality',
            'mode' => 'include',
            'view_label' => 'Claude filter',
            'alias' => 'claude_filter',
            'order' => 4,
            'enabled' => 1,
        ])->assertRedirect(admin_url('meili-filter'))->assertSessionHasNoErrors();

        // the saving() checks answer back() instead of the list when they refuse a setting
        $setting = MeiliFilterSetting::where('custom_table_id', $table->id)->where('column_name', 'select')->first();
        $this->assertNotNull($setting);
        $this->assertSame('equality', $setting->filter_type);
        $this->assertSame('Claude filter', $setting->view_label);
        $this->assertSame('claude_filter', $setting->alias);
        $this->assertSame(4, $setting->order);

        $html = (string) $this->get(admin_url("meili-filter/{$setting->id}/edit"))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="view_label")(?=[^>]*value="Claude filter")[^>]*>/', $html);

        $this->put(admin_url("meili-filter/{$setting->id}"), [
            'custom_table_id' => $table->id,
            'column_name' => 'integer',
            'filter_type' => 'range',
            'mode' => 'include',
            'view_label' => 'Claude range',
            'alias' => 'claude_range',
            'order' => 5,
            'enabled' => 1,
        ])->assertRedirect(admin_url('meili-filter'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meili_filter_settings', [
            'id' => $setting->id,
            'column_name' => 'integer',
            'filter_type' => 'range',
            'view_label' => 'Claude range',
        ]);

        $this->patch(admin_url("meili-filter/{$setting->id}"), [
            'custom_table_id' => $table->id,
            'column_name' => 'integer',
            'filter_type' => 'range',
            'mode' => 'exclude',
            'view_label' => 'Claude range (patch)',
            'alias' => 'claude_range',
            'order' => 6,
            'enabled' => 0,
        ])->assertRedirect(admin_url('meili-filter'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meili_filter_settings', [
            'id' => $setting->id,
            'mode' => 'exclude',
            'view_label' => 'Claude range (patch)',
            'enabled' => 0,
        ]);

        $this->delete(admin_url("meili-filter/{$setting->id}"))
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('meili_filter_settings', ['id' => $setting->id]);
    }
}
