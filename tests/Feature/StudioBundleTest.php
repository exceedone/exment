<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Studio: a new app made in one go - the table with a kanban, a calendar, a
 * chart and a dashboard (ScreenBundle).
 */
class StudioBundleTest extends FeatureTestBase
{
    use TestTrait;

    protected const TABLE = 'studio_test_bundle';
    protected const NAME = 'バンドルのテスト';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        foreach (Dashboard::where('dashboard_view_name', static::NAME)->get() as $dashboard) {
            $dashboard->delete();
        }
        $table = CustomTable::where('table_name', static::TABLE)->first();
        if (isset($table)) {
            $table->delete();
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $bundle
     * @param array<int, array<string, mixed>>|null $fields
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postApp(?array $bundle, ?array $fields = null)
    {
        System::clearRequestSession();
        $data = [
            'table_view_name' => static::NAME,
            'table_name' => static::TABLE,
            'fields' => json_encode($fields ?? [
                ['type' => ColumnType::TEXT, 'label' => 'title'],
                ['type' => ColumnType::SELECT, 'label' => 'status', 'choices' => "todo\ndoing\ndone"],
                ['type' => ColumnType::DATE, 'label' => 'due'],
            ]),
        ];
        if ($bundle !== null) {
            $data['bundle'] = json_encode($bundle);
        }
        return $this->post(admin_url('studio'), $data);
    }

    public function testTheBuilderOffersTheApp(): void
    {
        $response = $this->get(admin_url('studio/create'));
        $response->assertOk();
        $response->assertSee('id="exm-builder-bundle-box"', false);
        $response->assertSee('name="bundle"', false);
        $response->assertSee(exmtrans('studio.mode_new'));
    }

    public function testTheAppIsMadeWithItsViewsAndDashboard(): void
    {
        $this->postApp(['kanban' => 1, 'calendar' => 2, 'chart' => 1, 'dashboard' => true])
            ->assertRedirect(admin_urls('data', static::TABLE, 'create'));
        $this->assertSame([], session('studio_errors', []));

        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertNotNull($table);
        $status = CustomColumn::getEloquent('status', $table);
        $due = CustomColumn::getEloquent('due', $table);
        $this->assertNotNull($status);
        $this->assertNotNull($due);

        /** @var CustomView $kanban */
        $kanban = CustomView::where('custom_table_id', $table->id)->where('view_kind_type', ViewKindType::KANBAN)->firstOrFail();
        $this->assertSame(intval($status->id), intval($kanban->getOption('kanban_group_column_id')));
        $this->assertSame(3, $kanban->custom_view_columns()->count());

        /** @var CustomView $calendar */
        $calendar = CustomView::where('custom_table_id', $table->id)->where('view_kind_type', ViewKindType::CALENDAR)->firstOrFail();
        $this->assertSame([intval($due->id)], $calendar->custom_view_columns()->pluck('view_column_target_id')->map(function ($id) {
            return intval($id);
        })->all());

        /** @var CustomView $chart */
        $chart = CustomView::where('custom_table_id', $table->id)->where('view_kind_type', ViewKindType::AGGREGATE)->firstOrFail();
        $this->assertSame([intval($status->id)], $chart->custom_view_columns()->pluck('view_column_target_id')->map(function ($id) {
            return intval($id);
        })->all());
        $count = $chart->custom_view_summaries()->firstOrFail();
        $this->assertEquals(SummaryCondition::COUNT, $count->view_summary_condition);
        $this->assertEquals(ConditionType::SYSTEM, $count->view_column_type);

        /** @var Dashboard $dashboard */
        $dashboard = Dashboard::where('dashboard_view_name', static::NAME)->firstOrFail();
        $boxes = $dashboard->dashboard_boxes()->orderBy('row_no')->orderBy('column_no')->get();
        $this->assertSame([DashboardBoxType::LIST, DashboardBoxType::CHART, DashboardBoxType::CALENDAR], $boxes->pluck('dashboard_box_type')->all());
        $this->assertSame(intval($chart->id), intval(array_get($boxes[1]->options, 'target_view_id')));
        $this->assertSame(Define::CHARTITEM_LABEL, array_get($boxes[1]->options, 'chart_axisx'));
        $this->assertSame(ViewKindType::AGGREGATE . '_' . $count->id, array_get($boxes[1]->options, 'chart_axisy'));

        // some data, then each opens
        foreach ([['a', 'todo', '2026-11-21'], ['b', 'todo', '2026-11-22'], ['c', 'done', '2026-11-23']] as [$title, $state, $date]) {
            $data = $table->getValueModel();
            $data->setValue(['title' => $title, 'status' => $state, 'due' => $date]);
            $data->save();
        }
        foreach ([$kanban, $calendar, $chart] as $view) {
            System::clearRequestSession();
            $this->get(admin_urls('data', static::TABLE) . '?view=' . $view->suuid)->assertOk();
        }
        // the calendar finds the data by the date (its index is turned on)
        System::clearRequestSession();
        $this->assertTrue(boolval(CustomColumn::getEloquent('due', $table)->index_enabled));
        $events = json_decode((string) $this->get(admin_url('webapi/data/' . static::TABLE . '/calendar') . '?view=' . $calendar->suuid
            . '&start=2026-11-01&end=2026-12-01', ['X-Requested-With' => 'XMLHttpRequest'])->getContent(), true);
        $this->assertIsArray($events);
        $this->assertCount(3, $events);
        foreach ($boxes as $box) {
            System::clearRequestSession();
            $response = $this->get(admin_urls('dashboardbox', 'html', $box->suuid), ['X-Requested-With' => 'XMLHttpRequest']);
            $response->assertOk();
            $this->assertStringNotContainsString('Exception', (string) $response->getContent());
        }
        System::clearRequestSession();
        $chartHtml = (string) $this->get(admin_urls('dashboardbox', 'html', $boxes[1]->suuid), ['X-Requested-With' => 'XMLHttpRequest'])->getContent();
        $this->assertStringContainsString('todo', $chartHtml);
        System::clearRequestSession();
        $this->followingRedirects()->get(admin_url('?dashboard=' . $dashboard->suuid))->assertOk();
    }

    /**
     * The items the kanban and the chart are made from get the search index, so their
     * views can sort and filter by them (#2270); the title does not.
     */
    public function testTheKanbanAndChartItemsGetTheSearchIndex(): void
    {
        $this->postApp(['kanban' => 1, 'calendar' => null, 'chart' => 1, 'dashboard' => false])->assertRedirect();
        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertNotNull($table);
        $status = CustomColumn::getEloquent('status', $table);
        $this->assertNotNull($status);
        $this->assertTrue(boolval($status->index_enabled));
        $this->assertFalse(boolval(CustomColumn::getEloquent('title', $table)?->index_enabled));
        $this->assertTrue(hasColumn(getDBTableName($table), $status->getIndexColumnName(false)));
    }

    /**
     * ... but never past the number of indexes a table may have.
     */
    public function testTheSearchIndexKeepsTheLimit(): void
    {
        config(['exment.column_index_enabled_count' => 0]);
        $this->postApp(['kanban' => 1, 'calendar' => 2, 'chart' => 1, 'dashboard' => false])->assertRedirect();
        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertNotNull($table);
        $this->assertFalse(boolval(CustomColumn::getEloquent('status', $table)?->index_enabled));
        $this->assertFalse(boolval(CustomColumn::getEloquent('due', $table)?->index_enabled));
        // the views are made all the same
        $this->assertSame(3, CustomView::where('custom_table_id', $table->id)
            ->whereIn('view_kind_type', [ViewKindType::KANBAN, ViewKindType::CALENDAR, ViewKindType::AGGREGATE])->count());
    }

    /**
     * A "value,label" choice (#2272) is a column with its values and labels, and a kanban
     * and a chart can be made from it.
     */
    public function testAValueAndLabelChoiceMakesAKanban(): void
    {
        $this->postApp(['kanban' => 1, 'calendar' => null, 'chart' => 1, 'dashboard' => false], [
            ['type' => ColumnType::TEXT, 'label' => 'title'],
            ['type' => ColumnType::SELECT_VALTEXT, 'label' => 'office', 'choices' => "01,Head office\n02,Osaka"],
        ])->assertRedirect(admin_urls('data', static::TABLE, 'create'));
        $this->assertSame([], session('studio_errors', []));
        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertNotNull($table);
        $office = CustomColumn::getEloquent('office', $table);
        $this->assertNotNull($office);
        $this->assertSame(ColumnType::SELECT_VALTEXT, $office->column_type);
        $options = $office->createSelectOptions();
        $this->assertSame(['01' => 'Head office', '02' => 'Osaka'], $options instanceof \Illuminate\Support\Collection ? $options->all() : (array) $options);
        /** @var CustomView $kanban */
        $kanban = CustomView::where('custom_table_id', $table->id)->where('view_kind_type', ViewKindType::KANBAN)->firstOrFail();
        $this->assertSame(intval($office->id), intval($kanban->getOption('kanban_group_column_id')));

        $data = $table->getValueModel();
        $data->setValue(['title' => 'a', 'office' => '02']);
        $data->save();
        System::clearRequestSession();
        $this->get(admin_urls('data', static::TABLE) . '?view=' . $kanban->suuid)->assertOk()->assertSee('Osaka');
    }

    public function testOnlyWhatIsAskedForIsMade(): void
    {
        $views = CustomView::count();
        $dashboards = Dashboard::count();
        $this->postApp(null)->assertRedirect();
        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertNotNull($table);
        // the default view and the view of all data only, as before
        $this->assertSame(0, CustomView::where('custom_table_id', $table->id)
            ->whereIn('view_kind_type', [ViewKindType::KANBAN, ViewKindType::CALENDAR, ViewKindType::AGGREGATE])->count());
        $this->assertSame($dashboards, Dashboard::count());

        $table->delete();
        $this->postApp(['kanban' => null, 'calendar' => 2, 'chart' => null, 'dashboard' => false])->assertRedirect();
        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertSame([ViewKindType::CALENDAR], CustomView::where('custom_table_id', $table->id)
            ->whereIn('view_kind_type', [ViewKindType::KANBAN, ViewKindType::CALENDAR, ViewKindType::AGGREGATE])->pluck('view_kind_type')->all());
        $this->assertSame($dashboards, Dashboard::count());
        $this->assertGreaterThan($views, CustomView::count());
    }

    public function testMistakesMakeNothing(): void
    {
        $tables = CustomTable::count();
        $this->postApp(['kanban' => 0, 'calendar' => 1, 'chart' => 2, 'dashboard' => false])->assertRedirect();
        $errors = session('studio_errors');
        $this->assertSame(exmtrans('studio.error.bundle_kanban'), $errors['bundle.kanban'] ?? null);
        $this->assertSame(exmtrans('studio.error.bundle_calendar'), $errors['bundle.calendar'] ?? null);
        $this->assertSame(exmtrans('studio.error.bundle_chart'), $errors['bundle.chart'] ?? null);
        $this->assertSame($tables, CustomTable::count());
    }
}
