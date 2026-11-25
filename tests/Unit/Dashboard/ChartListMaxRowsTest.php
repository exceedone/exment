<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardBoxFixtures;
use Exceedone\Exment\Tests\Unit\Security\SecurityRegressionTestCase;

/**
 * A chart of a list view reads every row, as before 7.2.0: an update does not change what a
 * chart draws. A site may limit the rows with EXMENT_CHART_LIST_MAX_ROWS (ISS00413); then a
 * chart of more rows says that some are left out.
 *
 * Uses the test data (all_columns_table_fortest, 100 rows and more); the view is rolled back.
 */
class ChartListMaxRowsTest extends SecurityRegressionTestCase
{
    use DashboardBoxFixtures;

    public function test_by_default_every_row_is_read(): void
    {
        $this->assertSame(0, intval(config('exment.chart_list_max_rows')), 'no limit by default');
        $this->asAdmin();
        [$html] = $this->render($this->chart());
        $this->assertStringNotContainsString('chart-rows-left-out', $html);
    }

    public function test_a_limit_set_by_the_site_leaves_rows_out_and_says_so(): void
    {
        $this->asAdmin();
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
        } finally {
            config(['exment.chart_list_max_rows' => 0]);
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
