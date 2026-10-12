<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Grid\GanttGrid;
use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Admin;
use ExmentAdminCore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Regression tests for the kanban board findings of the 2026-10-09 review of
 * the feature/workflow-visual-designer branch (K-01 .. K-14, the dot-key part
 * of L4, and the kanban part of finding 3: form rules on board writes).
 *
 * Each test drives the real board code against the seeded fixture tables and
 * fails on the code before the fix. Everything a test writes is rolled back.
 */
class KanbanFixTest extends UnitTestBase
{
    use DatabaseTransactions;

    /** fixture table with a workflow whose action3 sends a record back to start */
    protected const WORKFLOW_TABLE = 'custom_value_edit';

    /** fixture table with select, select_table and user columns */
    protected const COLUMNS_TABLE = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    // ------------------------------------------------------------------ K-01

    /**
     * K-01: a request that asks for a file is checked for the export right,
     * whatever its `action` says. Before the fix only action=export was
     * checked: view_export, plugin_export or no action at all went straight
     * to the exporter for a user without the right.
     *
     * Asked as a plugin export of a plugin that does not exist. Before the fix
     * the request reached the exporter, which failed on the missing plugin; a
     * view or table export would have sent the file and ended the process
     * with exit, which a test cannot survive.
     */
    public function testExportNeedsTheExportRightWhateverTheAction(): void
    {
        // user2 may read and edit the table, but has no export right
        $login = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $this->assertNotTrue($table->enableExport(), 'Precondition: user2 has no export right on the table.');

        $response = $this->actingAs($login, 'admin')->get(
            $this->dataUrl('?_export_=all&action=plugin_export&plugin_uuid=' . md5(uniqid())),
            $this->ajaxHeaders()
        );

        $this->assertSame(403, $response->getStatusCode(), 'An export without the export right must be refused before any exporter runs.');
    }

    /**
     * K-01 (board): the board answers an export request itself, so it checks
     * the right itself - and a preview of unsaved settings never exports.
     */
    public function testKanbanExportIsRefusedInPreviewAndWithoutTheRight(): void
    {
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW]);
        $this->setRequest($this->dataUrl('?_export_=all&action=export&format=csv'));

        // a preview, even for a user who may export
        $grid = $this->gridOf($view)->preview(true);
        $this->assertHttpStatus(403, function () use ($grid) {
            $grid->grid();
        }, 'A preview must never answer an export request.');

        // a saved board, for a user without the export right
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertHttpStatus(403, function () use ($view) {
            $this->gridOf($view)->grid();
        }, 'The board must not export for a user without the export right.');
    }

    // ------------------------------------------------------------------ K-02

    /**
     * K-02: saving a board as a view needs the same right as making any other
     * view (the view right, or the system switch for user views). A reader
     * with neither got a view created anyway.
     */
    public function testReaderWithoutViewRightCannotSaveTheBoardAsAView(): void
    {
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW]);
        $login = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $this->assertFalse(System::userview_available(), 'Precondition: user views are switched off.');
        $this->assertFalse($table->hasViewPermission(), 'Precondition: user2 has no view right on the table.');

        $board = $this->boardOf($view);
        $this->assertSame('', $board['save_view_url'], 'The board must not offer "Save this as a view" to user2.');

        $before = CustomView::withoutGlobalScopes()->where('custom_table_id', $table->id)->count();
        $response = $this->actingAs($login, 'admin')->post(
            $this->dataUrl('/kanbanSaveView'),
            ['view' => $view->suuid, 'name' => 'mine', 'state' => []],
            $this->ajaxHeaders()
        );

        $this->assertSame(403, $response->getStatusCode(), 'Saving the board as a view must be refused for user2.');
        $this->assertSame($before, CustomView::withoutGlobalScopes()->where('custom_table_id', $table->id)->count(), 'No view may be created.');
    }

    // ------------------------------------------------------------------ K-03

    /**
     * K-03: a record whose latest workflow step sent it back to the start
     * (a step with no status) sits in the start column. Before the fix the
     * start column only took records with no step at all: the record was
     * counted in the column head and never drawn.
     */
    public function testRecordSentBackToStartIsDrawnInTheStartColumn(): void
    {
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $record_id = $this->sendBackToStart($table, 5);

        $view = $this->createKanbanView(static::WORKFLOW_TABLE, [
            'kanban_source' => KanbanGrid::SOURCE_WORKFLOW,
            'kanban_col_count' => 200,
        ]);

        $grid = $this->gridOf($view);
        $start = $grid->moreCards(Define::WORKFLOW_START_KEYNAME, 0);
        $ids = array_map('strval', array_column($start['cards'], 'id'));
        $this->assertContains(strval($record_id), $ids, 'A record sent back to start must be drawn in the start column.');

        $stats = $this->gridOf($view)->columnStats();
        $flat = $this->plain($stats['flat']);
        $this->assertSame(
            count($ids),
            intval($flat[Define::WORKFLOW_START_KEYNAME]['total'] ?? -1),
            'The start column head must count exactly the cards the column can load.'
        );
    }

    // ------------------------------------------------------------------ K-04

    /**
     * K-04: the board's figures join the latest workflow step, and a view
     * filter on the workflow status joins workflow_values too. The status
     * column was read without a table in front and the query failed as
     * ambiguous (MySQL error 1052).
     */
    public function testBoardFiguresWorkWhenTheViewFiltersOnWorkflowStatus(): void
    {
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, [
            'kanban_source' => KanbanGrid::SOURCE_WORKFLOW,
            'kanban_col_count' => 20,
        ]);
        $this->addFilter($view, [
            'view_column_type' => ConditionType::WORKFLOW,
            'view_column_table_id' => $view->custom_table_id,
            'view_column_target_id' => SystemColumn::getOption(['name' => 'workflow_status'])['id'],
            'view_filter_condition' => FilterOption::WORKFLOW_EQ_STATUS,
            'view_filter_condition_value_text' => '6',
        ]);

        $stats = $this->gridOf($view)->columnStats();
        $flat = $this->plain($stats['flat']);
        $this->assertSame(2, intval(array_sum(array_column($flat, 'total'))), 'Two fixture records stand on status 6.');

        $cards = $this->gridOf($view)->moreCards('6', 0);
        $this->assertCount(2, $cards['cards'], 'The status 6 column must load its two records.');
    }

    /**
     * K-04: an embedded board narrows the query to one parent with
     * parent_type / parent_id. A view filter on a referenced table joins that
     * table, which has the same two columns, and the board failed as a whole.
     */
    public function testEmbeddedBoardWorksWithAFilterOnAReferencedTable(): void
    {
        $table = CustomTable::getEloquent('child_table_select');
        $group = CustomColumn::getEloquent('parent_select_table', $table);
        $view = $this->createKanbanView('child_table_select', [
            'kanban_group_column_id' => $group->id,
            'kanban_col_count' => 20,
        ]);
        $parent = CustomTable::getEloquent('parent_table_select');
        $this->addFilter($view, [
            'view_column_type' => ConditionType::COLUMN,
            'view_column_table_id' => $parent->id,
            'view_column_target_id' => CustomColumn::getEloquent('index_text', $parent)->id,
            'view_filter_condition' => FilterOption::LIKE,
            'view_filter_condition_value_text' => 'index',
            'options' => ['view_pivot_table_id' => $table->id, 'view_pivot_column_id' => $group->id],
        ]);

        $grid = $this->gridOf($view)->setEmbedRelation('parent_table', 1);
        $stats = $grid->columnStats();
        $this->assertIsArray($stats, 'The figures of an embedded board must be read without an sql error.');

        $grid = $this->gridOf($view)->setEmbedRelation('parent_table', 1);
        $more = $grid->moreCards(KanbanGrid::EMPTY_KEY, 0);
        $this->assertSame([], $more['cards'], 'No record of the fixture belongs to that parent.');
    }

    // ------------------------------------------------------------------ K-05

    /**
     * K-05: the people a board offers follow the column the way its select box
     * on the edit form does: users who may use the table. Before the fix the
     * list was every user of the system.
     */
    public function testPeopleListFollowsTheTableTheColumnBelongsTo(): void
    {
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, [
            'kanban_source' => KanbanGrid::SOURCE_WORKFLOW,
            'kanban_assignee_column_id' => CustomColumn::getEloquent('user', $table)->id,
        ]);

        $board = $this->boardOf($view);
        $offered = array_map('strval', array_column($board['assignees'], 'key'));
        sort($offered);
        $accessible = $table->getAccessibleUserIds();
        $expected = array_map('strval', $accessible instanceof \Illuminate\Support\Collection ? $accessible->toArray() : (array)$accessible);
        sort($expected);

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $offered, 'Only users who may use the table are offered as assignee.');
    }

    /**
     * K-05: a column pointing at a long table (past the select box limit) was
     * listed whole in the filter panel and the colors, every load, for every
     * viewer. Now such a list holds what the cards on the page hold, and the
     * column is not offered as a way to split the board.
     */
    public function testLongListsAreCutToTheValuesOnTheCards(): void
    {
        config(['exment.select_table_limit_count' => 20]);
        $table = CustomTable::getEloquent(static::COLUMNS_TABLE);
        $select_table = CustomColumn::getEloquent('select_table', $table);
        $target = CustomTable::getEloquent('custom_value_view_all');
        $this->assertGreaterThan(20, $target->getValueModel()->query()->count(), 'Precondition: the referenced table is long.');

        $view = $this->createKanbanView(static::COLUMNS_TABLE, [
            'kanban_group_column_id' => CustomColumn::getEloquent('select', $table)->id,
            'kanban_filter_column_ids' => [$select_table->id],
            'kanban_max_count' => 10,
        ]);

        $retrieved = $this->countRetrieved(getModelName($target), function () use ($view, &$board) {
            $board = $this->boardOf($view);
        });

        $on_cards = [];
        foreach ($board['cards'] as $card) {
            $value = strval($card['values']['select_table'] ?? '');
            if ($value !== '') {
                $on_cards[$value] = true;
            }
        }
        $filter = $this->metaNamed($board['filters'], 'select_table');
        $this->assertNotNull($filter, 'The chosen filter column is in the panel.');
        $offered = array_map('strval', array_column($filter['options'], 'key'));
        sort($offered);
        $expected = array_keys($on_cards);
        $expected = array_map('strval', $expected);
        sort($expected);
        $this->assertSame($expected, $offered, 'A long list holds exactly the values on the cards.');

        $this->assertNull(
            $this->metaNamed($board['groupables'], 'select_table'),
            'A long list is not offered as a way to split the board.'
        );
        $this->assertLessThan(
            $target->getValueModel()->query()->count(),
            $retrieved,
            'The referenced table must not be read whole to draw the board.'
        );
    }

    /**
     * K-05: "only mine" on a lookup table of people asked for the whole table
     * and matched the rows in PHP. It now asks the database for the rows of
     * the logged in user only.
     */
    public function testOnlyMineReadsOnlyTheRowsOfTheUser(): void
    {
        $table = CustomTable::getEloquent('child_table_select');
        $mine = CustomColumn::getEloquent('parent_select_table', $table);
        $view = $this->createKanbanView('child_table_select', [
            'kanban_group_column_id' => $mine->id,
            'kanban_mine_column_id' => $mine->id,
        ]);
        $target = CustomTable::getEloquent('parent_table_select');
        $this->assertGreaterThan(1, $target->getValueModel()->query()->count(), 'Precondition: the lookup table has several rows.');

        $keys = null;
        $retrieved = $this->countRetrieved(getModelName($target), function () use ($view, $mine, &$keys) {
            $keys = $this->invoke($this->gridOf($view), 'getMyKeys', [$mine]);
        });

        $expected = [];
        foreach ($target->getValueModel()->query()->get() as $row) {
            if (strval(array_get($row->value, 'user')) === strval(\Exment::getUserId())) {
                $expected[] = strval($row->id);
            }
        }
        $this->assertNotEmpty($expected, 'Precondition: the admin has a row in the lookup table.');
        $this->assertSame($expected, $keys, 'The rows standing for the logged in user.');
        $this->assertLessThanOrEqual(count($expected), $retrieved, 'The lookup must not load every row of the table to find the user.');
    }

    /**
     * K-05: a keyword is looked up in a long lookup table through the view the
     * column's select box uses, once for every board column. That view is
     * cached for the request and its search service remembers the joins it
     * made: from the second board column on, a view sorted by a column of a
     * parent table lost its join, kept the sort, and the search failed with
     * an SQL error.
     */
    public function testKeywordSearchWorksWhenTheLookupViewSortsByAParentColumn(): void
    {
        $table = CustomTable::getEloquent('pivot_table');
        $lookup = CustomColumn::getEloquent('child_ajax', $table);
        $sorted = CustomView::where('view_view_name', 'child_table-parent-sort')->first();
        $this->assertInstanceOf(CustomView::class, $sorted);
        $this->assertTrue(
            $sorted->custom_view_sorts->contains(function ($sort) use ($sorted) {
                return strval($sort->view_column_table_id) !== strval($sorted->custom_table_id);
            }),
            'Precondition: the lookup view sorts by a column of another table.'
        );
        $options = $lookup->options ?? [];
        $options['select_target_view'] = $sorted->id;
        \DB::table('custom_columns')->where('id', $lookup->id)->update(['options' => json_encode($options)]);

        $view = $this->createKanbanView('pivot_table', [
            'kanban_group_column_id' => CustomColumn::getEloquent('parent', $table)->id,
            // every match fits in its column, so a missing card is a lost one
            'kanban_col_count' => 100,
        ], [
            ['column' => 'child_ajax'],
        ]);
        $grid = $this->gridOf($view);
        $lookup = CustomColumn::getEloquent('child_ajax', $view->custom_table);
        $this->assertSame(strval($sorted->id), strval($lookup->getOption('select_target_view')));
        $this->assertTrue($this->invoke($grid, 'isLongList', [$lookup]), 'Precondition: the lookup column is searched, not listed.');
        $group_column = $this->invoke($grid, 'getGroupColumn');
        $board_keys = $this->invoke($grid, 'getBoardKeys', [$group_column, null, $this->invoke($grid, 'getHideKeys', [$group_column, null])]);
        $this->assertGreaterThan(1, count($board_keys), 'Precondition: the board has several columns.');

        // keyword => card: the searchable text of the record a card points at
        $target = CustomTable::getEloquent('child_table');
        $cases = [];
        foreach ($table->getValueModel()->query()->orderBy('id')->take(20)->get() as $row) {
            $record = $target->getValueModel()->find(array_get($row->value, 'child_ajax'));
            $keyword = isset($record) ? strval(array_get($record->value, 'index_text')) : '';
            if ($keyword !== '' && !array_key_exists($keyword, $cases)) {
                $cases[$keyword] = strval($row->id);
            }
            if (count($cases) >= 2) {
                break;
            }
        }
        $this->assertCount(2, $cases, 'Precondition: two cards point at lookup records with different text.');

        // the second keyword cannot be answered from what the first one found
        foreach ($cases as $keyword => $expected_id) {
            $result = $grid->searchCards($keyword);
            $ids = array_map('strval', array_column($result['cards'], 'id'));
            $this->assertContains($expected_id, $ids, "The card whose lookup value holds '{$keyword}' is found.");
        }
    }

    // ------------------------------------------------------------------ K-06

    /**
     * K-06: the settings form only offers short lookup tables as board
     * columns, but a view keeps working with one that has grown. The stored
     * column was missing from the select, so the form showed the required
     * group column empty and saved the swimlane and AI columns empty.
     */
    public function testSettingsFormKeepsStoredColumnsOfALongTable(): void
    {
        config(['exment.select_table_limit_count' => 20]);
        $table = CustomTable::getEloquent(static::COLUMNS_TABLE);
        $group = CustomColumn::getEloquent('select_table', $table);
        $lane = CustomColumn::getEloquent('select_table_2', $table);
        $view = $this->createKanbanView(static::COLUMNS_TABLE, [
            'kanban_group_column_id' => $group->id,
            'kanban_swimlane_column_id' => $lane->id,
            'kanban_ai_column_id' => $group->id,
        ]);

        $this->bootAdmin();
        $form = new Form(new CustomView());
        KanbanGrid::setViewForm(ViewKindType::KANBAN, $form, $table, ['custom_view' => $view]);

        foreach (['kanban_group_column_id' => $group, 'kanban_swimlane_column_id' => $lane, 'kanban_ai_column_id' => $group] as $name => $column) {
            $field = $form->fields()->first(function ($field) use ($name) {
                return $field->column() === $name;
            });
            $this->assertNotNull($field, "The form has the $name field.");
            $this->assertArrayHasKey($column->id, $field->getOptions(), "The column stored in $name must stay selectable.");
        }
    }

    // ------------------------------------------------------------------ K-07

    /**
     * K-07: the board names its own view when it is saved as a new one. A
     * request that names none was answered with whichever view the user had
     * opened last - another board in another tab.
     */
    public function testSaveViewRequestMustNameTheBoard(): void
    {
        $login = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $board = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW, 'kanban_kpi' => '0']);
        $other = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW, 'kanban_kpi' => '1']);
        // the view the user opened last is the other board
        $login->setSettingValue(UserSetting::VIEW . '.' . static::WORKFLOW_TABLE, $other->suuid);

        $response = $this->actingAs($login, 'admin')->post(
            $this->dataUrl('/kanbanSaveView'),
            ['name' => 'no view named', 'state' => []],
            $this->ajaxHeaders()
        );
        $this->assertSame(404, $response->getStatusCode(), 'A save that names no board must not copy the last opened view.');

        $response = $this->actingAs($login, 'admin')->post(
            $this->dataUrl('/kanbanSaveView'),
            ['view' => $board->suuid, 'name' => 'copy of the board', 'state' => []],
            $this->ajaxHeaders()
        );
        $this->assertSame(200, $response->getStatusCode());
        $saved = CustomView::withoutGlobalScopes()->where('suuid', $response->json('suuid'))->first();
        $this->assertNotNull($saved);
        $this->assertSame('0', strval($saved->getOption('kanban_kpi')), 'The copy must be of the board that was named.');
    }

    // ------------------------------------------------------------------ K-08

    /**
     * K-08: the view name is stored in a column of 40 characters. A longer
     * name passed validation; the database then failed with a server error
     * (strict sql mode) or cut the name short without a word (non-strict).
     */
    public function testSaveViewNameIsLimitedToTheStoredLength(): void
    {
        $login = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW]);

        $response = $this->actingAs($login, 'admin')->post(
            $this->dataUrl('/kanbanSaveView'),
            ['view' => $view->suuid, 'name' => str_repeat('a', 41), 'state' => []],
            $this->ajaxHeaders()
        );

        $this->assertSame(422, $response->getStatusCode(), 'A name longer than 40 characters is a validation error.');
    }

    // ------------------------------------------------------------------ K-09

    /**
     * K-09: a board saved as a new view keeps the names its card fields were
     * given. They were dropped, so the saved board read differently.
     */
    public function testSavedBoardKeepsCardColumnNames(): void
    {
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW], [
            ['column' => 'text', 'name' => 'Card title'],
        ]);

        $saved = $this->gridOf($view)->saveAsView('saved board', []);

        $columns = CustomViewColumn::where('custom_view_id', $saved->id)->get();
        $this->assertCount(1, $columns);
        $this->assertSame('Card title', $columns->first()->view_column_name, 'The card field keeps its name.');
        $this->assertSame(CustomColumn::getEloquent('text', $table)->id, intval($columns->first()->view_column_target_id));
    }

    // ------------------------------------------------------------------ K-10

    /**
     * K-10: a card field carries its plain text for the painter, which puts it
     * in as text. It was taken from escaped markup, so "R&D" read "R&amp;D".
     */
    public function testCardTextIsNotEscapedTwice(): void
    {
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $record = $table->getValueModel()->query()->find(7);
        $value = $record->value;
        $value['text'] = 'R&D <b>';
        \DB::table(getDBTableName($table))->where('id', 7)->update(['value' => json_encode($value)]);

        $view = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW], [
            ['column' => 'text'],
        ]);

        $cards = $this->gridOf($view)->cardsOf([7]);
        $this->assertCount(1, $cards['cards']);
        $field = $this->metaNamed($cards['cards'][0]['fields'], 'text');
        $this->assertNotNull($field);
        $this->assertSame('R&D <b>', $field['text'], 'The plain text is the value as typed.');
        $this->assertStringNotContainsString('<b>', $field['html'], 'The markup stays escaped.');
    }

    // ------------------------------------------------------------------ K-12

    /**
     * K-12: a board column is read one slice at a time. With a sort that
     * does not tell every record apart, the database may order tied rows
     * differently per slice, and "load more" skipped records. The id now
     * settles every tie.
     */
    public function testColumnSlicesAreOrderedByIdAfterTheViewSort(): void
    {
        $table = CustomTable::getEloquent(static::WORKFLOW_TABLE);
        $view = $this->createKanbanView(static::WORKFLOW_TABLE, [
            'kanban_source' => KanbanGrid::SOURCE_WORKFLOW,
            'kanban_col_count' => 7,
        ]);
        CustomViewSort::create([
            'custom_view_id' => $view->id,
            'view_column_type' => ConditionType::COLUMN,
            'view_column_table_id' => $table->id,
            'view_column_target_id' => CustomColumn::getEloquent('odd_even', $table)->id,
            'sort' => 1,
            'priority' => 1,
        ]);
        $view = $this->reloadView($view);

        $grid = $this->gridOf($view);
        $workflow = Workflow::getWorkflowByTable($table);
        $query = $this->invoke($grid, 'buildColumnQuery', [null, $workflow, [], Define::WORKFLOW_START_KEYNAME]);
        $orders = $query->getQuery()->orders ?? [];
        $this->assertNotEmpty($orders);
        $last = end($orders);
        $this->assertSame(getDBTableName($table) . '.id', $last['column'] ?? null, 'The last order must be the record id.');

        // every record of the column, each exactly once, slice after slice
        $seen = [];
        $offset = 0;
        do {
            $slice = $this->gridOf($view)->moreCards(Define::WORKFLOW_START_KEYNAME, $offset);
            foreach ($slice['cards'] as $card) {
                $seen[] = intval($card['id']);
            }
            $offset += 7;
        } while ($slice['has_more'] && $offset < 500);
        $this->assertSame(count($seen), count(array_unique($seen)), 'No record may come back twice.');
        $all = $this->invoke($this->gridOf($view), 'buildColumnQuery', [null, $workflow, [], Define::WORKFLOW_START_KEYNAME])->count();
        $this->assertSame($all, count($seen), 'No record may be skipped.');
    }

    // ------------------------------------------------------------------ K-14

    /**
     * K-14: the calls a board makes in the background name its view to say
     * which board asks. They made that view the one the table opens with for
     * the user - a board embedded in a portal took over the table's own list.
     */
    public function testBoardCallsDoNotChangeTheRememberedView(): void
    {
        $login = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $remembered = $this->createKanbanView(static::WORKFLOW_TABLE, ['kanban_source' => KanbanGrid::SOURCE_WORKFLOW]);
        $board = $this->createKanbanView(static::WORKFLOW_TABLE, [
            'kanban_source' => KanbanGrid::SOURCE_WORKFLOW,
            'kanban_col_count' => 10,
        ]);
        $key = UserSetting::VIEW . '.' . static::WORKFLOW_TABLE;
        $login->setSettingValue($key, $remembered->suuid);

        $response = $this->actingAs($login, 'admin')->get(
            $this->dataUrl('/kanbanCards?view=' . $board->suuid . '&stats=1'),
            $this->ajaxHeaders()
        );
        $this->assertSame(200, $response->getStatusCode());

        System::clearRequestSession();
        $fresh = LoginUser::find($login->id);
        $this->assertInstanceOf(LoginUser::class, $fresh);
        $this->assertSame($remembered->suuid, $fresh->getSettingValue($key), 'The remembered view must stay.');
    }

    // -------------------------------------------------------------- L4 (dot)

    /**
     * L4: per-value colors were looked up with a dotted path, so a value that
     * holds a dot ("v1.5") never got its color on the board.
     */
    public function testBoardValueColorsWorkForValuesWithADot(): void
    {
        $select = $this->selectWithDottedValueColor();

        $view = $this->createKanbanView(static::COLUMNS_TABLE, [
            'kanban_group_column_id' => $select->id,
            'kanban_labels_column_id' => $select->id,
            'kanban_max_count' => 5,
        ]);
        $board = $this->boardOf($view);
        $this->assertSame('#ff0000', $board['colors']['select']['v1.5'] ?? null, 'The board must use the color set for "v1.5".');
    }

    /**
     * L4: the same dotted lookup on the gantt chart's bar colors.
     */
    public function testChartValueColorsWorkForValuesWithADot(): void
    {
        $select = $this->selectWithDottedValueColor();
        $table = CustomTable::getEloquent(static::COLUMNS_TABLE);

        $gantt_view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'gantt-unittest',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::GANTT,
            'options' => [],
        ]);
        $gantt = new GanttGrid($table, $gantt_view);
        list($colors) = $this->invoke($gantt, 'buildColors', [$select]);
        $this->assertSame('#ff0000', $colors['v1.5'] ?? null, 'The chart must use the color set for "v1.5".');
    }

    // ------------------------------------------------------- finding 3 board

    /**
     * Finding 3 (board part): a move, an assignment or a due date set on the
     * board stands in for the record's edit page. The board marks the columns
     * the record's form keeps out of reach, so it does not offer them; the
     * card follows the form its own record opens with.
     */
    public function testCardsCarryTheColumnsTheirFormLocks(): void
    {
        $table = CustomTable::getEloquent(static::COLUMNS_TABLE);
        $select = CustomColumn::getEloquent('select', $table);
        $default_form = CustomForm::getDefault($table);
        $this->setFormColumnOptions($default_form, $select, ['view_only' => '1']);

        $view = $this->createKanbanView(static::COLUMNS_TABLE, [
            'kanban_group_column_id' => $select->id,
            'kanban_max_count' => 5,
        ]);

        // the admin opens every record with the default form
        $board = $this->boardOf($view);
        $this->assertNotEmpty($board['cards']);
        foreach ($board['cards'] as $card) {
            $locked = array_merge($board['locked_columns'] ?? [], $card['locked'] ?? []);
            $this->assertContains('select', $locked, 'A column the form shows view only is locked on the card.');
        }

        // a member of the "dev" organization opens them with the form a
        // priority picks for that organization, which leaves the column open
        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $board = $this->boardOf($view);
        $this->assertNotEmpty($board['cards']);
        foreach ($board['cards'] as $card) {
            $locked = array_merge($board['locked_columns'] ?? [], $card['locked'] ?? []);
            $this->assertNotContains('select', $locked, "The card follows the form its own record opens with.");
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The fixture select column, given a choice with a dot in it and a color
     * for that choice.
     *
     * @return CustomColumn
     */
    protected function selectWithDottedValueColor()
    {
        $table = CustomTable::getEloquent(static::COLUMNS_TABLE);
        $select = CustomColumn::getEloquent('select', $table);
        $select->setOption('select_item', "v1.5\r\nplain");
        $select->setOption('grid_value_colors', 'v1.5,#ff0000');
        $select->save();
        System::clearCache();

        return CustomColumn::getEloquent('select', $table);
    }

    /**
     * @param string $user_id
     * @return LoginUser
     */
    protected function login($user_id)
    {
        $login = LoginUser::find($user_id);
        $this->assertInstanceOf(LoginUser::class, $login);
        $this->be($login, 'admin');
        System::clearRequestSession();
        System::clearCache();
        return $login;
    }

    /**
     * A kanban view of the table, with card columns.
     *
     * @param string $table_name
     * @param array<string, mixed> $options view options (kanban_* settings)
     * @param array<int, array<string, mixed>> $columns ['column' => name, 'name' => label]
     * @return CustomView
     */
    protected function createKanbanView(string $table_name, array $options, array $columns = [])
    {
        $table = CustomTable::getEloquent($table_name);
        $view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'kanban-unittest-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::KANBAN,
            'options' => array_merge(['kanban_editable' => '1'], $options),
        ]);
        foreach ($columns as $index => $column) {
            CustomViewColumn::create([
                'custom_view_id' => $view->id,
                'view_column_type' => ConditionType::COLUMN,
                'view_column_table_id' => $table->id,
                'view_column_target_id' => CustomColumn::getEloquent($column['column'], $table)->id,
                'view_column_name' => $column['name'] ?? null,
                'order' => $index + 1,
            ]);
        }

        return $this->reloadView($view);
    }

    /**
     * A value the way the browser receives it: objects and lists as json
     * makes them.
     *
     * @param mixed $value
     * @return array<mixed>
     */
    protected function plain($value): array
    {
        $json = json_encode($value);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * The entry of a list with the given name, or null.
     *
     * @param array<int, array<string, mixed>> $list
     * @param string $name
     * @return array<string, mixed>|null
     */
    protected function metaNamed(array $list, string $name): ?array
    {
        foreach ($list as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param CustomView $view
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function addFilter(CustomView $view, array $attributes)
    {
        CustomViewFilter::create(array_merge(['custom_view_id' => $view->id], $attributes));
    }

    /**
     * @param CustomView $view
     * @return CustomView
     */
    protected function reloadView(CustomView $view)
    {
        System::clearRequestSession();
        System::clearCache();
        $fresh = CustomView::withoutGlobalScopes()->find($view->id);
        $this->assertInstanceOf(CustomView::class, $fresh);
        return $fresh;
    }

    /**
     * A fresh board object of the view: the view caches the one it made.
     *
     * @param CustomView $view
     * @return KanbanGrid
     */
    protected function gridOf(CustomView $view)
    {
        $view = $this->reloadView($view);
        return new KanbanGrid($view->custom_table, $view);
    }

    /**
     * The payload the board is drawn from, built the way KanbanGrid::grid()
     * builds it, without the toolbar.
     *
     * @param CustomView $view
     * @return array<string, mixed>
     */
    protected function boardOf(CustomView $view)
    {
        $grid = $this->gridOf($view);
        $group_column = null;
        $workflow = null;
        if ($this->invoke($grid, 'getSource') == KanbanGrid::SOURCE_WORKFLOW) {
            $workflow = Workflow::getWorkflowByTable($view->custom_table);
        } else {
            $group_column = $this->invoke($grid, 'getGroupColumn');
        }
        $hide_keys = $this->invoke($grid, 'getHideKeys', [$group_column, $workflow]);
        $col_count = $this->invoke($grid, 'getColCount');
        if ($col_count > 0) {
            $records = collect();
            foreach ($this->invoke($grid, 'getBoardKeys', [$group_column, $workflow, $hide_keys]) as $key) {
                $records = $records->merge($this->invoke($grid, 'buildColumnQuery', [$group_column, $workflow, $hide_keys, $key])->take($col_count)->get());
            }
        } else {
            $max = $this->invoke($grid, 'getMaxCount');
            $records = $this->invoke($grid, 'newBoardQuery', [$group_column, $workflow, $hide_keys])->take($max)->get();
        }

        $board = $this->invoke($grid, 'buildBoard', [$group_column, $workflow, $records, $hide_keys]);
        // the board is sent as json: objects and lists as the browser sees them
        return $this->plain($board);
    }

    /**
     * Mark a record as sent back to the start: its latest workflow step names
     * no status, the way the core writes an action that returns to start.
     *
     * @param CustomTable $table
     * @param int $id
     * @return int
     */
    protected function sendBackToStart(CustomTable $table, int $id): int
    {
        $workflow = Workflow::getWorkflowByTable($table);
        $action = $workflow->workflow_actions_cache->first(function ($action) {
            return $action->action_name == 'action3';
        });
        $this->assertNotNull($action, 'Precondition: the fixture workflow has the send-back action.');
        \DB::table('workflow_values')->where('morph_type', $table->table_name)->where('morph_id', $id)->update(['latest_flg' => 0]);
        \DB::table('workflow_values')->insert([
            'workflow_id' => $workflow->id,
            'morph_type' => $table->table_name,
            'morph_id' => $id,
            'workflow_action_id' => $action->id,
            'workflow_status_from_id' => $action->status_from,
            'workflow_status_to_id' => null,
            'action_executed_flg' => 0,
            'latest_flg' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'created_user_id' => 1,
            'updated_user_id' => 1,
        ]);
        System::clearRequestSession();

        return $id;
    }

    /**
     * Set the options of one column on the default block of a form.
     *
     * @param CustomForm $form
     * @param CustomColumn $column
     * @param array<string, mixed> $options
     * @return void
     */
    protected function setFormColumnOptions(CustomForm $form, CustomColumn $column, array $options)
    {
        $block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->first();
        $this->assertNotNull($block);
        $updated = \DB::table('custom_form_columns')
            ->where('custom_form_block_id', $block->id)
            ->where('form_column_target_id', $column->id)
            ->update(['options' => json_encode($options)]);
        $this->assertSame(1, $updated, 'Precondition: the form shows the column.');
        System::clearRequestSession();
        System::clearCache();
    }

    /**
     * How many models of a class Eloquent hydrates while the callback runs.
     *
     * @param string $class
     * @param \Closure $callback
     * @return int
     */
    protected function countRetrieved(string $class, \Closure $callback): int
    {
        $count = 0;
        $listener = function ($event, $payload) use ($class, &$count) {
            if (isset($payload[0]) && $payload[0] instanceof $class) {
                $count++;
            }
        };
        \Event::listen('eloquent.retrieved: *', $listener);
        try {
            $callback();
        } finally {
            \Event::forget('eloquent.retrieved: *');
        }

        return $count;
    }

    /**
     * @param object $object
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    protected function invoke($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    /**
     * @param int $status
     * @param \Closure $callback
     * @param string $message
     * @return void
     */
    protected function assertHttpStatus(int $status, \Closure $callback, string $message)
    {
        try {
            $callback();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $message);
            return;
        }
        $this->fail($message . ' (no http error was raised)');
    }

    /**
     * @param string $suffix
     * @return string
     */
    protected function dataUrl(string $suffix = ''): string
    {
        return '/' . trim(config('admin.route.prefix', 'admin'), '/') . '/data/' . static::WORKFLOW_TABLE . $suffix;
    }

    /**
     * @return array<string, string>
     */
    protected function ajaxHeaders(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
    }

    /**
     * Bind a request with the given url, as the board reads its query.
     *
     * @param string $url
     * @return void
     */
    protected function setRequest(string $url)
    {
        $request = Request::create($url, 'GET');
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        app('url')->setRequest($request);
    }

    /**
     * Register the admin form fields, as the Initialize middleware does.
     *
     * @return void
     */
    protected function bootAdmin()
    {
        Initialize::initializeConfig();
        Initialize::registeredLaravelAdmin();
        app(Admin::class)->bootstrap();
        view()->share('errors', new ViewErrorBag());
        System::clearRequestSession();
    }
}
