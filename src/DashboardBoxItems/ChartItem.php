<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;

class ChartItem implements ItemInterface
{
    protected $dashboard_box;

    protected $custom_table;
    
    protected $custom_view;
    
    protected $axis_x;
    
    protected $axis_y;
    
    protected $chart_type;
    
    protected $chart_options;
    
    protected $chart_axis_label;
    
    protected $chart_axis_name;
    
    public function __construct($dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;

        $table_id = array_get($this->dashboard_box, 'options.target_table_id');
        $view_id = array_get($this->dashboard_box, 'options.target_view_id');

        // get table and view
        $this->custom_table = CustomTable::getEloquent($table_id);
        $this->custom_view = CustomView::getEloquent($view_id);

        $this->axis_x = array_get($this->dashboard_box, 'options.chart_axisx');
        $this->axis_y = array_get($this->dashboard_box, 'options.chart_axisy');
        $this->chart_type = array_get($this->dashboard_box, 'options.chart_type');
        $this->chart_options = array_get($this->dashboard_box, 'options.chart_options')?? [];
        $this->chart_axis_label = array_get($this->dashboard_box, 'options.chart_axis_label')?? [];
        $this->chart_axis_name = array_get($this->dashboard_box, 'options.chart_axis_name')?? [];
    }

    
    /**
     * get header
     */
    public function header()
    {
        return null;
    }
    
    /**
     * get footer
     */
    public function footer()
    {
        return null;
    }
    
    /**
     * get html(for display)
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function body()
    {
        if (is_null($this->custom_view)) {
            return null;
        }
        $view_column_x = $this->getViewColumn($this->axis_x);
        $view_column_y = $this->getViewColumn($this->axis_y);
        $item_x = $view_column_x->column_item;
        $item_y = $view_column_y->column_item;

        // create model for getting data --------------------------------------------------
        $model = $this->custom_table->getValueModel();

        if (array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE) {
            $item_x->options([
                'summary' => true,
                'summary_index' => $this->axis_x
            ]);
            // get data
            $datalist = $this->custom_view->getValueSummary($model, $this->custom_table->table_name);
            $chart_label = $datalist->map(function ($val) use ($item_x) {
                $item = $item_x->setCustomValue($val);
                $option = SystemColumn::getOption(['name' => $item->name()]);
                if (array_get($option, 'type') == 'user') {
                    return getUserName($item->text());
                } else {
                    return esc_html($item->text());
                }
            });
            $chart_data = $datalist->pluck("column_$this->axis_y");
        } else {
            // filter model
            $model = \Exment::user()->filterModel($model, $this->custom_table->table_name, $this->custom_view);
            // get data
            $datalist = $model->get();
            $chart_label = $datalist->map(function ($val) use ($item_x) {
                return esc_html($item_x->setCustomValue($val)->text());
            });
            $axis_y_name = $view_column_y->custom_column->column_name;
            $chart_data = $datalist->pluck('value.'.$axis_y_name);
        }

        return view('exment::dashboard.chart.chart', [
            'suuid' => $this->dashboard_box->suuid,
            'chart_data' => json_encode($chart_data, JSON_UNESCAPED_SLASHES),
            'chart_labels' => json_encode($chart_label, JSON_UNESCAPED_SLASHES),
            'chart_type' => $this->chart_type,
            'chart_height' => 300,
            'chart_axisx_label' => in_array(ChartAxisType::X, $this->chart_axis_label),
            'chart_axisy_label' => in_array(ChartAxisType::Y, $this->chart_axis_label),
            'chart_axisx_name' => in_array(ChartAxisType::X, $this->chart_axis_name),
            'chart_axisy_name' => in_array(ChartAxisType::Y, $this->chart_axis_name),
            'chart_axisx' => array_get($view_column_x, 'view_column_name')?? $item_x->label(),
            'chart_axisy' => array_get($view_column_y, 'view_column_name')?? $item_y->label(),
            'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
            'chart_begin_zero' => in_array(ChartOptionType::BEGIN_ZERO, $this->chart_options),
            'chart_color' => json_encode($this->getChartColor(count($chart_data)))
        ])->render();
    }

    /**
     * get custom view column or summary record.
     */
    protected function getViewColumn($column_keys)
    {
        if (preg_match('/\d+_\d+$/i', $column_keys) === 1) {
            $keys = explode('_', $column_keys);
            if (count($keys) === 2) {
                if ($keys[0] == ViewKindType::AGGREGATE) {
                    $view_column = CustomViewSummary::getEloquent($keys[1]);
                } else {
                    $view_column = CustomViewColumn::getEloquent($keys[1]);
                }
                return $view_column;
            }
        }
        return null;
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        $form->description(exmtrans('dashboard.description_chart'));

        $form->select('chart_type', exmtrans("dashboard.dashboard_box_options.chart_type"))
                ->required()
                ->options(ChartType::transArray("chart.chart_type_options"));

        // get only has summaryview
        $model = CustomTable::whereHas('custom_views', function ($query) {
            $query->where('view_kind_type', ViewKindType::AGGREGATE);
        });
        $tables = CustomTable::filterList($model, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])
            ->pluck('table_view_name', 'id');
        $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
            ->required()
            ->options($tables)
            ->attribute([
                'data-linkage' => json_encode(['options_target_view_id' => admin_urls('dashboardbox', 'table_views', DashboardBoxType::CHART)]),
                'data-linkage-expand' => json_encode(['dashboard_suuid' => $dashboard->suuid])
            ]);

        $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
            ->required()
            ->options(function ($value) use($dashboard) {
                if (!isset($value)) {
                    return [];
                }
                return CustomView::getEloquent($value)->custom_table->custom_views
                    ->filter(function ($value) {
                        return array_get($value, 'view_kind_type') != ViewKindType::CALENDAR;
                    })
                    ->filter(function ($value) use($view, $dashboard) {
                        if(array_get($dashboard, 'dashboard_type') != DashBoardType::SYSTEM){
                            return true;
                        }
                        return array_get($value, 'view_type') == ViewType::SYSTEM;
                    })->pluck('view_view_name', 'id');
            })
            ->loads(
                ['options_chart_axisx', 'options_chart_axisy'],
                [admin_url('dashboardbox/chart_axis').'/x', admin_url('dashboardbox/chart_axis').'/y']
            );

        $form->select('chart_axisx', exmtrans("dashboard.dashboard_box_options.chart_axisx"))
            ->required()
            ->options(function ($value) {
                if (!isset($value)) {
                    return [];
                }
                $keys = explode("_", $value);
                if ($keys[0] == ViewKindType::DEFAULT) {
                    $view_column = CustomViewColumn::getEloquent($keys[1]);
                } else {
                    $view_column = CustomViewSummary::getEloquent($keys[1]);
                }
                $options = $view_column->custom_view->getColumnsSelectOptions(false);
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
    }

    /**
     * saving event
     */
    public static function saving(&$form)
    {
        // except fields not visible
        $options = $form->options;
        $chart_type = array_get($options, 'chart_type');
        $chart_options = array_get($options, 'chart_options')?? [];
        $new_options = [];
        if ($chart_type == ChartType::PIE) {
            $options['chart_axis_label'] = [];
            $options['chart_axis_name'] = [];
            foreach ($chart_options as $chart_option) {
                if ($chart_option == ChartOptionType::LEGEND) {
                    $new_options[] = $chart_option;
                }
            }
        } else {
            foreach ($chart_options as $chart_option) {
                if ($chart_option == ChartOptionType::BEGIN_ZERO) {
                    $new_options[] = $chart_option;
                }
            }
        }
        $options['chart_options'] = $new_options;
        $form->options = $options;
    }

    /**
     * get chart color array.
     *
     * @return chart color array
     */
    protected function getChartColor($datacnt)
    {
        $chart_color = config('exment.chart_backgroundColor', ['red']);
        if ($this->chart_type == ChartType::PIE) {
            $colors = [];
            for ($i = 0; $i < $datacnt; $i++) {
                if (count($colors) >= $datacnt) {
                    break;
                }

                $colors[] = $chart_color[$i % count($chart_color)];
            }
            return $colors;
        } else {
            return (count($chart_color) > 0)? $chart_color[0]: '';
        }
    }

    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
