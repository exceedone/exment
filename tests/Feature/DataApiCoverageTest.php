<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The data and table API calls that no test had sent. Most are the screens' own ajax calls
 * (admin/webapi/..., the logged-in session); the select, column and related-linkage calls are
 * checked through the token API (admin/api/...), which is the prefix their rows name.
 */
class DataApiCoverageTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        Notification::fake();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testTheColumnListsOfATable(): void
    {
        $table = $this->table();

        $names = collect($this->listOf($this->webapi('table/columns?q=' . $table->id)->assertOk()))->pluck('column_name');
        $this->assertEqualsCanonicalizing($table->custom_columns()->pluck('column_name')->all(), $names->all());
        $this->assertContains('text', $names->all());

        // only the columns with an index: the text column has none, index_text has one
        $index = collect($this->listOf($this->webapi('table/indexcolumns?q=' . $table->id)->assertOk()))->pluck('column_name')->all();
        $this->assertContains('index_text', $index);
        $this->assertNotContains('text', $index);
        $this->assertEqualsCanonicalizing($table->custom_columns()->indexEnabled()->pluck('column_name')->all(), $index);
    }

    public function testTheFilterViewsAndOneViewOfATable(): void
    {
        $table = $this->table();
        $filter = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'cov filter view',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::FILTER,
        ]);
        $alldata = CustomView::where('custom_table_id', $table->id)->where('view_kind_type', ViewKindType::ALLDATA)->first();
        $this->assertNotNull($alldata);

        $ids = collect($this->listOf($this->webapi('table/filterviews?q=' . $table->id)->assertOk()))->pluck('id')->all();
        $this->assertContains($filter->id, $ids);
        $this->assertNotContains($alldata->id, $ids);

        // a view by its id, and by its suuid
        $this->webapi('view/' . $filter->id)->assertOk()->assertJson(['id' => $filter->id, 'view_view_name' => 'cov filter view']);
        $this->webapi('view/' . $filter->suuid)->assertOk()->assertJson(['id' => $filter->id, 'suuid' => $filter->suuid]);
    }

    public function testTheColumnsOfTheTableASelectColumnPointsAt(): void
    {
        // all_columns_table_fortest.select_table_2 selects custom_value_edit
        $column = CustomColumn::getEloquent('select_table_2', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $this->assertNotNull($column);
        $target = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->assertNotNull($target);

        $columns = collect($this->listOf($this->webapi('target_table/columns/' . $column->id)->assertOk()));
        $this->assertEqualsCanonicalizing($target->custom_columns()->pluck('id')->all(), $columns->pluck('view_id')->all());
        $this->assertContains('text', $columns->pluck('view_name')->all());
    }

    public function testTheNavbarNotificationsAndWorkflowTasks(): void
    {
        $user_id = \Exment::getUserId();
        // the navbar lists the user's unread notifications; leave only one unread
        NotifyNavbar::where('target_user_id', $user_id)->update(['read_flg' => 1]);
        $notify = Notify::where('notify_trigger', NotifyTrigger::BUTTON)->first();
        $this->assertNotNull($notify);
        $navbar = new NotifyNavbar();
        $navbar->notify_id = $notify->id;
        $navbar->target_user_id = $user_id;
        $navbar->trigger_user_id = $user_id;
        $navbar->notify_subject = 'cov navbar subject';
        $navbar->notify_body = 'cov navbar body';
        $navbar->read_flg = 0;
        $navbar->save();

        $page = $this->webapi('notifyPage')->assertOk()->assertJson(['count' => 1]);
        $this->assertSame('cov navbar subject', $page->json('items.0.label'));
        $this->assertSame(admin_urls('notify_navbar', $navbar->id), $page->json('items.0.href'));

        // a record the admin creates waits for the admin's first workflow action
        $this->createValue(['text' => 'cov task', 'index_text' => 'cov task label']);
        $cacheKey = WorkflowTaskService::navbarCacheKey();
        if (!is_null($cacheKey)) {
            Cache::forget($cacheKey);
        }
        $tasks = $this->webapi('workflowTaskPage')->assertOk()->assertJson(['noItemMessage' => exmtrans('workflow_task.empty')]);
        $this->assertGreaterThanOrEqual(1, $tasks->json('count'));
        $this->assertNotEmpty($tasks->json('items'));
        $this->assertStringContainsString(admin_url('workflow_task/read') . '?key=', (string) $tasks->json('items.0.href'));
    }

    public function testTheUserAndOrganizationSelect(): void
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($admin);
        $user_code = $admin->base_user?->getValue('user_code');
        $this->assertNotEmpty($user_code);

        $ids = collect($this->listOf($this->webapi('user_organization/select?q=' . urlencode((string) $user_code))->assertOk(), 'data'))->pluck('id')->all();
        $this->assertContains('user_' . $admin->base_user_id, $ids);

        // the keyword is required
        $this->webapi('user_organization/select')->assertStatus(400);
    }

    public function testTheCalendarCellAndRecordCallsOfTheScreens(): void
    {
        $table = $this->table();
        $custom_value = $this->createValue(['text' => 'cov calendar text', 'index_text' => 'cov calendar label', 'date' => '2026-10-10']);

        // the calendar view the screen shows, on the date column
        $date = CustomColumn::getEloquent('date', static::TABLE);
        $this->assertNotNull($date);
        $calendar = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'cov calendar',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::CALENDAR,
        ]);
        $view_column = new CustomViewColumn();
        $view_column->custom_view_id = $calendar->id;
        $view_column->view_column_type = ConditionType::COLUMN;
        $view_column->view_column_table_id = $table->id;
        $view_column->view_column_target_id = $date->id;
        $view_column->order = 1;
        $view_column->save();

        $response = $this->webapi('data/' . static::TABLE . '/calendar?view=' . $calendar->suuid . '&start=2026-10-01&end=2026-11-01')->assertOk();
        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($decoded);
        $tasks = collect($decoded);
        $task = $tasks->first(fn ($task) => array_get($task, 'id') == $custom_value->id);
        $this->assertNotNull($task, 'the record of 2026-10-10 is not on the October calendar');
        $this->assertSame(admin_url('data', [static::TABLE, $custom_value->id]), $task['url']);
        $this->assertTrue($tasks->every(fn ($task) => array_get($task, 'value.date') >= '2026-10-01' && array_get($task, 'value.date') <= '2026-11-01'));

        // one grid cell, drawn again after an inline edit
        $cell = $this->webapi('data/' . static::TABLE . '/cell/' . $custom_value->id . '/text')->assertOk();
        $this->assertStringContainsString('cov calendar text', (string) $cell->json('html'));

        // the record, asked for by POST as the form's linkage does
        System::clearRequestSession();
        $this->post(admin_urls('webapi', 'data', static::TABLE, $custom_value->id), [], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJson(['id' => $custom_value->id, 'value' => ['text' => 'cov calendar text']]);
    }

    public function testTheSelectColumnAndLinkageCallsOfTheTokenApi(): void
    {
        $custom_value = $this->createValue(['text' => 'cov api text', 'index_text' => 'covapi-select-value']);
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        $headers = ['Authorization' => "Bearer {$token}"];

        // the select box search
        $data = collect($this->listOf($this->withHeaders($headers)->get(admin_urls('api', 'data', static::TABLE, 'select') . '?q=covapi-select-value')->assertOk(), 'data'));
        $this->assertContains($custom_value->id, $data->pluck('id')->all());

        // the values of one indexed column that match
        $values = json_decode((string) $this->withHeaders($headers)->get(admin_urls('api', 'data', static::TABLE, 'column', 'index_text') . '?query=covapi-select-value')->assertOk()->getContent(), true);
        $this->assertIsArray($values);
        $this->assertContains('covapi-select-value', $values);

        // the children of the chosen parent, for a child select that is filtered by its parent
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $this->assertNotNull($parent_table);
        $this->assertNotNull($child_table);
        $child_column = CustomColumn::getEloquent(TestDefine::TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER, TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE);
        $this->assertNotNull($child_column);
        $parent = $parent_table->getValueModel()->query()->orderBy('id')->first();
        $this->assertNotNull($parent);
        $children = $child_table->getValueModel()->query()
            ->where('parent_id', $parent->id)
            ->where('parent_type', $parent_table->table_name)
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($children);

        $linked = $this->withHeaders($headers)->get(admin_urls('api', 'data', $parent_table->table_name, 'relatedLinkage') . '?' . http_build_query([
            'q' => $parent->id,
            'parent_select_table_id' => $parent_table->id,
            'child_select_table_id' => $child_table->id,
            'child_column_id' => $child_column->id,
            'search_type' => SearchType::ONE_TO_MANY,
        ]))->assertOk();
        $this->assertEqualsCanonicalizing($children, collect($this->listOf($linked))->pluck('id')->all());
    }

    public function testTheViewsOfATableByTheTokenApi(): void
    {
        $table = $this->table();
        $views = CustomView::where('custom_table_id', $table->id)->get();
        $this->assertGreaterThan(1, $views->count());
        $token = $this->getAdminAccessToken([ApiScope::VIEW_READ]);
        $url = admin_urls('api', 'table', static::TABLE, 'views');
        $ids = fn (array $query = []) => collect($this->listOf(
            $this->withHeaders(['Authorization' => "Bearer {$token}"])->get($url . ($query === [] ? '' : '?' . http_build_query($query)))->assertOk()
        ))->pluck('id')->all();

        $this->assertEqualsCanonicalizing($views->pluck('id')->all(), $ids());
        // the filters the api takes
        $alldata = $views->where('view_kind_type', ViewKindType::ALLDATA)->pluck('id')->all();
        $this->assertGreaterThan(0, count($alldata));
        $this->assertEqualsCanonicalizing($alldata, $ids(['view_kind_type' => ViewKindType::ALLDATA]));
        $name = strval($views->last()?->view_view_name);
        $this->assertEqualsCanonicalizing($views->where('view_view_name', $name)->pluck('id')->all(), $ids(['view_view_name' => $name]));
    }

    public function testTheQuickAddOfASelectBoxRefusesWhatTheTableWouldRefuse(): void
    {
        // custom_value_edit: index_text is the label column, and text is required too
        $table = $this->quickAddTable();
        $url = admin_urls('webapi', $table->table_name, 'quickadd');
        $before = $this->recordCount($table);

        // without a label there is nothing to add
        $empty = $this->quickAdd($url, '')->assertStatus(400);
        $this->assertArrayHasKey('label', (array) $empty->json('errors'));
        // a label alone does not fill the required text column: refused and nothing stored
        $refused = $this->quickAdd($url, 'cov quick add')->assertStatus(400)->assertJsonPath('code', ErrorCode::VALIDATION_ERROR()->getValue());
        $this->assertArrayHasKey('text', (array) $refused->json('errors'));
        $this->assertSame($before, $this->recordCount($table));
    }

    public function testTheQuickAddOfASelectBoxMakesOneRecordForALabel(): void
    {
        // with text optional the label is all the table needs. Changed before the first request:
        // the route keeps its controller, and the table that controller read, for the whole test
        $table = $this->quickAddTable();
        $text = CustomColumn::getEloquent('text', $table);
        $this->assertNotNull($text);
        DB::table('custom_columns')->where('id', $text->id)->update(['options' => json_encode(array_merge($text->options ?? [], ['required' => '0']))]);
        System::clearCache();
        $url = admin_urls('webapi', $table->table_name, 'quickadd');
        $before = $this->recordCount($table);

        $created = $this->quickAdd($url, 'cov quick add')->assertOk()->assertJson(['created' => true, 'label' => 'cov quick add']);
        $record = $table->getValueModel()->query()->withoutGlobalScopes()->findOrFail(intval($created->json('id')));
        $this->assertSame('cov quick add', $record->getValue('index_text'));
        // the same label again gives that record instead of a second one
        $this->quickAdd($url, 'cov quick add')->assertOk()->assertJson(['id' => $record->id, 'label' => 'cov quick add', 'created' => false]);
        $this->assertSame($before + 1, $this->recordCount($table));
    }

    public function testTheRelationSearchOfASearchResult(): void
    {
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $this->assertNotNull($parent_table);
        $this->assertNotNull($child_table);
        $parent = $parent_table->getValueModel()->query()->orderBy('id')->first();
        $this->assertNotNull($parent);
        $children = $child_table->getValueModel()->query()
            ->where('parent_id', $parent->id)
            ->where('parent_type', $parent_table->table_name)
            ->pluck('id')
            ->all();
        $this->assertGreaterThan(0, count($children));

        // the children of the chosen record, a page of them, with the link to their list
        $related = $this->relationSearch($parent_table, $parent->id, $child_table, SearchType::ONE_TO_MANY)
            ->assertOk()
            ->assertJsonPath('table_name', $child_table->table_name);
        $listed = $this->listedIds($related);
        $this->assertCount(min(count($children), intval(System::datalist_pager_count() ?? 5)), $listed);
        $this->assertSame([], array_diff($listed, $children), 'only children of the chosen record are listed');
        $this->assertStringContainsString(admin_url("data/{$child_table->table_name}"), strval($related->json('header')));

        // the chosen record itself
        $self = $this->relationSearch($parent_table, $parent->id, $parent_table, SearchType::SELF)->assertOk();
        $this->assertSame([$parent->id], $this->listedIds($self));

        // a record without children
        $lonely = $parent_table->getValueModel();
        $lonely->setValue(['text' => 'cov relation search lonely'])->save();
        $this->relationSearch($parent_table, $lonely->id, $child_table, SearchType::ONE_TO_MANY)
            ->assertOk()
            ->assertJsonPath('body', exmtrans('search.no_result'));
    }

    protected function quickAddTable(): CustomTable
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->assertNotNull($table);

        return $table;
    }

    protected function recordCount(CustomTable $table): int
    {
        return $table->getValueModel()->query()->withoutGlobalScopes()->count();
    }

    /**
     * Post a label the way quickadd.js does.
     *
     * @return TestResponse<Response>
     */
    protected function quickAdd(string $url, string $label): TestResponse
    {
        System::clearRequestSession();

        return $this->post($url, ['label' => $label], ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * The related records the search result page loads for a record it found.
     *
     * @return TestResponse<Response>
     */
    protected function relationSearch(CustomTable $value_table, int $value_id, CustomTable $search_table, int $search_type): TestResponse
    {
        System::clearRequestSession();

        return $this->get(admin_url('search/relation') . '?' . http_build_query([
            'value_table_name' => $value_table->table_name,
            'value_id' => $value_id,
            'search_table_name' => $search_table->table_name,
            'search_type' => $search_type,
        ]), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * The record ids of the result table of a relation search (each row carries a hidden data-id).
     *
     * @param TestResponse<Response> $response
     * @return array<int, int>
     */
    protected function listedIds(TestResponse $response): array
    {
        preg_match_all('/data-id="(\d+)"/', strval($response->json('body')), $matches);

        return array_map('intval', $matches[1]);
    }

    /**
     * A screen's own ajax call: the logged-in session on admin/webapi.
     *
     * @return TestResponse<Response>
     */
    protected function webapi(string $path): TestResponse
    {
        System::clearRequestSession();

        return $this->get(admin_url('webapi/' . $path), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * The json list a call answered (or the list under $key).
     *
     * @param TestResponse<Response> $response
     * @return array<mixed>
     */
    protected function listOf(TestResponse $response, ?string $key = null): array
    {
        $list = $response->json($key);
        $this->assertIsArray($list);

        return $list;
    }

    protected function table(): CustomTable
    {
        $table = CustomTable::getEloquent(static::TABLE);
        if (!$table instanceof CustomTable) {
            $this->fail('the test data has no ' . static::TABLE . ' table');
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $value
     */
    protected function createValue(array $value): CustomValue
    {
        $custom_value = $this->table()->getValueModel();
        if (!$custom_value instanceof CustomValue) {
            $this->fail('no value model for ' . static::TABLE);
        }
        $custom_value->setValue($value);
        $custom_value->save();

        return $custom_value;
    }
}
