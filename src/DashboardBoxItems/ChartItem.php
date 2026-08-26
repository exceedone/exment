<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\AiSummaryService;
use Exceedone\Exment\Services\Dashboard\ChartFilter;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Services\Dashboard\FilterValue;

/**
 * Chart box. On top of the configured chart, the box renders a toolbar (runtime chart-type
 * switcher + the box's own chart filter) and, when the dashboard opted in, the AI summary
 * strip. Every data path applies the dashboard filter bar (df_*) and the chart filter
 * (bf_*) of the request, so chart, popover options and AI summary see the same rows.
 */
class ChartItem implements ItemInterface
{
    use TableItemTrait;

    /** labels / values are printed raw into a <script>: hex-encode so they can never break out */
    protected const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    // @phpstan-ignore-next-line
    protected $dashboard_box;

    // @phpstan-ignore-next-line
    protected $custom_table;

    // @phpstan-ignore-next-line
    protected $custom_view;

    // @phpstan-ignore-next-line
    protected $axis_x;

    // @phpstan-ignore-next-line
    protected $axis_y;

    /** @var string|null the configured type */
    protected $configured_type;

    /** @var string|null the type actually rendered (runtime switch applied) */
    protected $chart_type;

    // @phpstan-ignore-next-line
    protected $chart_series;

    // @phpstan-ignore-next-line
    protected $chart_options;

    // @phpstan-ignore-next-line
    protected $chart_axis_label;

    // @phpstan-ignore-next-line
    protected $chart_axis_name;

    /** @var DashboardFilter */
    protected $dashboard_filter;

    /** @var ChartFilter */
    protected $chart_filter;

    // @phpstan-ignore-next-line
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
        $this->chart_series = array_get($this->dashboard_box, 'options.chart_series');
        $this->chart_options = array_get($this->dashboard_box, 'options.chart_options') ?? [];
        $this->chart_axis_label = array_get($this->dashboard_box, 'options.chart_axis_label') ?? [];
        $this->chart_axis_name = array_get($this->dashboard_box, 'options.chart_axis_name') ?? [];

        // runtime chart-type switch (`ct` on the box request): presentation only, same data
        $this->configured_type = array_get($this->dashboard_box, 'options.chart_type');
        $this->chart_type = ChartType::resolve($this->configured_type, request()->input('ct'));
        if ($this->chart_type !== $this->configured_type) {
            // the saved option flag belongs to the configured family — give the switched type its own default
            $this->chart_options = in_array($this->chart_type, ChartType::legendTypes(), true)
                ? [ChartOptionType::LEGEND] : [ChartOptionType::BEGIN_ZERO];
        }

        $this->dashboard_filter = DashboardFilter::fromRequest($this->dashboard_box->dashboard ?? null);
        $this->chart_filter = ChartFilter::fromRequest($this->dashboard_box, $this->custom_table);
    }

    /**
     * get header
     */
    // @phpstan-ignore-next-line
    public function header()
    {
        return $this->tableheader();
    }

    /**
     * get footer
     */
    // @phpstan-ignore-next-line
    public function footer()
    {
        return null;
    }

    /**
     * get html(for display)
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    // @phpstan-ignore-next-line
    public function body()
    {
        if (($result = $this->hasPermission()) !== true) {
            return $result;
        }

        if (is_null($this->custom_view)) {
            return null;
        }

        // toolbar first: building the chart-filter popover also drops ticked values the
        // current scope no longer offers, and the data query below must see that
        $toolbar = $this->toolbarHtml();

        $common = [
            'suuid' => $this->dashboard_box->suuid,
            'chart_type' => $this->chart_type,
            'chart_height' => 300,
            'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
        ];

        if (ChartType::isMulti($this->chart_type)) {
            $result = $this->getMultiSeriesData();
            if ($result === false) {
                return exmtrans('dashboard.message.need_multiseries');
            }
            $chart = view('exment::dashboard.chart.echart_multi', $common + [
                'x_categories' => json_encode($result['x_categories'], static::JSON_FLAGS),
                'series_names' => json_encode($result['series_names'], static::JSON_FLAGS),
                'matrix' => json_encode($result['matrix'], static::JSON_FLAGS),
                'chart_axisx' => $result['axisx_label'],
                'chart_axisy' => $result['axisy_label'],
                'chart_colors' => json_encode($this->getChartPalette()),
                'chart_click' => json_encode($result['chart_click'], static::JSON_FLAGS),
            ]);
        } else {
            $result = $this->isAggregateView() ? $this->getAggregateData() : $this->getListData();
            if ($result === false) {
                return exmtrans('dashboard.message.need_setting');
            }
            $vars = $common + [
                'chart_data' => json_encode($result['chart_data'], static::JSON_FLAGS),
                'chart_labels' => json_encode($result['chart_label'], static::JSON_FLAGS),
                'chart_axisx' => $result['axisx_label'],
                'chart_axisy' => $result['axisy_label'],
                'chart_click' => json_encode($result['chart_click'] ?? null, static::JSON_FLAGS),
            ];
            if (ChartType::isEcharts($this->chart_type)) {
                $chart = view('exment::dashboard.chart.echart', $vars + [
                    'chart_colors' => json_encode($this->getChartPalette()),
                ]);
            } else {
                $chart = view('exment::dashboard.chart.chart', $vars + [
                    'chart_axisx_label' => in_array(ChartAxisType::X, $this->chart_axis_label),
                    'chart_axisy_label' => in_array(ChartAxisType::Y, $this->chart_axis_label),
                    'chart_axisx_name' => in_array(ChartAxisType::X, $this->chart_axis_name),
                    'chart_axisy_name' => in_array(ChartAxisType::Y, $this->chart_axis_name),
                    'chart_begin_zero' => in_array(ChartOptionType::BEGIN_ZERO, $this->chart_options),
                    'chart_color' => json_encode($this->getChartColor(count($result['chart_data']))),
                ]);
            }
        }

        return $toolbar . $chart->render() . $this->aiSummaryHtml();
    }

    /**
     * The chart's data for the AI summary (same rows the chart shows), or null.
     *
     * @return array|null {title, chart_type, axis_x_label, axis_y_label, labels, values, is_aggregate}
     */
    // @phpstan-ignore-next-line
    public function getInsightData()
    {
        if ($this->hasPermission() !== true || is_null($this->custom_view)) {
            return null;
        }
        $result = $this->isAggregateView() ? $this->getAggregateData() : $this->getListData();
        if ($result === false) {
            return null;
        }
        return [
            'title' => array_get($this->dashboard_box, 'dashboard_box_view_name'),
            'chart_type' => $this->chart_type,
            'axis_x_label' => $result['axisx_label'],
            'axis_y_label' => $result['axisy_label'],
            'labels' => collect($result['chart_label'])->values()->map(function ($v) {
                return is_scalar($v) ? (string) $v : $v;
            })->all(),
            'values' => collect($result['chart_data'])->values()->map(function ($v) {
                return is_numeric($v) ? floatval($v) : $v;
            })->all(),
            'is_aggregate' => $this->isAggregateView(),
        ];
    }

    /**
     * Stable string of this box's current filter state (dashboard filter + chart filter).
     */
    public function filterFingerprint(): string
    {
        return md5($this->dashboard_filter->fingerprint() . '|' . $this->chart_filter->fingerprint());
    }

    protected function isAggregateView(): bool
    {
        return array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE;
    }

    /**
     * AND the dashboard filter (targeting-aware) and this box's chart filter onto a query.
     */
    protected function applyFilters($query): void
    {
        $this->dashboard_filter->applyTo($query, $this->custom_table, $this->dashboard_box);
        $this->chart_filter->applyTo($query);
    }

    /**
     * The toolbar above the chart: [フィルター ▾] [chart type ▾]; '' when neither applies.
     */
    protected function toolbarHtml(): string
    {
        $types = [];
        foreach (ChartType::switchPool($this->configured_type) as $type) {
            $types[$type] = exmtrans('chart.chart_type_options.' . $type);
        }
        $fields = [];
        if ($this->chart_filter->isConfigured()) {
            // option lists are scoped like the chart itself: the view's own filters first
            $viewScope = function ($query) {
                $this->custom_view->filterModel($query);
            };
            $fields = $this->chart_filter->fields($this->dashboard_filter, $viewScope);
        }
        if (empty($types) && empty($fields)) {
            return '';
        }
        return view('exment::dashboard.chart.toolbar', [
            'types' => $types,
            'current_type' => $this->chart_type,
            'fields' => $fields,
            'filter_count' => count($this->chart_filter->values()),
            'captions' => $this->chart_filter->captions(),
        ])->render();
    }

    /**
     * The collapsed AI summary strip under the chart (only where the dashboard opted in).
     */
    protected function aiSummaryHtml(): string
    {
        return AiSummaryService::enabledForBox($this->dashboard_box)
            ? view('exment::dashboard.chart.ai_summary')->render()
            : '';
    }

    /**
     * get chart data from list-view
     */
    // @phpstan-ignore-next-line
    protected function getListData()
    {
        $view_column_x = CustomViewSummary::getSummaryViewColumn($this->axis_x);
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);

        if (is_nullorempty($view_column_x) || is_nullorempty($view_column_y)) {
            return false;
        }

        // create model for getting data --------------------------------------------------
        $model = $this->custom_table->getValueQuery();
        $this->applyFilters($model);
        $this->custom_view->filterModel($model);

        // get data
        $items = $model->get();

        $chart_label = $items->map(function ($val) use ($view_column_x) {
            // if get as CHARTITEM_LABEL, return label.
            if ($view_column_x == Define::CHARTITEM_LABEL) {
                return $val->getLabel();
            }
            // plain text: drawn on a canvas and hex-encoded before reaching the DOM
            return $view_column_x->column_item->setCustomValue($val)->text();
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
    // @phpstan-ignore-next-line
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
        $this->applyFilters($query);

        // get data
        $datalist = $this->custom_view->getQuery($query)->get();
        $chart_label = $datalist->map(function ($val) use ($item_x_list) {
            $labels = $item_x_list->map(function ($item_x) use ($val) {
                $item = $item_x->setCustomValue($val);
                return $item->text();
            });
            return $labels->implode(' ');
        });
        $chart_data = $datalist->pluck($item_y->uniqueName());

        // click-to-filter: a single group column only (a compound label has no one value)
        $chart_click = null;
        if (count($view_column_x_list) === 1) {
            $chart_click = $this->clickFilter(collect($view_column_x_list)->first(), $datalist->pluck($item_x_list->first()->uniqueName()));
        }

        // get item label
        $axisx_label = collect($view_column_x_list)->map(function ($item) {
            return array_get($item, 'view_column_name')?? $item->column_item->label();
        })->implode(' ');

        return [
            'chart_data'    => $chart_data,
            'chart_label'   => $chart_label,
            'axisx_label'   => $axisx_label,
            'axisy_label'   => array_get($view_column_y, 'view_column_name')?? $item_y->label(),
            'chart_click'   => $chart_click,
        ];
    }

    /**
     * Click-to-filter payload of a chart whose group column is an item of the dashboard
     * filter bar: {column, values[]} with values[i] = the stored value behind data point i
     * (what a df_{column} param compares against), so clicking a bar selects it on the bar.
     * null when the column is not a filter item, belongs to another table, or the grouping
     * is a derived bucket (date format) whose value never equals the stored one.
     *
     * @param mixed $view_column  the view's group CustomViewColumn
     * @param iterable $raw_values  raw group values, index-aligned with the chart's points
     * @return array|null
     */
    protected function clickFilter($view_column, $raw_values)
    {
        $config = $this->dashboard_filter->config();
        $custom_column = $view_column ? $view_column->custom_column : null;
        if ($config === null || is_nullorempty($custom_column) || is_nullorempty($this->custom_table)) {
            return null;
        }
        if (array_get($view_column, 'view_column_table_id') != $this->custom_table->id
            || !is_nullorempty(array_get($view_column, 'view_group_condition'))
            || $config->dim($custom_column->column_name) === null) {
            return null;
        }
        return [
            'column' => $custom_column->column_name,
            'values' => collect($raw_values)->map(function ($v) {
                return is_scalar($v) ? (string) $v : '';
            })->values()->all(),
        ];
    }

    /**
     * Pivoted data of a multi-series chart from an aggregate view grouped by 2+ columns:
     * the series column (chart_series, default = 2nd column) splits the legend, the first
     * other group column is the X axis, the measure (chart_axisy) fills each cell.
     *
     * @return array|false {x_categories[], series_names[], matrix[seriesIdx][xIdx], axisx_label, axisy_label}
     */
    // @phpstan-ignore-next-line
    protected function getMultiSeriesData()
    {
        if (!$this->isAggregateView()) {
            return false;
        }
        $view_columns = collect($this->custom_view->custom_view_columns)->values();
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        if ($view_columns->count() < 2 || is_nullorempty($view_column_y)) {
            return false;
        }

        $series_pos = 1;
        foreach ($view_columns as $pos => $column) {
            if (!is_nullorempty($this->chart_series) && (ViewKindType::DEFAULT . '_' . $column->id) === $this->chart_series) {
                $series_pos = $pos;
            }
        }
        $x_pos = $series_pos === 0 ? 1 : 0;

        $items = $view_columns->map(function ($item) {
            return $item->column_item->options([
                'summary' => true,
                'summary_index' => ViewKindType::DEFAULT . '_' . $item->id,
            ]);
        });
        $item_x = $items[$x_pos];
        $item_series = $items[$series_pos];
        $item_y = $view_column_y->column_item;

        $query = $this->custom_table->getValueQuery();
        $this->applyFilters($query);
        $datalist = $this->custom_view->getQuery($query)->get();

        $x_texts = $datalist->map(function ($val) use ($item_x) {
            return $item_x->setCustomValue($val)->text();
        })->all();
        $series_texts = $datalist->map(function ($val) use ($item_series) {
            return $item_series->setCustomValue($val)->text();
        })->all();
        $y_values = $datalist->pluck($item_y->uniqueName())->all();

        // strict unique so "7" and "007" stay distinct categories
        $x_categories = collect($x_texts)->unique(null, true)->values();
        $series_names = collect($series_texts)->unique(null, true)->values();

        $x_raws = $datalist->pluck($item_x->uniqueName())->all();
        $x_raw_by_category = [];
        $matrix = array_fill(0, $series_names->count(), array_fill(0, $x_categories->count(), 0));
        foreach ($y_values as $i => $value) {
            $x_idx = $x_categories->search($x_texts[$i], true);
            $s_idx = $series_names->search($series_texts[$i], true);
            if ($x_idx !== false && $s_idx !== false) {
                // accumulate: a view grouped by 3+ columns yields several rows per cell
                $matrix[$s_idx][$x_idx] += is_numeric($value) ? floatval($value) : 0;
                $x_raw_by_category[$x_idx] = $x_raw_by_category[$x_idx] ?? ($x_raws[$i] ?? null);
            }
        }

        return [
            'x_categories' => $x_categories->all(),
            'series_names' => $series_names->all(),
            'matrix'       => $matrix,
            'axisx_label'  => array_get($view_columns[$x_pos], 'view_column_name') ?? $item_x->label(),
            'axisy_label'  => array_get($view_column_y, 'view_column_name') ?? $item_y->label(),
            'chart_click'  => $this->clickFilter($view_columns[$x_pos], $x_categories->keys()->map(function ($idx) use ($x_raw_by_category) {
                return $x_raw_by_category[$idx] ?? null;
            })),
        ];
    }

    /**
     * set laravel admin embeds option
     */
    // @phpstan-ignore-next-line
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
                'data-linkage' => json_encode([
                    'options_target_view_id' => admin_urls('dashboardbox', 'table_views', DashboardBoxType::CHART),
                    // the chart filter fields are columns of the table
                    'options_chart_filters' => admin_urls('dashboardbox', 'chart_filter_columns'),
                ]),
                'data-linkage-expand' => json_encode(['dashboard_suuid' => $dashboard->suuid])
            ]);

        $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
            ->required()
            ->options(function ($value, $field, $model) use ($dashboard) {
                return ChartItem::getCustomViewSelectOptions($value, $field, $model, $dashboard);
            })
            ->loads(
                ['options_chart_axisx', 'options_chart_axisy', 'options_chart_series'],
                [admin_url('dashboardbox/chart_axis').'/x', admin_url('dashboardbox/chart_axis').'/y', admin_url('dashboardbox/chart_axis').'/series']
            );

        // link to manual
        $form->descriptionHtml(sprintf(exmtrans("chart.help.chartitem_manual"), getManualUrl('dashboard?id='.exmtrans('chart.chartitem_manual'))));

        $viewColumnOptions = function ($summary) {
            return function ($value, $model) use ($summary) {
                $custom_view = ChartItem::formCustomView($model);
                return $custom_view ? array_column($custom_view->getViewColumnsSelectOptions($summary), 'text', 'id') : [];
            };
        };
        $form->select('chart_axisx', exmtrans("dashboard.dashboard_box_options.chart_axisx"))
            ->required()
            ->default(Define::CHARTITEM_LABEL)
            ->options($viewColumnOptions(false));

        $form->select('chart_axisy', exmtrans("dashboard.dashboard_box_options.chart_axisy"))
            ->required()
            ->options($viewColumnOptions(true));

        // series column of a multi-series chart (shown for those types only, see the script below)
        $form->select('chart_series', exmtrans("dashboard.dashboard_box_options.chart_series"))
            ->help(exmtrans('dashboard.message.need_multiseries'))
            ->options(function ($value, $model) {
                // laravel-admin binds this closure to the model, so the class is named explicitly
                return array_column(ChartItem::seriesSelectOptions(ChartItem::formCustomView($model)), 'text', 'id');
            });

        // chart filter: columns of the table offered as filter fields on this chart only
        $form->multipleSelect('chart_filters', exmtrans("dashboard.dashboard_box_options.chart_filters"))
            ->options(function ($value, $model) {
                $table_id = array_get(request()->all(), 'options.target_table_id') ?? array_get($model->data(), 'target_table_id');
                $custom_table = isset($table_id) ? CustomTable::getEloquent($table_id) : null;
                $options = [];
                foreach ($custom_table ? $custom_table->custom_columns : [] as $column) {
                    $options[$column->column_name] = $column->column_view_name . ' (' . $column->column_name . ')';
                }
                return $options;
            })
            ->help(exmtrans("dashboard.dashboard_box_options.chart_filters_help"));

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

        $legendTypes = json_encode(ChartType::legendTypes());
        $multiTypes = json_encode(ChartType::multiTypes());
        $script = <<<EOT
        var exmentLegendCharts = $legendTypes;
        var exmentMultiCharts = $multiTypes;
        function setChartOptions(val) {
            var legend = exmentLegendCharts.indexOf(val) >= 0;
            $('#chart_options > .icheck:nth-child(1)').toggle(legend);
            $('#chart_options > .icheck:nth-child(2)').toggle(!legend);
            $('.options_chart_series').closest('.form-group').toggle(exmentMultiCharts.indexOf(val) >= 0);
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
    // @phpstan-ignore-next-line
    public static function saving(&$form)
    {
        // except fields not visible
        $options = $form->options;
        $chart_type = array_get($options, 'chart_type');
        $chart_options = array_get($options, 'chart_options')?? [];
        $keep = in_array($chart_type, ChartType::legendTypes(), true) ? ChartOptionType::LEGEND : ChartOptionType::BEGIN_ZERO;
        if (ChartType::isCircular($chart_type)) {
            $options['chart_axis_label'] = [];
            $options['chart_axis_name'] = [];
        }
        $options['chart_options'] = array_values(array_filter($chart_options, function ($option) use ($keep) {
            return $option == $keep;
        }));

        // series column only matters to a multi-series type
        if (!ChartType::isMulti($chart_type)) {
            unset($options['chart_series']);
        }

        // chart filter: plain column names only; an empty selection posts nothing = cleared
        $filters = array_values(array_filter((array) array_get($options, 'chart_filters', []), function ($column) {
            return FilterValue::isIdentifier($column);
        }));
        if (count($filters)) {
            $options['chart_filters'] = $filters;
        } else {
            unset($options['chart_filters']);
        }

        $form->options = $options;
    }

    /**
     * The view chosen on the box form (the posted one while editing, else the stored one).
     */
    // @phpstan-ignore-next-line
    public static function formCustomView($model)
    {
        $view_id = array_get(request()->all(), 'options.target_view_id') ?? array_get($model->data(), 'target_view_id');
        return isset($view_id) ? CustomView::getEloquent($view_id) : null;
    }

    /**
     * Series-column choices of a multi-series chart = the group columns of the aggregate view.
     *
     * @return array<int, array{id:string, text:string|null}>
     */
    // @phpstan-ignore-next-line
    public static function seriesSelectOptions($custom_view)
    {
        $options = [];
        if (is_nullorempty($custom_view) || $custom_view->view_kind_type != ViewKindType::AGGREGATE) {
            return $options;
        }
        foreach ($custom_view->custom_view_columns_cache as $custom_view_column) {
            $condition_item = $custom_view_column->condition_item;
            $options[] = [
                'id'   => ViewKindType::DEFAULT . '_' . $custom_view_column->id,
                'text' => $condition_item ? $condition_item->getSelectColumnText($custom_view_column, $custom_view->custom_table) : null,
            ];
        }
        return $options;
    }

    /**
     * get chart color array.
     *
     * @return array|string Chart color array
     */
    // @phpstan-ignore-next-line
    protected function getChartColor($datacnt)
    {
        $chart_color = $this->getChartPalette();

        if ($this->chart_type == ChartType::PIE) {
            $colors = [];
            for ($i = 0; $i < $datacnt; $i++) {
                $colors[] = $chart_color[$i % count($chart_color)];
            }
            return $colors;
        }
        return $chart_color[0];
    }

    /**
     * The configured color list (config exment.chart_backgroundColor), never empty.
     *
     * @return string[]
     */
    protected function getChartPalette()
    {
        $chart_color = config('exment.chart_backgroundColor');
        $chart_color = stringToArray(empty($chart_color) ? 'red' : $chart_color);
        return count($chart_color) > 0 ? array_values($chart_color) : ['red'];
    }

    // @phpstan-ignore-next-line
    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
