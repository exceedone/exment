<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\Eloquent\Model;

/**
 * The order columns of the setting lists are edited in place with the inline edit of admin-core
 * 2.0 (`_edit_inline`), not x-editable (ISS02862): it changes the order only, not the rest.
 */
class OrderInlineEditTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

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

    public function testNoSettingListUsesXEditable(): void
    {
        foreach (['CustomTableController', 'CustomColumnController', 'CustomViewController', 'CustomFormController', 'RoleGroupController', 'CellStylePresetSettingController'] as $name) {
            $source = (string) file_get_contents(exment_package_path("src/Controllers/{$name}.php"));
            $this->assertStringNotContainsString('->editable()', $source, $name);
            $this->assertStringContainsString('->text()', $source, $name);
        }
    }

    public function testTheOrderOfATable(): void
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->assertTheOrderIsEdited(admin_urls('table', $table->id), $table, 'table_view_name');
    }

    public function testTheOrderOfAColumn(): void
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        /** @var CustomColumn $column */
        $column = CustomColumn::query()->where('custom_table_id', $table->id)->firstOrFail();
        $this->assertTheOrderIsEdited(admin_urls('column', $table->table_name, $column->id), $column, 'column_view_name');
    }

    public function testTheOrderOfAView(): void
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        /** @var CustomView $view */
        $view = CustomView::query()->where('custom_table_id', $table->id)->firstOrFail();
        $this->assertTheOrderIsEdited(admin_urls('view', $table->table_name, $view->id), $view, 'view_view_name');
    }

    public function testTheOrderOfAFormPriority(): void
    {
        /** @var CustomFormPriority $priority */
        $priority = CustomFormPriority::query()->firstOrFail();
        $table = $priority->custom_form->custom_table;
        $this->assertTheOrderIsEdited(admin_urls('formpriority', $table->table_name, $priority->id), $priority, 'custom_form_id');
    }

    public function testTheOrderOfACellStylePreset(): void
    {
        $preset = new CellStylePreset();
        $preset->preset_name = 'order inline edit test';
        $preset->column_types = [];
        $preset->options = ['grid_style' => 'pill'];
        $preset->order = 1;
        $preset->save();
        $this->assertTheOrderIsEdited(admin_urls('cell_style_preset', $preset->id), $preset, 'preset_name');
    }

    /**
     * @param string $kept a field that must stay as it was (not saved as the whole form)
     */
    protected function assertTheOrderIsEdited(string $url, Model $model, string $kept): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $before = $model->getAttribute($kept);

        System::clearRequestSession();
        // what open-admin-grid-inline-edit.js sends
        $response = $this->put($url, [
            '_method' => 'PUT',
            '_edit_inline' => 'true',
            'after-save' => 'exit',
            'order' => 7,
        ], static::AJAX);
        $this->assertLessThan(400, $response->getStatusCode(), $url . ': ' . substr((string) $response->getContent(), 0, 300));

        $fresh = $model->newQuery()->findOrFail($model->getKey());
        $this->assertSame(7, (int) $fresh->getAttribute('order'), $url);
        $this->assertEquals($before, $fresh->getAttribute($kept), $url . ': ' . $kept);
    }
}
