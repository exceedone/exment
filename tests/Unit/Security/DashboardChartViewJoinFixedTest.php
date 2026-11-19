<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * FIXED: a chart box with chart filter fields, on a view filtered by a column of a related
 * table (here the user name of a user column), failed with "Unknown column": the view keeps
 * the JOIN its filter needs in the search service it caches and adds it to the first query
 * only — the chart filter's option lists ran first, the chart's data query then got the
 * WHERE without the JOIN. Each query of the box now starts from a fresh search service.
 * Also: a list-view chart drew null points on such a view (the joined user table's `value`
 * column stood in for the row's); its query now selects the row's own columns.
 *
 * Rows of exmenttest_data, the view, the dashboard and the box are rolled back.
 */
class DashboardChartViewJoinFixedTest extends SecurityRegressionTestCase
{
    public function test_a_chart_on_a_view_filtered_by_a_related_table_renders(): void
    {
        $login = LoginUser::find(1);
        $table = CustomTable::getEloquent('exmenttest_data');
        if ($login === null || $table === null) {
            $this->markTestSkipped('needs LoginUser 1 and the exmenttest_data table (exment:inittest)');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin');
        $column = function (string $name) use ($table) {
            return $table->custom_columns->firstWhere('column_name', $name);
        };
        foreach ([['A', 1], ['B', 0]] as [$text, $userId]) {
            $table->getValueModel()->setValue([
                'onelinetext' => $text, 'integer' => 3, 'date' => '2025-05-05',
                'user_single' => $userId ?: null,
            ])->saved_notify(false)->save();
        }

        $view = new CustomView();
        $view->custom_table_id = $table->id;
        $view->view_view_name = 'join filter';
        $view->view_type = 0;
        $view->view_kind_type = 0;
        $view->save();
        $x = $this->viewColumn($view, $column('onelinetext')->id, 1);
        $y = $this->viewColumn($view, $column('integer')->id, 2);
        $user = CustomTable::getEloquent('user');
        $filter = new CustomViewFilter();
        $filter->custom_view_id = $view->id;
        $filter->view_column_type = '0'; // a column of a table
        $filter->view_column_table_id = $user->id;
        $filter->view_column_target_id = $user->custom_columns->firstWhere('column_name', 'user_name')->id;
        $filter->view_pivot_column_id = $column('user_single')->id;
        $filter->view_pivot_table_id = $table->id;
        $filter->view_filter_condition = 1; // equal
        $filter->view_filter_condition_value_text = $login->base_user->getValue('user_name');
        $filter->save();

        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'join_' . uniqid();
        $dashboard->dashboard_view_name = 'join';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'join';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = [
            'target_table_id' => $table->id, 'target_view_id' => $view->id,
            'chart_axisx' => '0_' . $x->id, 'chart_axisy' => '0_' . $y->id, 'chart_type' => 'bar',
            // two option lists (a date range, a number list) run before the chart's data
            'chart_filters' => ['date', 'integer'],
        ];
        $box->save();

        $body = (string) DashboardBox::where('id', $box->id)->firstOrFail()->dashboard_box_item->body();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringContainsString('exment-bf-range', $body, 'the chart filter is drawn');
        $this->assertMatchesRegularExpression('/var labels = \["A"\]/', $body, 'only the row of the filtered user');
    }

    private function viewColumn(CustomView $view, int $columnId, int $order): CustomViewColumn
    {
        $viewColumn = new CustomViewColumn();
        $viewColumn->custom_view_id = $view->id;
        $viewColumn->view_column_type = '0'; // a column of a table
        $viewColumn->view_column_target_id = $columnId;
        $viewColumn->view_column_table_id = $view->custom_table_id;
        $viewColumn->order = $order;
        $viewColumn->save();
        return $viewColumn;
    }
}
