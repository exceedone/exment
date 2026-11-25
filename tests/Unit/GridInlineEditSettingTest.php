<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Grid\Tools\GridInlineEditor;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inline edit of the data list:
 *
 *   - #2163: the system setting "grid_inline_edit_available" and the view
 *     setting "inline_edit_enabled" turn it off, and the server refuses an
 *     inline edit they turned off. Bulk edit is left alone.
 *   - #2183: a "2値の選択" (boolean) column can be edited inline and in bulk,
 *     with its own two choices.
 */
class GridInlineEditSettingTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    /** GridInlineEditor::SCREEN_WRITE_KEY, the contract with grid_tools.js */
    private const SCREEN_WRITE = '_grid_edit';

    /** GridInlineEditor::INLINE_EDIT_KEY, the contract with grid_tools.js */
    private const INLINE_EDIT = '_grid_inline';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // fixtures -----------------------------------------------------------

    private function table(string $table_name = self::TABLE): CustomTable
    {
        return CustomTable::getEloquent($table_name);
    }

    /**
     * Drop the fixture table's form priorities, so the default form decides
     * for every record, as in GridScreenEditTest.
     */
    private function dropPriorityForms(): void
    {
        $formIds = CustomForm::where('custom_table_id', $this->table()->id)->pluck('id');
        \DB::table('custom_form_priorities')->whereIn('custom_form_id', $formIds)->delete();
        System::clearCache();
    }

    private function firstRecordId(): int
    {
        $this->dropPriorityForms();
        return intval($this->table()->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->value('id'));
    }

    private function defaultView(): CustomView
    {
        $view = CustomView::where('custom_table_id', $this->table()->id)
            ->where('view_kind_type', ViewKindType::DEFAULT)
            ->first();
        $this->assertNotNull($view, 'the fixture table has a data list view');
        return $view;
    }

    private function setViewInlineEdit(CustomView $view, string $value): void
    {
        $options = $view->options ?? [];
        $options['inline_edit_enabled'] = $value;
        \DB::table('custom_views')->where('id', $view->id)->update(['options' => json_encode($options)]);
        System::clearCache();
    }

    private function booleanColumn(): CustomColumn
    {
        $column = new CustomColumn([
            'column_name' => 'gender',
            'column_view_name' => 'gender',
            'column_type' => ColumnType::BOOLEAN,
            'options' => ['true_value' => 'm', 'true_label' => 'Male', 'false_value' => 'f', 'false_label' => 'Female'],
        ]);
        return $column;
    }

    /**
     * Call the webapi the way the screen tools do.
     *
     * @param array<string, mixed> $params
     * @return int status
     */
    private function callUpdate(CustomTable $table, int $id, array $params): int
    {
        $request = Request::create('/admin/webapi/data/' . $table->table_name . '/' . $id, 'PUT', $params);
        $this->app->instance('request', $request);
        $controller = new ApiDataController($table, $request);
        $result = $controller->dataUpdate($request, $table->table_name, $id);

        return $result instanceof Response ? $result->getStatusCode() : 200;
    }

    // #2163: settings ------------------------------------------------------

    public function testInlineEditIsOnByDefault(): void
    {
        $this->assertTrue(boolval(System::grid_inline_edit_available()), 'the system setting is on by default');
        $this->assertTrue(GridInlineEditor::isInlineEditEnabled(null));

        // A view saved before the setting existed has no value: on.
        $view = new CustomView();
        $this->assertTrue(boolval($view->inline_edit_enabled));
        $this->assertTrue(GridInlineEditor::isInlineEditEnabled($view));
    }

    public function testViewSettingTurnsInlineEditOff(): void
    {
        $view = new CustomView();
        $view->inline_edit_enabled = '0';
        $this->assertFalse(GridInlineEditor::isInlineEditEnabled($view));

        $view->inline_edit_enabled = '1';
        $this->assertTrue(GridInlineEditor::isInlineEditEnabled($view));
    }

    public function testSystemSettingOffWinsOverTheView(): void
    {
        System::grid_inline_edit_available(false);
        System::clearCache();

        $view = new CustomView();
        $view->inline_edit_enabled = '1';
        $this->assertFalse(GridInlineEditor::isInlineEditEnabled(null));
        $this->assertFalse(GridInlineEditor::isInlineEditEnabled($view), 'a view cannot turn it back on');
    }

    // #2163: the server refuses an inline edit the settings turned off ---

    public function testServerRefusesInlineEditWhenTheSystemSettingIsOff(): void
    {
        $id = $this->firstRecordId();
        $view = $this->defaultView();

        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, self::INLINE_EDIT => $view->suuid, 'value' => ['integer' => 21]]);
        $this->assertSame(200, $status, 'inline edit works by default');

        System::grid_inline_edit_available(false);
        System::clearCache();

        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, self::INLINE_EDIT => $view->suuid, 'value' => ['integer' => 22]]);
        $this->assertSame(403, $status, 'an inline edit is refused');

        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, self::INLINE_EDIT => 1, 'value' => ['integer' => 22]]);
        $this->assertSame(403, $status, 'an inline edit without a view is refused too');

        // Bulk edit sends no inline key and is left alone.
        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, 'value' => ['integer' => 23]]);
        $this->assertSame(200, $status, 'bulk edit still works');
    }

    public function testServerRefusesInlineEditOnAViewThatTurnedItOff(): void
    {
        $id = $this->firstRecordId();
        $view = $this->defaultView();
        $this->setViewInlineEdit($view, '0');

        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, self::INLINE_EDIT => $view->suuid, 'value' => ['integer' => 31]]);
        $this->assertSame(403, $status, 'an inline edit from that view is refused');

        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, 'value' => ['integer' => 32]]);
        $this->assertSame(200, $status, 'bulk edit still works');

        // A view of another table is read as no view.
        $other = CustomView::where('custom_table_id', '<>', $this->table()->id)->first();
        $this->assertNotNull($other);
        $this->setViewInlineEdit($other, '0');
        $status = $this->callUpdate($this->table(), $id, [self::SCREEN_WRITE => 1, self::INLINE_EDIT => $other->suuid, 'value' => ['integer' => 33]]);
        $this->assertSame(200, $status, 'only a view of this table has a say');
    }

    // #2183: boolean columns -----------------------------------------------

    public function testBooleanColumnIsInlineEditableWithItsTwoChoices(): void
    {
        $column = $this->booleanColumn();
        $this->assertTrue(GridInlineEditor::isEditable($column));

        $editor = new GridInlineEditor(new CustomTable(), new CustomView());
        $method = new \ReflectionMethod(GridInlineEditor::class, 'buildColumnMeta');
        $method->setAccessible(true);
        $meta = $method->invoke($editor, $column);

        $this->assertSame('select', $meta['type']);
        $this->assertTrue($meta['required'], 'a boolean column has no empty state');
        $this->assertSame([
            ['v' => 'm', 'l' => 'Male'],
            ['v' => 'f', 'l' => 'Female'],
        ], $meta['choices']);
    }

    public function testBooleanColumnIsBulkEditableAndCanBeWrittenFromTheList(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS);
        $column = CustomColumn::getEloquent('boolean', $table);
        $this->assertNotNull($column);

        $view_column = new CustomViewColumn();
        $view_column->view_column_type = ConditionType::COLUMN;
        $view_column->view_column_table_id = $table->id;
        $view_column->view_column_target_id = $column->id;
        $this->assertTrue(GridInlineEditor::isEditableColumn($view_column, $table), 'a pen for the boolean column');
        $this->assertTrue(GridInlineEditor::isBulkEditableColumn($view_column, $table), 'and a place in bulk edit');

        $record = $table->getValueModel();
        $record->setValue('text', 'boolean inline');
        $record->setValue('boolean', 'ng');
        $record->save();

        $status = $this->callUpdate($table, intval($record->id), [self::SCREEN_WRITE => 1, self::INLINE_EDIT => 1, 'value' => ['boolean' => 'ok']]);
        $this->assertSame(200, $status);
        $saved = $table->getValueModel()->query()->withoutGlobalScopes()->find($record->id);
        $this->assertSame('ok', $saved->getValue('boolean'), 'the value of the chosen side is saved');
    }
}
