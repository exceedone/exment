<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Controllers\CustomViewController;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Grid\Tools\GridContextMenu;
use Exceedone\Exment\Grid\Tools\GridInlineEditor;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The list screen fixes of the 2026-10-09 review, server side:
 *
 *   - a write from a list screen (inline edit, bulk edit, "Set me as", a
 *     kanban move) obeys the record's form, the "cannot change from
 *     display" switches and calc formulas, while a plain API call is left
 *     as it was;
 *   - the view preview reads the table of the url, never a posted one, and
 *     never exports;
 *   - the cell endpoint refuses a column of another table;
 *   - an editable cell carries its true value whenever a style adds text;
 *   - the grid tools keep their browser state apart per user.
 *
 * Every test here fails on the code before the fixes (ee3e87aa6).
 */
class GridScreenEditTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    /**
     * The body key grid_tools.js and the kanban send with every write
     * (GridInlineEditor::SCREEN_WRITE_KEY). Written out, because it is the
     * contract with the client: the old code simply ignored it.
     */
    private const SCREEN_WRITE = '_grid_edit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // fixtures -----------------------------------------------------------

    private function table(): CustomTable
    {
        return CustomTable::getEloquent(self::TABLE);
    }

    private function column(string $column_name): CustomColumn
    {
        return CustomColumn::getEloquent($column_name, $this->table());
    }

    private function firstRecordId(): int
    {
        return intval($this->table()->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->value('id'));
    }

    private function defaultForm(): CustomForm
    {
        return CustomForm::getDefault($this->table());
    }

    private function otherForm(): CustomForm
    {
        $form = CustomForm::where('custom_table_id', $this->table()->id)->where('default_flg', false)->first();
        $this->assertNotNull($form, 'the fixture table has a second form');
        return $form;
    }

    /**
     * Drop the fixture table's form priorities, so the default form decides
     * for every record. Optionally add one priority without conditions -
     * which matches every record - for the given form.
     */
    private function setPriorityForm(?CustomForm $form = null): void
    {
        $formIds = CustomForm::where('custom_table_id', $this->table()->id)->pluck('id');
        \DB::table('custom_form_priorities')->whereIn('custom_form_id', $formIds)->delete();
        if (isset($form)) {
            $priority = new CustomFormPriority();
            $priority->custom_form_id = $form->id;
            $priority->order = 1;
            $priority->save();
        }
        System::clearCache();
    }

    /**
     * Change how a form shows one column; null takes it off the form.
     *
     * @param array<string, mixed>|null $options
     */
    private function setFormColumn(CustomForm $form, string $column_name, ?array $options): void
    {
        $blockIds = \DB::table('custom_form_blocks')
            ->where('custom_form_id', $form->id)
            ->where('form_block_type', FormBlockType::DEFAULT)
            ->pluck('id');
        $query = \DB::table('custom_form_columns')
            ->whereIn('custom_form_block_id', $blockIds)
            ->where('form_column_type', FormColumnType::COLUMN)
            ->where('form_column_target_id', $this->column($column_name)->id);
        $this->assertSame(1, $query->count(), "the form shows {$column_name}");
        if (is_null($options)) {
            $query->delete();
        } else {
            $query->update(['options' => json_encode($options)]);
        }
        System::clearCache();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function setTableOptions(array $options): void
    {
        $table = $this->table();
        \DB::table('custom_tables')->where('id', $table->id)
            ->update(['options' => json_encode(array_merge($table->options ?? [], $options))]);
        System::clearCache();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function setColumnOptions(string $column_name, array $options): void
    {
        $column = $this->column($column_name);
        \DB::table('custom_columns')->where('id', $column->id)
            ->update(['options' => json_encode(array_merge($column->options ?? [], $options))]);
        System::clearCache();
    }

    /**
     * Call the webapi the way the screen tools do.
     *
     * @param string $method PUT or DELETE
     * @param array<string, mixed> $params
     * @return array{0: int, 1: array<mixed>}
     */
    private function callData(string $method, int $id, array $params): array
    {
        $request = Request::create('/admin/webapi/data/' . self::TABLE . '/' . $id, $method, $params);
        $this->app->instance('request', $request);
        $controller = new ApiDataController($this->table(), $request);
        $result = $method === 'DELETE'
            ? $controller->dataDelete($request, self::TABLE, $id)
            : $controller->dataUpdate($request, self::TABLE, $id);

        if ($result instanceof Response) {
            return [$result->getStatusCode(), json_decode((string)$result->getContent(), true) ?? []];
        }
        return [200, []];
    }

    /**
     * Create a record through the webapi the way the kanban quick add does.
     *
     * @param array<string, mixed> $params
     * @return array{0: int, 1: array<mixed>}
     */
    private function callCreate(array $params): array
    {
        $request = Request::create('/admin/webapi/data/' . self::TABLE, 'POST', $params);
        $this->app->instance('request', $request);
        $controller = new ApiDataController($this->table(), $request);
        $result = $controller->dataCreate($request);

        if ($result instanceof Response) {
            return [$result->getStatusCode(), json_decode((string)$result->getContent(), true) ?? []];
        }
        return [200, []];
    }

    private function bootAdmin(): void
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
    }

    // High #3: list screen writes follow the record's form ---------------

    public function testScreenWriteRefusesAColumnTheFormShowsViewOnly(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'integer', ['view_only' => '1']);
        $id = $this->firstRecordId();

        [$status, $body] = $this->callData('PUT', $id, [self::SCREEN_WRITE => 1, 'value' => ['integer' => 123]]);
        $this->assertSame(400, $status, 'a view only column cannot be written from the list');
        $this->assertStringContainsString($this->column('integer')->column_view_name, json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');

        // The plain API keeps writing columns, as documented.
        [$status] = $this->callData('PUT', $id, ['value' => ['integer' => 123]]);
        $this->assertSame(200, $status, 'a plain API write is unchanged');
    }

    public function testScreenWriteRefusesAColumnTheFormDoesNotShow(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'integer', null);

        [$status] = $this->callData('PUT', $this->firstRecordId(), [self::SCREEN_WRITE => 1, 'value' => ['integer' => 7]]);
        $this->assertSame(400, $status);
    }

    public function testScreenWriteRefusesHiddenReadOnlyAndInternalColumns(): void
    {
        $this->setPriorityForm();
        foreach (['read_only', 'hidden', 'internal'] as $switch) {
            $this->setFormColumn($this->defaultForm(), 'integer', ['field_showing_type' => $switch]);
            [$status] = $this->callData('PUT', $this->firstRecordId(), [self::SCREEN_WRITE => 1, 'value' => ['integer' => 8]]);
            $this->assertSame(400, $status, "a {$switch} column cannot be written from the list");
        }
    }

    public function testScreenWriteFollowsTheFormThePriorityPicksForTheRecord(): void
    {
        // The default form lets the user change the column; the form a
        // priority picks for every record does not.
        $this->setFormColumn($this->otherForm(), 'integer', ['view_only' => '1']);
        $this->setPriorityForm($this->otherForm());
        $id = $this->firstRecordId();

        [$status] = $this->callData('PUT', $id, [self::SCREEN_WRITE => 1, 'value' => ['integer' => 9]]);
        $this->assertSame(400, $status, 'the record\'s own form decides');

        // ...and the request cannot pick the lenient form instead.
        [$status] = $this->callData('PUT', $id, [
            self::SCREEN_WRITE => 1,
            'formid' => $this->defaultForm()->suuid,
            'value' => ['integer' => 9],
        ]);
        $this->assertSame(400, $status, 'formid does not choose the form of a screen write');
    }

    public function testScreenWriteKeepsTheFormsRequiredRule(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'integer', ['required' => '1']);

        [$status] = $this->callData('PUT', $this->firstRecordId(), [self::SCREEN_WRITE => 1, 'value' => ['integer' => '']]);
        $this->assertSame(400, $status, 'a column the form requires cannot be emptied from the list');
    }

    public function testScreenWriteObeysTheCannotEditFromDisplaySwitch(): void
    {
        $this->setPriorityForm();
        $this->setTableOptions(['form_action_disable_flg' => ['edit']]);

        [$status] = $this->callData('PUT', $this->firstRecordId(), [self::SCREEN_WRITE => 1, 'value' => ['integer' => 11]]);
        $this->assertSame(403, $status);
    }

    public function testScreenDeleteObeysTheCannotDeleteFromDisplaySwitch(): void
    {
        $this->setTableOptions(['form_action_disable_flg' => ['delete']]);
        // A record nothing refers to, so only the switch can refuse it.
        $record = $this->table()->getValueModel();
        $record->setValue('text', 'screen delete');
        $record->save();
        $id = intval($record->id);

        [$status] = $this->callData('DELETE', $id, [self::SCREEN_WRITE => 1]);
        $this->assertSame(403, $status);
        $this->assertNotNull($this->table()->getValueModel()->query()->withoutGlobalScopes()->find($id), 'the record is still there');
    }

    // Medium (2026-10-10): kanban quick add follows the create page ------

    private function countRecords(): int
    {
        return $this->table()->getValueModel()->query()->withoutGlobalScopes()->count();
    }

    public function testScreenCreateRefusesAColumnTheFormShowsViewOnly(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'integer', ['view_only' => '1']);
        $before = $this->countRecords();

        // a card typed into the "integer = 5" column of a board
        [$status, $body] = $this->callCreate([self::SCREEN_WRITE => 1, 'value' => ['text' => 'quick add', 'integer' => 5]]);
        $this->assertSame(400, $status, 'a view only column cannot be set from the board');
        $this->assertStringContainsString($this->column('integer')->column_view_name, json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame($before, $this->countRecords(), 'nothing was created');

        // The plain API keeps creating with any column, as documented.
        [$status] = $this->callCreate(['value' => ['text' => 'quick add', 'integer' => 5]]);
        $this->assertSame(200, $status, 'a plain API create is unchanged');
    }

    public function testScreenCreateObeysTheCannotCreateFromDisplaySwitch(): void
    {
        $this->setPriorityForm();
        $this->setTableOptions(['form_action_disable_flg' => ['create']]);
        $before = $this->countRecords();

        [$status] = $this->callCreate([self::SCREEN_WRITE => 1, 'value' => ['text' => 'quick add']]);
        $this->assertSame(403, $status);
        $this->assertSame($before, $this->countRecords(), 'nothing was created');

        [$status] = $this->callCreate(['value' => ['text' => 'quick add']]);
        $this->assertSame(200, $status, 'a plain API create is unchanged');
    }

    public function testScreenCreateKeepsTheFormsRequiredRule(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'integer', ['required' => '1']);

        // quick add sends the title only; the create page would not submit
        [$status, $body] = $this->callCreate([self::SCREEN_WRITE => 1, 'value' => ['text' => 'quick add']]);
        $this->assertSame(400, $status, 'a column the form requires cannot be left out');
        $this->assertStringContainsString($this->column('integer')->column_view_name, json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');

        [$status] = $this->callCreate([self::SCREEN_WRITE => 1, 'value' => ['text' => 'quick add', 'integer' => 7]]);
        $this->assertSame(200, $status, 'with the required column filled the card is created');

        // A column default fills the gap, as it does on the create page.
        $this->setColumnOptions('integer', ['default' => '3']);
        [$status] = $this->callCreate([self::SCREEN_WRITE => 1, 'value' => ['text' => 'quick add']]);
        $this->assertSame(200, $status, 'the default satisfies the required rule');
    }

    public function testListOffersNoEditorForAColumnNoFormShows(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'integer', ['view_only' => '1']);
        $this->setFormColumn($this->otherForm(), 'integer', ['view_only' => '1']);

        $this->assertFalse(GridInlineEditor::isEditableColumn($this->viewColumn('integer'), $this->table()), 'no pen, no bulk edit for it');
        // A column every form shows is still offered.
        $this->assertTrue(GridInlineEditor::isEditableColumn($this->viewColumn('decimal'), $this->table()));
    }

    /**
     * A view column of the fixture table, as a view would hold it. A new
     * one each time: the column item is cached on the instance.
     */
    private function viewColumn(string $column_name): CustomViewColumn
    {
        $view_column = new CustomViewColumn();
        $view_column->view_column_type = ConditionType::COLUMN;
        $view_column->view_column_table_id = $this->table()->id;
        $view_column->view_column_target_id = $this->column($column_name)->id;
        return $view_column;
    }

    public function testSetMeAsSkipsAUserColumnNoFormShows(): void
    {
        $this->setPriorityForm();
        $this->setFormColumn($this->defaultForm(), 'user', ['view_only' => '1']);
        $this->setFormColumn($this->otherForm(), 'user', ['view_only' => '1']);

        $menu = new GridContextMenu($this->table());
        $method = new \ReflectionMethod(GridContextMenu::class, 'findAssignColumn');
        $method->setAccessible(true);
        $this->assertNull($method->invoke($menu), '"Set me as" is not offered for a column the forms keep view only');
    }

    // Medium #6: a calc formula input is not edited from the list -----------

    public function testColumnACalcFormulaReadsIsNotEditableFromTheList(): void
    {
        $this->setPriorityForm();
        $this->setColumnOptions('currency', ['calc_formula' => '${value:integer} * 2']);

        $this->assertFalse(GridInlineEditor::isEditable($this->column('integer')), 'the formula input gets no inline editor');
        $this->assertTrue(GridInlineEditor::isEditable($this->column('decimal')), 'a column no formula reads keeps it');

        [$status] = $this->callData('PUT', $this->firstRecordId(), [self::SCREEN_WRITE => 1, 'value' => ['integer' => 5]]);
        $this->assertSame(400, $status, 'the server refuses the write too');
    }

    // F6: the cell endpoint draws columns of its own table only --------------

    public function testCellEndpointRefusesAColumnOfAnotherTable(): void
    {
        $foreign = CustomColumn::getEloquent('select_valtext', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS);
        $this->assertNotNull($foreign);
        $id = $this->firstRecordId();

        $request = Request::create('/admin/webapi/data/' . self::TABLE . '/cell/' . $id . '/' . $foreign->id, 'GET');
        $this->app->instance('request', $request);
        $controller = new ApiDataController($this->table(), $request);
        $result = $controller->dataCellHtml($request, self::TABLE, $id, strval($foreign->id));

        $this->assertInstanceOf(Response::class, $result, 'no cell is drawn');
        $this->assertSame(404, $result->getStatusCode());
    }

    // L2: the editor starts from the value, not from the decorated text ------

    public function testEditableCellCarriesItsValueWhenTheStyleAddsText(): void
    {
        $record = $this->table()->getValueModel($this->firstRecordId());
        $record->setValue('text', 'Tanaka');
        $item = $this->column('text')->column_item->setCustomValue($record);

        // The avatar style prints the initial in front of the name.
        $marker = GridInlineEditor::rawValueTag($item, '<span class="exm-cell-avatar"><span>T</span>Tanaka</span>');
        $this->assertStringContainsString('data-v="Tanaka"', $marker);

        // Plain text needs no marker: the text is the value.
        $this->assertSame('', GridInlineEditor::rawValueTag($item, 'Tanaka'));
    }

    // L9: the grid tools keep their state apart per user ---------------------

    public function testGridToolStateIsKeptPerUser(): void
    {
        $this->bootAdmin();
        $admin = $this->gridToolStorageKey();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        System::clearRequestSession();
        $user1 = $this->gridToolStorageKey();

        $this->assertNotSame($admin, $user1, 'two users of one table do not share the stored filter, groups and pins');
        $this->assertStringStartsWith(self::TABLE, $user1);
    }

    // I-1: the group-by menu takes its brackets from the translation ---------

    public function testGroupByHeaderTakesItsBracketsFromTheTranslation(): void
    {
        $this->bootAdmin();
        $locale = app()->getLocale();
        try {
            foreach (['en', 'ja'] as $lang) {
                app()->setLocale($lang);
                $html = $this->gridGroupByTool()->render();
                $scope = e(exmtrans('common.grid_group_scope', ['page' => exmtrans('common.grid_group_page')]));

                $this->assertStringContainsString("<small>{$scope}</small>", $html, "$lang: the header note comes from the translation");
                if ($lang === 'en') {
                    $this->assertStringNotContainsString("\u{FF08}", $html, 'no full-width bracket in the English menu');
                }
            }
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * The key the list's group-by tool keeps its browser state under, read
     * off the tool the data grid really builds.
     */
    private function gridToolStorageKey(): string
    {
        $tool = $this->gridGroupByTool();
        return strval((new \ReflectionProperty($tool, 'storageKey'))->getValue($tool));
    }

    /**
     * The group-by tool the data grid really builds.
     */
    private function gridGroupByTool(): \Exceedone\Exment\Grid\Tools\GridGroupBy
    {
        $request = Request::create('/admin/data/' . self::TABLE, 'GET');
        $this->app->instance('request', $request);
        $view = CustomView::where('custom_table_id', $this->table()->id)
            ->where('view_kind_type', ViewKindType::DEFAULT)->first();
        $this->assertNotNull($view);
        $grid = $view->grid_item->grid();

        $tools = (new \ReflectionProperty(\ExmentAdminCore\Admin\Grid::class, 'tools'))->getValue($grid);
        // Grid\Tools keeps one collection per toolbar position.
        $this->assertIsObject($tools);
        $positions = (new \ReflectionProperty($tools, 'tools'))->getValue($tools);
        foreach ($positions as $list) {
            foreach ($list as $tool) {
                if ($tool instanceof \Exceedone\Exment\Grid\Tools\GridGroupBy) {
                    return $tool;
                }
            }
        }
        $this->fail('the data grid has its group-by tool');
    }

    // High #1: the view preview reads the table of the url -------------------

    public function testPreviewReadsTheTableOfTheUrlNotAPostedOne(): void
    {
        $this->bootAdmin();
        $table = $this->table();
        $request = Request::create('/admin/view/' . self::TABLE . '/preview', 'POST', [
            'custom_table_id' => CustomTable::getEloquent('user')->id,
            'view_view_name' => 'preview',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $this->app->instance('request', $request);

        $controller = new CustomViewController($table, $request);
        $method = new \ReflectionMethod(CustomViewController::class, 'getViewFromRequest');
        $method->setAccessible(true);
        $view = $method->invoke($controller, $request, null);

        $this->assertInstanceOf(CustomView::class, $view);
        $this->assertEquals($table->id, $view->custom_table_id, 'the grid reads the table the permission was checked on');
    }

    public function testPreviewIgnoresAViewOfAnotherTable(): void
    {
        $this->bootAdmin();
        $other = CustomView::where('custom_table_id', '<>', $this->table()->id)->first();
        $this->assertNotNull($other);
        $request = Request::create('/admin/view/' . self::TABLE . '/preview/' . $other->id, 'POST', [
            'view_view_name' => 'preview',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $this->app->instance('request', $request);

        $controller = new CustomViewController($this->table(), $request);
        $method = new \ReflectionMethod(CustomViewController::class, 'getViewFromRequest');
        $method->setAccessible(true);
        $this->assertNull($method->invoke($controller, $request, $other->id), 'a view of another table is not previewed here');
    }

    public function testPreviewNeverExports(): void
    {
        $this->bootAdmin();
        $request = Request::create('/admin/view/' . self::TABLE . '/preview', 'POST', [
            '_export_' => 'all',
            'view_view_name' => 'preview',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $this->app->instance('request', $request);

        $controller = new CustomViewController($this->table(), $request);
        $content = $controller->preview($request, self::TABLE);

        $this->assertInstanceOf(Content::class, $content);
        $rows = (new \ReflectionProperty(Content::class, 'rows'))->getValue($content);
        $this->assertCount(0, $rows, 'no grid - and so no export - is built for a preview');
    }
}
