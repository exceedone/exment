<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * The chart box form refuses a multi-series chart of an AVG measure on a view grouped by 3+
 * columns: drawn, such a box shows the need_average_groups message only (ChartItem::
 * isAverageOfExtraGroups), so the form says so on save, at チャートの種類, and saves nothing.
 * A single-series type, another summary condition, or a view of 2 group columns saves as
 * before. The data is rolled back.
 */
class ChartAverageGroupsFormTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1');
        }
        $this->actingAs($login, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function testAMultiSeriesAverageOfThreeGroupColumnsIsRefused(): void
    {
        $dashboard = $this->dashboard();
        $view = $this->aggregateView(3, SummaryCondition::AVG);

        $response = $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'mbar'));

        $response->assertSessionHasErrors(['options.chart_type' => exmtrans('dashboard.message.need_average_groups_form')]);
        $this->assertSame(0, DashboardBox::where('dashboard_id', $dashboard->id)->count());
    }

    public function testAnEditToSuchAChartIsRefused(): void
    {
        $dashboard = $this->dashboard();
        $view = $this->aggregateView(3, SummaryCondition::AVG);
        $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'bar'))->assertSessionHasNoErrors();
        $box = DashboardBox::where('dashboard_id', $dashboard->id)->first();
        $this->assertNotNull($box);

        $response = $this->put($this->url('dashboardbox/' . $box->id), $this->boxForm($dashboard, $view, 'sbar'));

        $response->assertSessionHasErrors(['options.chart_type' => exmtrans('dashboard.message.need_average_groups_form')]);
        $box->refresh();
        $this->assertSame('bar', array_get($box->options, 'chart_type'));
    }

    /**
     * Each one draws a chart, so saves: a single-series type, another condition, a view of 2
     * group columns.
     */
    public function testChartsThatDrawAreSaved(): void
    {
        $dashboard = $this->dashboard();
        $cases = [
            'single series' => [$this->aggregateView(3, SummaryCondition::AVG), 'bar'],
            'sum'           => [$this->aggregateView(3, SummaryCondition::SUM), 'mbar'],
            '2 columns'     => [$this->aggregateView(2, SummaryCondition::AVG), 'heatmap'],
        ];
        foreach ($cases as $label => [$view, $type]) {
            DashboardBox::where('dashboard_id', $dashboard->id)->delete();
            $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, $type))->assertSessionHasNoErrors();
            $box = DashboardBox::where('dashboard_id', $dashboard->id)->first();
            $this->assertNotNull($box, $label);
            $this->assertSame($type, array_get($box->options, 'chart_type'), $label);
            $this->assertNotSame(exmtrans('dashboard.message.need_average_groups'), (new ChartItem($box))->body(), $label);
        }
    }

    /**
     * The form refuses what the chart would answer with the message, and only that.
     */
    public function testTheFormAndTheChartAgree(): void
    {
        foreach ([2, 3] as $groups) {
            foreach ([SummaryCondition::AVG, SummaryCondition::SUM] as $condition) {
                $view = $this->aggregateView($groups, $condition);
                foreach (['bar', 'mbar'] as $type) {
                    $box = new DashboardBox();
                    $box->options = $this->boxForm(null, $view, $type)['options'];
                    request()->merge(['options' => $box->options]);
                    $message = (new ChartItem($box))->body() === exmtrans('dashboard.message.need_average_groups');
                    $this->assertSame($message, ChartItem::formAverageOfExtraGroups($type), "$groups columns, condition $condition, $type");
                    $this->assertSame($groups === 3 && $condition === SummaryCondition::AVG && $type === 'mbar', $message);
                }
            }
        }
        request()->offsetUnset('options');
    }

    private function aggregateView(int $groups, string $condition): CustomView
    {
        System::clearCache();
        $columns = array_slice([['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3'], ['column_name' => 'text']], 0, $groups);
        return $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => $columns,
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => $condition]],
        ], ViewKindType::AGGREGATE)[1];
    }

    private function dashboard(): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'avg_form_' . uniqid();
        $dashboard->dashboard_view_name = 'avg form';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        return $dashboard;
    }

    /**
     * @return array<string, mixed>
     */
    private function boxForm(?Dashboard $dashboard, CustomView $view, string $type): array
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        return [
            'dashboard_id' => $dashboard ? $dashboard->id : null,
            'dashboard_box_type' => DashboardBoxType::CHART,
            'row_no' => 1,
            'column_no' => 1,
            'dashboard_box_view_name' => 'avg chart',
            'options' => [
                'chart_type' => $type,
                'target_table_id' => $view->custom_table_id,
                'target_view_id' => $view->id,
                'chart_axisx' => 'chartitem_label',
                'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
            ],
        ];
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
