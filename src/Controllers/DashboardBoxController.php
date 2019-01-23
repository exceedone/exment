<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Table as WidgetTable;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\RoleValue;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\SystemColumn;

class DashboardBoxController extends AdminControllerBase
{
    use HasResourceActions;
    protected $dashboard;
    protected $dashboard_box_type;
    protected $row_no;
    protected $column_no;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("dashboard.header"), exmtrans("dashboard.header"));
    }

    public function index(Request $request, Content $content)
    {
        return redirect(admin_base_path(''));
    }
    
    /**
     * Delete interface.
     *
     * @return Content
     */
    public function delete(Request $request, $suuid)
    {
        // get suuid
        $box = DashBoardBox::findBySuuid($suuid);
        if (isset($box)) {
            $box->delete();
            return response()->json([
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ]);
        } else {
            return response()->json([
                'status'  => false,
                'message' => trans('admin.delete_failed'),
            ]);
        }
    }
    
    /**
     * get box html from ajax
     */
    public function getHtml($suuid)
    {
        // get dashboardbox object
        $box = DashBoardBox::findBySuuid($suuid);
        
        // get box html --------------------------------------------------
        $html = null;
        if (isset($box)) {
            // swicth dashboard_box_type
            switch ($box->dashboard_box_type) {
                // system
                case DashboardBoxType::SYSTEM:
                    // get id match item
                    $item = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function ($value) use ($box) {
                        return array_get($value, 'id') == array_get($box, 'options.target_system_id');
                    });
                    if (isset($item)) {
                        $html = view('exment::dashboard.system.'.array_get($item, 'name'))->render() ?? null;
                    }
                    break;
                // list
                case DashboardBoxType::LIST:
                    // get target table and view
                    $table_id = array_get($box, 'options.target_table_id');
                    $view_id = array_get($box, 'options.target_view_id');

                    // get table and view
                    $table = CustomTable::find($table_id);
                    $view = CustomView::find($view_id);
                    
                    // if table not found, break
                    if (!isset($table)) {
                        break;
                    }

                    // if view not found, set view first data
                    if (!isset($view)) {
                        $view = $table->custom_views()->first();
                    }
                    if (!isset($view)) {
                        break;
                    }

                    // if not access permission
                    if(!$table->hasPermission()){
                        $html = view('exment::dashboard.list.header')->render();
                        $html .= trans('admin.deny');
                    }else{

                        // create model for getting data --------------------------------------------------
                        $classname = getModelName($table);
                        $model = new $classname();
                        // filter model
                        $model = Admin::user()->filterModel($model, $table->table_name, $view);
                        // get data
                        // TODO:only take 5 rows. add function that changing take and skip records.
                        $datalist = $model->take(5)->get();

                        // get widget table
                        list($headers, $bodies) = $view->getDataTable($datalist);
                        $widgetTable = new WidgetTable($headers, $bodies);
                        $widgetTable->class('table table-hover');

                        // check edit permission
                        if($table->hasPermission(RoleValue::AVAILABLE_EDIT_CUSTOM_VALUE)){
                            $new_url= admin_base_path("data/{$table->table_name}/create");
                            $list_url = admin_base_path("data/{$table->table_name}");
                        }else{
                            $new_url = null;
                            $list_url = null;
                        }

                        $html = view('exment::dashboard.list.header', [
                            'new_url' => $new_url,
                            'list_url' => $list_url,
                        ])->render();
                        $html .= $widgetTable->render();

                    }
                    break;
                // chart
                case DashboardBoxType::CHART:
                    // get target table and view and axis
                    $table_id = array_get($box, 'options.target_table_id');
                    $view_id = array_get($box, 'options.target_view_id');
                    $axis_x = array_get($box, 'options.chart_axisx');
                    $axis_y = array_get($box, 'options.chart_axisy');
                    $chart_type = array_get($box, 'options.chart_type');
                    $chart_options = array_get($box, 'options.chart_options')?? [];
                    $chart_axis_label = array_get($box, 'options.chart_axis_label')?? [];
                    $chart_axis_name = array_get($box, 'options.chart_axis_name')?? [];

                    $table = CustomTable::find($table_id);
                    $view = CustomView::find($view_id);
                    $item = $this->getViewColumn($axis_x)->column_item;
                    $item_y = $this->getViewColumn($axis_y)->column_item;
            
                    // create model for getting data --------------------------------------------------
                    $classname = getModelName($table);
                    $model = new $classname();

                    if (array_get($view, 'view_kind_type') == ViewKindType::AGGREGATE) {
                        $item->options([
                            'summary' => true,
                            'summary_index' => $axis_x
                        ]);
                        // get data
                        $datalist = $view->getValueSummary($model, $table->table_name);
                        $chart_label = $datalist->map(function($val) use($item) {
                            $data = $item->setCustomValue($val)->text();
                            return $data;
                        });
                        $chart_data = $datalist->pluck("column_$axis_y");
                    } else {
                        // filter model
                        $model = Admin::user()->filterModel($model, $table->table_name, $view);
                        // get data
                        $datalist = $model->all();
                        $chart_label = $datalist->map(function($val) use($item) {
                            $data = $item->setCustomValue($val)->text();
                            return $data;
                        });
                        $axis_y_name = $this->getViewColumn($axis_y)->custom_column->column_name;
                        $chart_data = $datalist->pluck('value.'.$axis_y_name);
                    }

                    $html = view('exment::dashboard.chart.chart', [
                        'chart_data' => json_encode($chart_data, JSON_UNESCAPED_SLASHES),
                        'chart_labels' => json_encode($chart_label, JSON_UNESCAPED_SLASHES),
                        'chart_type' => $chart_type,
                        'chart_axisx_label' => in_array(ChartAxisType::X, $chart_axis_label),
                        'chart_axisy_label' => in_array(ChartAxisType::Y, $chart_axis_label),
                        'chart_axisx_name' => in_array(ChartAxisType::X, $chart_axis_name),
                        'chart_axisy_name' => in_array(ChartAxisType::Y, $chart_axis_name),
                        'chart_axisx' => $item->label(),
                        'chart_axisy' => $item_y->label(),
                        'chart_legend' => in_array(ChartOptionType::LEGEND, $chart_options),
                        'chart_begin_zero' => in_array(ChartOptionType::BEGIN_ZERO, $chart_options),
                        'chart_color' => json_encode($this->getChartColor($chart_type, count($chart_data)))
                    ])->render();
                    break;
            }
        }
        // get dashboard box
        return [
            'html' => $html,
            'suuid' => $suuid,
        ];
    }
    
    /**
     * get chart color array.
     *
     * @return chart color array
     */
    protected function getChartColor($chart_type, $datacnt) {
        $chart_color = config('exment.chart_backgroundColor', ['red']);
        if ($chart_type == ChartType::PIE) {
            while (count($chart_color) < $datacnt) {
                $chart_color .= $chart_color;
            };
        } else {
            $chart_color = (count($chart_color) > 0)? $chart_color[0]: '';
        }
        return $chart_color;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new DashboardBox);
        // set info with query --------------------------------------------------
        // get request
        $request = request();
        // get dashboard, row_no, column_no, ... from query "dashboard_suuid"
        list($dashboard, $dashboard_box_type, $row_no, $column_no) = $this->getDashboardInfo($id);
        if (!isset($dashboard)) {
            return redirect(admin_base_path(''));
        }

        $form->display('dashboard_view_name', exmtrans('dashboard.dashboard_view_name'))->default($dashboard->dashboard_view_name);
        $form->hidden('dashboard_id')->default($dashboard->id);

        $form->display('row_no', exmtrans('dashboard.row_no'))->default($row_no);
        $form->hidden('row_no')->default($row_no);

        $form->display('column_no', exmtrans('dashboard.column_no'))->default($column_no);
        $form->hidden('column_no')->default($column_no);

        $form->display('dashboard_box_type_display', exmtrans('dashboard.dashboard_box_type'))->default(exmtrans("dashboard.dashboard_box_type_options.$dashboard_box_type"));
        $form->hidden('dashboard_box_type')->default($dashboard_box_type);

        $form->text('dashboard_box_view_name', exmtrans("dashboard.dashboard_box_view_name"))->required();
        
        // Option Setting --------------------------------------------------
        $form->embeds('options', function ($form) use ($dashboard_box_type) {
            //$dashboard_box_type is list
            switch ($dashboard_box_type) {
                case DashboardBoxType::LIST:
                    $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
                        ->required()
                        ->options(CustomTable::filterList()->pluck('table_view_name', 'id'))
                        ->load('options_target_view_id', admin_base_path('dashboardbox/table_views'));

                    $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
                        ->required()
                        ->options(function ($value) {
                            if (!isset($value)) {
                                return [];
                            }

                            return CustomView::find($value)->custom_table->custom_views()->pluck('view_view_name', 'id');
                        })
                        ->loads(['options_chart_axisx', 'options_chart_axisy'], 
                            [admin_base_path('dashboardbox/chart_axis').'/x', admin_base_path('dashboardbox/chart_axis').'/y']);
                    break;
                case DashboardBoxType::CHART:
                    $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
                        ->required()
                        ->options(CustomTable::filterList()->pluck('table_view_name', 'id'))
                        ->load('options_target_view_id', admin_base_path('dashboardbox/table_views'));

                    $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
                        ->required()
                        ->options(function ($value) {
                            if (!isset($value)) {
                                return [];
                            }

                            return CustomView::find($value)->custom_table->custom_views()->pluck('view_view_name', 'id');
                        })
                        ->loads(['options_chart_axisx', 'options_chart_axisy'], 
                            [admin_base_path('dashboardbox/chart_axis').'/x', admin_base_path('dashboardbox/chart_axis').'/y']);

                    $form->select('chart_type', exmtrans("dashboard.dashboard_box_options.chart_type"))
                            ->required()
                            ->options(ChartType::transArray("chart.chart_type_options"));
    
                    $form->select('chart_axisx', exmtrans("dashboard.dashboard_box_options.chart_axisx"))
                        ->required()
                        ->options(function ($value) {
                            if (!isset($value)) {
                                return [];
                            }
                            $keys = explode("_", $value);
                            if ($keys[0] == ViewKindType::DEFAULT) {
                                $view_column = CustomViewColumn::find($keys[1]);
                            } else {
                                $view_column = CustomViewSummary::find($keys[1]);
                            }
                            $options = $view_column->custom_view->getColumnsSelectOptions();
                            return array_column($options, 'text', 'id');
                        });
                    $form->select('chart_axisy', exmtrans("dashboard.dashboard_box_options.chart_axisy"))
                        ->required()
                        ->options(function ($value) {
                            if (!isset($value)) {
                                return [];
                            }
                            $keys = explode("_", $value);
                            if ($keys[0] == ViewKindType::DEFAULT) {
                                $view_column = CustomViewColumn::find($keys[1]);
                            } else {
                                $view_column = CustomViewSummary::find($keys[1]);
                            }
                            $options = $view_column->custom_view->getColumnsSelectOptions(true);
                            return array_column($options, 'text', 'id');
                        });
                    $form->checkbox('chart_axis_label', exmtrans("dashboard.dashboard_box_options.chart_axis_label"))
                        ->options([
                            1 => exmtrans("dashboard.dashboard_box_options.chart_axisx_short"),
                            2 => exmtrans("dashboard.dashboard_box_options.chart_axisy_short")])
                        ;
                    $form->checkbox('chart_axis_name', exmtrans("dashboard.dashboard_box_options.chart_axis_name"))
                    ->options([
                            1 => exmtrans("dashboard.dashboard_box_options.chart_axisx_short"),
                            2 => exmtrans("dashboard.dashboard_box_options.chart_axisy_short")])
                        ;
                    $form->checkbox('chart_options', exmtrans("dashboard.dashboard_box_options.chart_options"))
                    ->options([
                            1 => exmtrans("dashboard.dashboard_box_options.chart_legend"),
                            2 => exmtrans("dashboard.dashboard_box_options.chart_begin_zero")])
                        ;
                    $script = <<<EOT
                    function setChartOptions(val) {
                        if (val == 'pie') {
                            $('#chart_options > label:nth-child(1)').show();
                            $('#chart_options > label:nth-child(2)').hide();
                            $('#chart_axis_label').parent().hide();
                            $('#chart_axis_name').parent().hide();
                        } else {
                            $('#chart_options > label:nth-child(1)').hide();
                            $('#chart_options > label:nth-child(2)').show();
                            $('#chart_axis_label').parent().show();
                            $('#chart_axis_name').parent().show();
                        }
                    }
                    setChartOptions($('.options_chart_type').val());

                    $(document).off('change', ".options_chart_type");
                    $(document).on('change', ".options_chart_type", function () {
                        setChartOptions($(this).val());
                    });
EOT;
                    Admin::script($script);
                    break;
                
                // $dashboard_box_type is system
                case DashboardBoxType::SYSTEM:
                    // show system item list
                    $options = [];
                    foreach (Define::DASHBOARD_BOX_SYSTEM_PAGES as $page) {
                        $options[array_get($page, 'id')] = exmtrans('dashboard.dashboard_box_system_pages.'.array_get($page, 'name'));
                    }
                    $form->select('target_system_id', exmtrans("dashboard.dashboard_box_options.target_system_id"))
                        ->required()
                        ->options($options)
                        ;
            }
        })->disableHeader();
        disableFormFooter($form);
        $form->tools(function (Form\Tools $tools) use ($id, $form) {
            $tools->disableView();
            $tools->disableList();
            $tools->disableDelete();

            // addhome button
            $tools->append('<a href="'.admin_base_path('').'" class="btn btn-sm btn-default"  style="margin-right: 5px"><i class="fa fa-home"></i>&nbsp;'. exmtrans('common.home').'</a>');
        });
        // add form saving and saved event
        $this->manageFormSaving($form);
        return $form;
    }

    protected function manageFormSaving($form)
    {
        // before saving
        $form->saving(function ($form) {
            if ($form->dashboard_box_type == DashboardBoxType::CHART) {
                // except fields not visible
                $options = $form->options;
                $chart_type = array_get($options, 'chart_type');
                $chart_options = array_get($options, 'chart_options')?? [];
                $new_options = [];
                if ($chart_type == ChartType::PIE) {
                    $options['chart_axis_label'] = [];
                    $options['chart_axis_name'] = [];
                    foreach($chart_options as $chart_option) {
                        if ($chart_option == ChartOptionType::LEGEND) {
                            $new_options[] = $chart_option;
                        }
                    }
                } else {
                    foreach($chart_options as $chart_option) {
                        if ($chart_option == ChartOptionType::BEGIN_ZERO) {
                            $new_options[] = $chart_option;
                        }
                    }
                }
                $options['chart_options'] = $new_options;
                $form->options = $options;
            }
        });
    }

    /**
     * get dashboard info using id, or query
     */
    protected function getDashboardInfo($id)
    {
        // set info with query --------------------------------------------------
        // get request
        $request = request();
        // get dashboard_id from query "dashboard_suuid"
        if (isset($id)) {
            $dashboard_box = DashboardBox::find($id);
            $dashboard = $dashboard_box->dashboard;
            return [$dashboard, $dashboard_box->dashboard_box_type, $dashboard_box->row_no, $dashboard_box->column_no];
        }
            
        if (!is_null($request->input('dashboard_id'))) {
            $dashboard = Dashboard::find($request->input('dashboard_id'));
        } else {
            // get dashboard_suuid from query
            $dashboard_suuid = $request->query('dashboard_suuid');
            if (!isset($dashboard_suuid)) {
                return [null, null, null, null];
            }
            $dashboard = Dashboard::findBySuuid($dashboard_suuid) ?? null;
        }
        if (!isset($dashboard)) {
            return [null, null, null, null];
        }

        if (!is_null($request->input('dashboard_box_type'))) {
            $dashboard_box_type = $request->input('dashboard_box_type');
        } else {
            // get dashboard_box_type from query
            $dashboard_box_type = $request->query('dashboard_box_type');
        }

        // row_no
        if (!is_null($request->input('row_no'))) {
            $row_no = $request->input('row_no');
        } else {
            // get from query
            $row_no = $request->query('row_no');
        }

        // column_no
        if (!is_null($request->input('column_no'))) {
            $column_no = $request->input('column_no');
        } else {
            // get from query
            $column_no = $request->query('column_no');
        }
        return [$dashboard, $dashboard_box_type, $row_no, $column_no];
    }
    
    /**
     * get views using table id
     * @param mixed custon_table id
     */
    public function tableViews(Request $request)
    {
        $id = $request->get('q');
        if (!isset($id)) {
            return [];
        }
        // get custom views
        $custom_table = CustomTable::find($id);
        $views = $custom_table->custom_views()->get(['id', 'view_view_name as text']);
        // if count > 0, return value.
        if (!is_null($views) && count($views) > 0) {
            return $views;
        }

        // create default view
        $view = createDefaultView($custom_table);
        createDefaultViewColumns($view);
        return [['id' => $view->id, 'text' => $view->view_view_name]];
    }
    
    /**
     * get view columns using view id
     * @param mixed custon_view id
     */
    public function chartAxis(Request $request, $axis_type)
    {
        $id = $request->get('q');
        if (!isset($id)) {
            return [];
        }
        // get custom views
        $custom_view = CustomView::find($id);

        return $custom_view->getColumnsSelectOptions($axis_type == 'y');
    }
    protected function getViewColumn($column_keys)
    {
        if(preg_match('/\d+_\d+$/i', $column_keys) === 1) {
            $keys = explode('_', $column_keys);
            if (count($keys) === 2) {
                if ($keys[0] == ViewKindType::AGGREGATE) {
                    $view_column = CustomViewSummary::find($keys[1]);
                } else {
                    $view_column = CustomViewColumn::find($keys[1]);
                }
                return $view_column;
            }
        }
        return null;
    }
}
