<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardBoxFixtures;
use Exceedone\Exment\Tests\Unit\Security\SecurityRegressionTestCase;

/**
 * A chart of a list view reads at most EXMENT_CHART_LIST_MAX_ROWS rows (5000 by default since
 * 7.2.6, ISS00169; 0: every row), and a chart of more rows says that some are left out (ISS00413).
 *
 * Uses the test data (all_columns_table_fortest, 100 rows and more); the view is rolled back.
 */
class ChartListMaxRowsTest extends SecurityRegressionTestCase
{
    use DashboardBoxFixtures;

    public function test_by_default_at_most_5000_rows_are_read(): void
    {
        $this->assertSame(5000, intval(config('exment.chart_list_max_rows')), '5000 by default');
        $this->asAdmin();
        // the test data has fewer rows: all of them are drawn, nothing to say
        [$html] = $this->render($this->chart());
        $this->assertStringNotContainsString('chart-rows-left-out', $html);
    }

    public function test_no_limit_when_set_to_0(): void
    {
        $this->asAdmin();
        $before = config('exment.chart_list_max_rows');
        config(['exment.chart_list_max_rows' => 0]);
        try {
            [$html] = $this->render($this->chart());
            $this->assertStringNotContainsString('chart-rows-left-out', $html);
        } finally {
            config(['exment.chart_list_max_rows' => $before]);
        }
    }

    public function test_a_limit_set_by_the_site_leaves_rows_out_and_says_so(): void
    {
        $this->asAdmin();
        $before = config('exment.chart_list_max_rows');
        $options = $this->chartOptions();
        $rows = $this->table()->getValueModel()::query()->count();
        $this->assertGreaterThan(10, $rows, 'the test data has more rows than the limit');

        config(['exment.chart_list_max_rows' => 10]);
        try {
            [$html] = $this->render(new ChartItem($this->box(DashboardBoxType::CHART, $options)));
            $this->assertStringContainsString('chart-rows-left-out', $html);

            config(['exment.chart_list_max_rows' => $rows]);
            [$html] = $this->render(new ChartItem($this->box(DashboardBoxType::CHART, $options)));
            $this->assertStringNotContainsString('chart-rows-left-out', $html, 'no more rows than the limit');
            // the note names the limit
            config(['exment.chart_list_max_rows' => 10]);
            [$html] = $this->render(new ChartItem($this->box(DashboardBoxType::CHART, $options)));
            $this->assertStringContainsString(esc_html(exmtrans('dashboard.message.chart_list_rows_left_out', ['count' => 10])), $html);
        } finally {
            config(['exment.chart_list_max_rows' => $before]);
        }
    }

    protected function chart(): ChartItem
    {
        return new ChartItem($this->box(DashboardBoxType::CHART, $this->chartOptions()));
    }

    /**
     * A bar chart of a list view: text on the X axis, integer on the Y axis.
     *
     * @return array<string, mixed>
     */
    protected function chartOptions(): array
    {
        $table = $this->table();
        $viewId = $this->makeView(ViewKindType::DEFAULT, [
            [$table->id, $this->column('text')->id, []],
            [$table->id, $this->column('integer')->id, []],
        ]);
        $columns = \Illuminate\Support\Facades\DB::table('custom_view_columns')->where('custom_view_id', $viewId)->orderBy('order')->pluck('id')->all();
        return [
            'target_table_id' => $table->id, 'target_view_id' => $viewId, 'chart_type' => 'bar',
            'chart_axisx' => ViewKindType::DEFAULT . '_' . $columns[0], 'chart_axisy' => ViewKindType::DEFAULT . '_' . $columns[1],
        ];
    }
}
