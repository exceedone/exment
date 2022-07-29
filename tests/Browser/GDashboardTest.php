<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Enums;
use Exceedone\Exment\Model;
use Exceedone\Exment\Tests\TestDefine;

class GDashboardTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     */
    public function testDisplayCreateDashboard()
    {
        $this->visit(admin_url('dashboard/create'))
            ->seePageIs(admin_url('dashboard/create'))
            ->see('ダッシュボード')
            ->seeInElement('label', 'ダッシュボード名(英数字)')
            ->seeInElement('label', 'ダッシュボード表示名')
            ->seeInElement('label', '既定')
            ->seeInElement('label', 'ダッシュボード1行目')
            ->seeInElement('label', 'ダッシュボード2行目')
            ->seeInElement('label', 'ダッシュボード3行目')
            ->seeInElement('label', 'ダッシュボード4行目')
            ->seeInElement('button', '保存');
    }


    public function testCreateDashboard()
    {
        $this->visit(admin_url('dashboard/create'))
            ->seePageIs(admin_url('dashboard/create'))
            ->type('DashboardTest', 'dashboard_name')
            ->type('DashboardTest', 'dashboard_view_name')
            ->type('3', 'options[row1]')
            ->type('3', 'options[row2]')
            ->type('3', 'options[row3]')
            ->type('3', 'options[row4]')
            ->press('admin-submit')
            ->seePageIs(admin_url(''))
        ;

        // Check database
        $model = $this->getDashboardTestModel();
        $this->assertTrue(isMatchString($model->dashboard_name, 'DashboardTest'));
        $this->assertTrue(isMatchString($model->dashboard_view_name, 'DashboardTest'));
        $this->assertTrue(isMatchString($model->options['row1'], '3'));
        $this->assertTrue(isMatchString($model->options['row2'], '3'));
        $this->assertTrue(isMatchString($model->options['row3'], '3'));
        $this->assertTrue(isMatchString($model->options['row4'], '3'));
    }


    public function testEditDashboard()
    {
        $model = $this->getDashboardTestModel();
        $this->assertTrue(!is_nullorempty($model), 'dashboard not found');

        $this->visit(admin_urls('dashboard', $model->id, 'edit'))
            ->seePageIs(admin_urls('dashboard', $model->id, 'edit'))
            ->type('DashboardTestUpdate', 'dashboard_view_name')
            ->press('admin-submit')
            ->seePageIs(admin_url(''))
        ;

        // Check database
        $model = $this->getDashboardTestModel();
        $this->assertTrue(isMatchString($model->dashboard_view_name, 'DashboardTestUpdate'));
    }


    public function testDisplayCreateBoxList()
    {
        $model = $this->getDashboardTestModel();

        $url = admin_urls_query('dashboardbox', 'create', [
            'column_no' => '1',
            'dashboard_box_type' => 'list',
            'dashboard_suuid' => $model->suuid,
            'row_no' => '1',
        ]);
        $this->visit($url)
            ->seePageIs($url)
            ->seeInElement('label', 'ダッシュボード表示名')
            ->seeInElement('label', '行番号')
            ->seeInElement('label', '列番号')
            ->seeInElement('label', 'アイテム種類')
            ->seeInElement('label', 'アイテム表示名')
            ->seeInElement('label', '表示')
            ->seeInElement('label', '対象のビュー')
        ;

        //ToDo: How to test options
    }


    public function testCreateBoxList()
    {
        $model = $this->getDashboardTestModel();
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_view = $custom_table->custom_views->first();

        // cannot press 'target_view_id', so execute as post.

        $this->post(admin_urls_query('dashboardbox', [
            'column_no' => '1',
            'dashboard_box_type' => 'list',
            'dashboard_suuid' => $model->suuid,
            'row_no' => '1',
        ]), [
            'dashboard_id' => $model->id,
            'row_no' => 1,
            'column_no' => 1,
            'dashboard_box_type' => 'list',
            'dashboard_box_view_name' => 'DashboardBoxListTest',
            'options' => [
                'pager_count' => '5',
                'target_table_id' => $custom_table->id,
                'target_view_id' => $custom_view->id,
            ],
        ]);

        // Check database
        $box = $this->getDashboardBoxModel($model, 1, 1);
        $this->assertTrue(isMatchString($box->dashboard_box_view_name, 'DashboardBoxListTest'));
        $this->assertTrue(isMatchString($box->dashboard_box_type, 'list'));
        $this->assertTrue(isMatchString($box->options['pager_count'], '5'));
        $this->assertTrue(isMatchString($box->options['target_table_id'], $custom_table->id));
        $this->assertTrue(isMatchString($box->options['target_view_id'], $custom_view->id));
    }



    public function testDisplayCreateBoxSystem()
    {
        $model = $this->getDashboardTestModel();

        $url = admin_urls_query('dashboardbox', 'create', [
            'column_no' => '2',
            'dashboard_box_type' => 'system',
            'dashboard_suuid' => $model->suuid,
            'row_no' => '1',
        ]);
        $this->visit($url)
            ->seePageIs($url)
            ->seeInElement('label', 'ダッシュボード表示名')
            ->seeInElement('label', '行番号')
            ->seeInElement('label', '列番号')
            ->seeInElement('label', 'アイテム種類')
            ->seeInElement('label', 'アイテム表示名')
            ->seeInElement('label', '表示')
        ;

        //ToDo: How to test options
    }


    public function testCreateBoxSystemGuideline()
    {
        $this->_testCreateDashboardSystem('SystemGuidelineTest', Enums\DashboardBoxSystemPage::GUIDELINE, 1, 2);

        //ToDo: How to test real dashboard item
    }


    public function testCreateBoxSystemNews()
    {
        $this->_testCreateDashboardSystem('SystemNewsTest', Enums\DashboardBoxSystemPage::NEWS, 1, 3);


        //ToDo: How to test real dashboard item
    }


    public function testCreateBoxSystemEditor()
    {
        $this->_testCreateDashboardSystem('SystemEditorTest', Enums\DashboardBoxSystemPage::EDITOR, 2, 1, [
            'content' => 'TestTest',
        ]);

        //ToDo: How to test real dashboard item
    }

    public function testCreateBoxSystemHtml()
    {
        $this->_testCreateDashboardSystem('SystemHtmlTest', Enums\DashboardBoxSystemPage::HTML, 2, 2, [
            'html' => '<b>TestTest</b>',
        ]);

        //ToDo: How to test real dashboard item
    }




    public function testDisplayCreateBoxChart()
    {
        $model = $this->getDashboardTestModel();

        $url = admin_urls_query('dashboardbox', 'create', [
            'column_no' => '3',
            'dashboard_box_type' => Enums\DashboardBoxType::CHART,
            'dashboard_suuid' => $model->suuid,
            'row_no' => '2',
        ]);
        $this->visit($url)
            ->seePageIs($url)
            ->seeInElement('label', 'ダッシュボード表示名')
            ->seeInElement('label', '行番号')
            ->seeInElement('label', '列番号')
            ->seeInElement('label', 'アイテム種類')
            ->seeInElement('label', 'アイテム表示名')
            ->seeInElement('label', 'チャートの種類')
            ->seeInElement('label', '対象のテーブル')
            ->seeInElement('label', '対象のビュー')
            ->seeInElement('label', 'X軸の項目')
            ->seeInElement('label', 'Y軸の項目')
            ->seeInElement('label', 'ラベルを表示する')
            ->seeInElement('label', '項目名を表示する')
            ->seeInElement('label', 'オプション設定')
        ;

        //ToDo: How to test options
    }


    public function testCreateBoxChartBar()
    {
        $this->_testCreateDashboardChart('ChartBarTest', Enums\ChartType::BAR, 2, 3, [
            'chart_axisx' => Model\Define::CHARTITEM_LABEL,
        ]);

        //ToDo: How to test real dashboard item
    }


    public function testCreateBoxChartLine()
    {
        $this->_testCreateDashboardChart('ChartLineTest', Enums\ChartType::LINE, 3, 1, [
            'chart_axisx' => Model\Define::CHARTITEM_LABEL,
        ]);

        //ToDo: How to test real dashboard item
    }


    public function testCreateBoxChartPie()
    {
        $this->_testCreateDashboardChart('ChartPieTest', Enums\ChartType::PIE, 3, 2, [
            'chart_axisx' => Model\Define::CHARTITEM_LABEL,
        ]);

        //ToDo: How to test real dashboard item
    }



    public function testDisplayCreateBoxCalendar()
    {
        $model = $this->getDashboardTestModel();

        $url = admin_urls_query('dashboardbox', 'create', [
            'column_no' => '3',
            'dashboard_box_type' => Enums\DashboardBoxType::CALENDAR,
            'dashboard_suuid' => $model->suuid,
            'row_no' => '3',
        ]);
        $this->visit($url)
            ->seePageIs($url)
            ->seeInElement('label', 'ダッシュボード表示名')
            ->seeInElement('label', '行番号')
            ->seeInElement('label', '列番号')
            ->seeInElement('label', 'アイテム種類')
            ->seeInElement('label', 'アイテム表示名')
            ->seeInElement('label', 'カレンダーの種類')
            ->seeInElement('label', '対象のテーブル')
            ->seeInElement('label', '対象のビュー')
        ;

        //ToDo: How to test options
    }


    public function testCreateBoxCalendarMonth()
    {
        $this->_testCreateDashboardCalendar('CalendarMonthTest', Enums\CalendarType::MONTH, 3, 3);

        //ToDo: How to test real dashboard item
    }


    public function testCreateBoxCalendarList()
    {
        $this->_testCreateDashboardCalendar('CalendarListTest', Enums\CalendarType::LIST, 4, 1);

        //ToDo: How to test real dashboard item
    }






    protected function _testCreateDashboardSystem($dashboard_box_view_name, $system_type, $row_no, $column_no, array $options = [])
    {
        $model = $this->getDashboardTestModel();

        $options = array_merge(
            [
                    'target_system_id' => $system_type,
            ],
            $options
        );

        $this->post(admin_urls_query('dashboardbox', [
            'column_no' => $column_no,
            'dashboard_box_type' => Enums\DashboardBoxType::SYSTEM,
            'dashboard_suuid' => $model->suuid,
            'row_no' => $row_no,
        ]), [
            'dashboard_id' => $model->id,
            'row_no' => $row_no,
            'column_no' => $column_no,
            'dashboard_box_type' => Enums\DashboardBoxType::SYSTEM,
            'dashboard_box_view_name' => $dashboard_box_view_name,
            'options' => $options,
        ]);

        // Check database
        $box = $this->getDashboardBoxModel($model, $row_no, $column_no);
        $this->assertTrue(isMatchString($box->dashboard_box_view_name, $dashboard_box_view_name));
        $this->assertTrue(isMatchString($box->dashboard_box_type, Enums\DashboardBoxType::SYSTEM));

        foreach ($options as $key => $value) {
            $this->assertTrue(isMatchString(array_get($box->options, $key), $value));
        }
    }


    /**
     * TODO: Now is only default view. Append summary view
     *
     * @param string $dashboard_box_view_name
     * @param string $chart_type
     * @param int $row_no
     * @param int $column_no
     * @param array $options
     * @return void
     */
    protected function _testCreateDashboardChart($dashboard_box_view_name, $chart_type, $row_no, $column_no, array $options = [])
    {
        $model = $this->getDashboardTestModel();
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_view = $custom_table->custom_views->first();

        // get select option item
        $custom_view_column = $custom_view->custom_view_columns->first(function ($custom_view_column) {
            return $custom_view_column->view_column_type == Enums\ConditionType::COLUMN && $custom_view_column->custom_column->column_type == 'integer';
        });

        $options = array_merge(
            [
                'chart_type' => $chart_type,
                'target_table_id' => $custom_table->id,
                'target_view_id' => $custom_view->id,
                'chart_axisy' => Enums\ConditionType::COLUMN . '_' . $custom_view_column->id,
            ],
            $options
        );

        $this->post(admin_urls_query('dashboardbox', [
            'column_no' => $column_no,
            'dashboard_box_type' => Enums\DashboardBoxType::CHART,
            'dashboard_suuid' => $model->suuid,
            'row_no' => $row_no,
        ]), [
            'dashboard_id' => $model->id,
            'row_no' => $row_no,
            'column_no' => $column_no,
            'dashboard_box_type' => Enums\DashboardBoxType::CHART,
            'dashboard_box_view_name' => $dashboard_box_view_name,
            'options' => $options,
        ]);

        // Check database
        $box = $this->getDashboardBoxModel($model, $row_no, $column_no);
        $this->assertTrue(isMatchString($box->dashboard_box_view_name, $dashboard_box_view_name));
        $this->assertTrue(isMatchString($box->dashboard_box_type, Enums\DashboardBoxType::CHART));

        foreach ($options as $key => $value) {
            $this->assertTrue(isMatchString(array_get($box->options, $key), $value));
        }
    }


    protected function _testCreateDashboardCalendar($dashboard_box_view_name, $calendar_type, $row_no, $column_no, array $options = [])
    {
        $model = $this->getDashboardTestModel();
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_view = $custom_table->custom_views->first(function ($custom_view) {
            return $custom_view->view_kind_type == Enums\ViewKindType::CALENDAR;
        });

        $options = array_merge(
            [
                'calendar_type' => $calendar_type,
                'target_table_id' => $custom_table->id,
                'target_view_id' => $custom_view->id,
            ],
            $options
        );

        $this->post(admin_urls_query('dashboardbox', [
            'column_no' => $column_no,
            'dashboard_box_type' => Enums\DashboardBoxType::CALENDAR,
            'dashboard_suuid' => $model->suuid,
            'row_no' => $row_no,
        ]), [
            'dashboard_id' => $model->id,
            'row_no' => $row_no,
            'column_no' => $column_no,
            'dashboard_box_type' => Enums\DashboardBoxType::CALENDAR,
            'dashboard_box_view_name' => $dashboard_box_view_name,
            'options' => $options,
        ]);

        // Check database
        $box = $this->getDashboardBoxModel($model, $row_no, $column_no);
        $this->assertTrue(isMatchString($box->dashboard_box_view_name, $dashboard_box_view_name));
        $this->assertTrue(isMatchString($box->dashboard_box_type, Enums\DashboardBoxType::CALENDAR));

        foreach ($options as $key => $value) {
            $this->assertTrue(isMatchString(array_get($box->options, $key), $value));
        }
    }


    protected function getDashboardTestModel(): Model\Dashboard
    {
        $model = Model\Dashboard::where('dashboard_name', 'DashboardTest')->first();
        $this->assertTrue(isset($model), 'dashboard not found');
        return $model;
    }


    protected function getDashboardBoxModel(Model\Dashboard $dashboard, $row_no, $column_no): Model\DashboardBox
    {
        $model = Model\DashboardBox::where('dashboard_id', $dashboard->id)
            ->where('row_no', $row_no)
            ->where('column_no', $column_no)->first();
        $this->assertTrue(isset($model), 'dashboard box not found');
        return $model;
    }
}
