<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;

class ChartItem implements ItemInterface
{
    use TableItemTrait;

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
        return $this->tableheader();
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
        if (($result = $this->hasPermission()) !== true) {
            return $result;
        }

        if (is_null($this->custom_view)) {
            return null;
        }

        if (array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE) {
            $result = $this->getAggregateData();
        } else {
            $result = $this->getListData();
        }

        if ($result === false) {
            return exmtrans('dashboard.message.need_setting');
        }

        $axisx_label = $result['axisx_label'];
        $axisy_label = $result['axisy_label'];
        $chart_data = $result['chart_data'];
        $chart_label = $result['chart_label'];

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
            'chart_axisx' => $axisx_label,
            'chart_axisy' => $axisy_label,
            'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
            'chart_begin_zero' => in_array(ChartOptionType::BEGIN_ZERO, $this->chart_options),
            'chart_color' => json_encode($this->getChartColor(count($chart_data)))
        ])->render();
    }

    /**
     * get chart data from list-view
     */
    protected function getListData()
    {
        $view_column_x = CustomViewSummary::getSummaryViewColumn($this->axis_x);
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);

        if (is_nullorempty($view_column_x) || is_nullorempty($view_column_y)) {
            return false;
        }

        // create model for getting data --------------------------------------------------
        $model = $this->custom_table->getValueQuery();

        $this->custom_view->filterModel($model);

        // get data
        $items = $model->get();

        $chart_label = $items->map(function ($val) use ($view_column_x) {
            // if get as CHARTITEM_LABEL, return label.
            if ($view_column_x == Define::CHARTITEM_LABEL) {
                return $val->getLabel();
            }
            return esc_html($view_column_x->column_item->setCustomValue($val)->text());
        });
        $axis_y_name = $view_column_y->custom_column->column_name;
        $chart_data = $items->pluck('value.'.$axis_y_name);

        if ($view_column_x == Define::CHARTITEM_LABEL) {
            $axisx_label = $this->custom_table->table_view_name;
        } else {
            $axisx_label = array_get($view_column_x, 'view_column_name') ?? $view_column_x->column_item->label();
        }

        return [
            'chart_data'    => $chart_data,
            'chart_label'   => $chart_label,
            'axisx_label'   => $axisx_label,
            'axisy_label'   => array_get($view_column_y, 'view_column_name') ?? $view_column_y->column_item->label(),
        ];
    }

    /**
     * get chart data from aggregate-view
     */
    protected function getAggregateData()
    {
        $view_column_x_list = $this->custom_view->custom_view_columns;
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);

        if (is_nullorempty($view_column_x_list) || count(($view_column_x_list)) == 0 || is_nullorempty($view_column_y)) {
            return false;
        }

        $item_x_list = collect($view_column_x_list)->map(function ($item) {
            $summary_index = ViewKindType::DEFAULT . '_' . $item->id;
            return $item->column_item->options([
                'summary' => true,
                'summary_index' => $summary_index
            ]);
        });
        $item_y = $view_column_y->column_item;

        // create model for getting data --------------------------------------------------
        $query = $this->custom_table->getValueQuery();

        // get data
        $datalist = $this->custom_view->getQuery($query)->get();
        $chart_label = $datalist->map(function ($val) use ($item_x_list) {
            $labels = $item_x_list->map(function ($item_x) use ($val) {
                $item = $item_x->setCustomValue($val);
                return esc_html($item->text());
            });
            return $labels->implode(' ');
        });
        $chart_data = $datalist->pluck($item_y->uniqueName());

        // get item label
        $axisx_label = collect($view_column_x_list)->map(function ($item) {
            return array_get($item, 'view_column_name')?? $item->column_item->label();
        })->implode(' ');

        return [
            'chart_data'    => $chart_data,
            'chart_label'   => $chart_label,
            'axisx_label'   => $axisx_label,
            'axisy_label'   => array_get($view_column_y, 'view_column_name')?? $item_y->label(),
        ];
    }
    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        $form->select('chart_type', exmtrans("dashboard.dashboard_box_options.chart_type"))
                ->required()
                ->options(ChartType::transArray("chart.chart_type_options"));

        // get only has summaryview
        $model = CustomTable::query();
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
            ->options(function ($value, $field, $model) use ($dashboard) {
                return ChartItem::getCustomViewSelectOptions($value, $field, $model, $dashboard);
            })
            ->loads(
                ['options_chart_axisx', 'options_chart_axisy'],
                [admin_url('dashboardbox/chart_axis').'/x', admin_url('dashboardbox/chart_axis').'/y']
            );

        // link to manual
        $form->descriptionHtml(sprintf(exmtrans("chart.help.chartitem_manual"), getManualUrl('dashboard?id='.exmtrans('chart.chartitem_manual'))));

        $form->select('chart_axisx', exmtrans("dashboard.dashboard_box_options.chart_axisx"))
            ->required()
            ->default(Define::CHARTITEM_LABEL)
            ->options(function ($value, $model) {
                $target_view_id = array_get(request()->all(), 'options.target_view_id') ?? array_get($model->data(), 'target_view_id');
                if (!isset($target_view_id)) {
                    return [];
                }

                $custom_view = CustomView::getEloquent($target_view_id);
                if (!isset($custom_view)) {
                    return [];
                }

                $options = $custom_view->getViewColumnsSelectOptions(false);
                return array_column($options, 'text', 'id');
            });

        $form->select('chart_axisy', exmtrans("dashboard.dashboard_box_options.chart_axisy"))
            ->required()
            ->options(function ($value, $model) {
                $target_view_id = array_get(request()->all(), 'options.target_view_id') ?? array_get($model->data(), 'target_view_id');
                if (!isset($target_view_id)) {
                    return [];
                }

                $custom_view = CustomView::getEloquent($target_view_id);
                if (!isset($custom_view)) {
                    return [];
                }

                $options = $custom_view->getViewColumnsSelectOptions(true);
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
                $('#chart_options > .icheck:nth-child(1)').show();
                $('#chart_options > .icheck:nth-child(2)').hide();
            } else {
                $('#chart_options > .icheck:nth-child(1)').hide();
                $('#chart_options > .icheck:nth-child(2)').show();
            }
        }
        setChartOptions($('.options_chart_type').val());

        $(document).off('change.exment_dashboard', ".options_chart_type");
        $(document).on('change.exment_dashboard', ".options_chart_type", function () {
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
     * @return array Chart color array
     */
    protected function getChartColor($datacnt)
    {
        $chart_color = config('exment.chart_backgroundColor');
        $chart_color = stringToArray(empty($chart_color) ? 'red' : $chart_color);

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
            return (count($chart_color) > 0) ? $chart_color[0] : '';
        }
    }

    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
