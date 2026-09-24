<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\ChartAggregate;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\AiSummaryService;
use Exceedone\Exment\Services\Dashboard\ChartColors;
use Exceedone\Exment\Services\Dashboard\ChartFilter;
use Exceedone\Exment\Services\Dashboard\ChartSort;
use Exceedone\Exment\Services\Dashboard\ChartSorter;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Services\Dashboard\SummaryAverage;

/**
 * Chart box. On top of the configured chart, the box renders a toolbar (runtime chart-type
 * switcher + the box's own chart filter) and, when the dashboard opted in, the AI summary
 * strip. Every data path applies the dashboard filter bar (df_*) and the chart filter
 * (bf_*) of the request, so chart, popover options and AI summary see the same rows.
 *
 * Cross-highlight: the bar item that is this chart's own X column never filters the chart
 * (highlightColumn) — every category stays on screen and the picked values are drawn solid,
 * the rest faded, the way Power BI treats the visual a selection was made on. The other
 * boxes filter as usual.
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

    /** @var string|null aggregate of the Y value on this box (ChartAggregate value), null = as the view */
    protected $aggregate;

    /** @var string|null the configured type */
    protected $configured_type;

    /** @var string|null the type actually rendered (runtime switch applied) */
    protected $chart_type;

    /** @var ChartSort|null runtime sort of the points (`cs` on the request: a field of the chart + direction), null = the view's order */
    protected $chart_sort;

    /** @var string[] display options applied from the box menu (`cd` on the request): 'labels' */
    protected $chart_display;

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

    /** @var string|null|false highlightColumn() memo (false = not resolved yet) */
    protected $highlight_column = false;

    /** @var bool|null canEditColors() memo */
    protected $can_edit_colors;

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
        // TEMPORARILY DISABLED (2026-09-23): 集計方法 (box option chart_aggregate). A stored value is
        // ignored, so every box renders as its view. Re-enable by restoring the line below together
        // with the form field in setupForm() and the sanitizer in saving().
        // $this->aggregate = ChartAggregate::resolve(array_get($this->dashboard_box, 'options.chart_aggregate'));
        $this->aggregate = null;
        $this->chart_series = array_get($this->dashboard_box, 'options.chart_series');
        $this->chart_options = array_get($this->dashboard_box, 'options.chart_options') ?? [];
        $this->chart_axis_label = array_get($this->dashboard_box, 'options.chart_axis_label') ?? [];
        $this->chart_axis_name = array_get($this->dashboard_box, 'options.chart_axis_name') ?? [];

        // runtime chart-type switch (`ct` on the box request): presentation only, same data
        $this->configured_type = array_get($this->dashboard_box, 'options.chart_type');
        $this->chart_type = ChartType::resolve($this->configured_type, request()->input('ct'));
        $this->chart_sort = ChartSort::parse(request()->input('cs'));
        $this->chart_display = static::displayOptions(request()->input('cd'));
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

        // the chart-filter popover first: building it also drops ticked values the current
        // scope no longer offers, and the data query below must see that
        $fields = $this->chartFilterFields();

        $common = [
            'suuid' => $this->dashboard_box->suuid,
            'chart_type' => $this->chart_type,
            'chart_height' => 300,
            'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
            'chart_labels_on' => in_array('labels', $this->chart_display, true),
            // right-click a point / series to paint it (dashboard.js colorMenu)
            'chart_color_edit' => $this->canEditColors(),
        ];

        if (ChartType::isMulti($this->chart_type)) {
            $result = $this->getMultiSeriesData();
            if ($result === false) {
                return exmtrans('dashboard.message.need_multiseries');
            }
            $result = $this->sorted($result, true);
            $chart = view('exment::dashboard.chart.echart_multi', $common + [
                'x_categories' => json_encode($result['x_categories'], static::JSON_FLAGS),
                'series_names' => json_encode($result['series_names'], static::JSON_FLAGS),
                'matrix' => json_encode($result['matrix'], static::JSON_FLAGS),
                'chart_axisx' => $result['axisx_label'],
                'chart_axisy' => $result['axisy_label'],
                // one color per series (index-aligned with series_names); the heatmap and the
                // boxplot paint no series, they keep the palette
                'chart_colors' => json_encode(ChartType::supportsColorEdit($this->chart_type)
                    ? $this->chartColors()->colorsFor(ChartColors::SERIES, $result['series_names'], $this->getChartPalette())
                    : $this->getChartPalette()),
                'chart_click' => json_encode($result['chart_click'], static::JSON_FLAGS),
            ]);
        } else {
            $result = $this->isAggregateView() ? $this->getAggregateData() : $this->getListData();
            if ($result === false) {
                return exmtrans('dashboard.message.need_setting');
            }
            // a point's color is decided in the VIEW's own order and then travels with the
            // point through the runtime sort, so re-sorting moves the colors instead of
            // repainting the categories (a viewer recognises a bar by its color)
            $result['point_colors'] = $this->pointColors(collect($result['chart_label'])->values()->all());
            $result = $this->sorted($result, false);
            $vars = $common + [
                'chart_data' => json_encode($result['chart_data'], static::JSON_FLAGS),
                'chart_labels' => json_encode($result['chart_label'], static::JSON_FLAGS),
                'chart_axisx' => $result['axisx_label'],
                'chart_axisy' => $result['axisy_label'],
                'chart_click' => json_encode($result['chart_click'] ?? null, static::JSON_FLAGS),
                'chart_counts' => json_encode($result['chart_counts'] ?? null, static::JSON_FLAGS),
            ];
            if (ChartType::isEcharts($this->chart_type)) {
                $chart = view('exment::dashboard.chart.echart', $vars + [
                    'chart_colors' => json_encode($this->singleSeriesPalette()),
                    'chart_point_colors' => json_encode($result['point_colors']),
                ]);
            } else {
                $chart = view('exment::dashboard.chart.chart', $vars + [
                    'chart_axisx_label' => in_array(ChartAxisType::X, $this->chart_axis_label),
                    'chart_axisy_label' => in_array(ChartAxisType::Y, $this->chart_axis_label),
                    'chart_axisx_name' => in_array(ChartAxisType::X, $this->chart_axis_name),
                    'chart_axisy_name' => in_array(ChartAxisType::Y, $this->chart_axis_name),
                    'chart_begin_zero' => in_array(ChartOptionType::BEGIN_ZERO, $this->chart_options),
                    'chart_color' => json_encode($this->getChartColor($result['point_colors'])),
                ]);
            }
        }

        return $this->toolbarHtml($fields) . $chart->render() . $this->aiSummaryHtml();
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
        $result = $this->sorted($result, false);
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
        return md5($this->dashboard_filter->fingerprint($this->dashboardExcept()) . '|' . $this->chart_filter->fingerprint());
    }

    protected function isAggregateView(): bool
    {
        return array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE;
    }

    /**
     * AND the dashboard filter (targeting-aware, minus the highlighted X item) and this
     * box's chart filter onto a query.
     */
    protected function applyFilters($query): void
    {
        $this->dashboard_filter->applyTo($query, $this->custom_table, $this->dashboard_box, $this->dashboardExcept());
        $this->chart_filter->applyTo($query);
    }

    /**
     * The bar items this box does not filter by: its highlighted X item, if any.
     *
     * @return string[]
     */
    protected function dashboardExcept(): array
    {
        $column = $this->highlightColumn();
        return $column === null ? [] : [$column];
    }

    /**
     * The filter bar item this chart highlights instead of filtering by: its own X column,
     * when a click on the chart picks it (clickColumn) and the item narrows this box at all
     * (targeting). With the item picked — on the chart or on the bar — the chart keeps every
     * category and fades the ones not picked; dashboard.js then repaints a change of the
     * pick in place, since the data cannot have changed. null: the chart filters like any
     * other box.
     */
    protected function highlightColumn(): ?string
    {
        if ($this->highlight_column !== false) {
            return $this->highlight_column;
        }
        $this->highlight_column = null;
        $custom_column = $this->clickColumn($this->xViewColumn());
        $config = $this->dashboard_filter->config();
        if ($custom_column !== null && $config !== null && $config->appliesTo($custom_column->column_name, $this->dashboard_box)) {
            $this->highlight_column = $custom_column->column_name;
        }
        return $this->highlight_column;
    }

    /**
     * The view column on the X axis as the rendered type reads the view: the sole group
     * column of an aggregate view (a compound label has no one value), or the X column of a
     * multi-series chart. null for a list view or a compound grouping.
     *
     * @return mixed CustomViewColumn|null
     */
    protected function xViewColumn()
    {
        if (is_null($this->custom_view) || !$this->isAggregateView()) {
            return null;
        }
        $view_columns = collect($this->custom_view->custom_view_columns)->values();
        if (ChartType::isMulti($this->chart_type)) {
            return $view_columns->count() >= 2 ? $view_columns[$this->multiSeriesXPos($view_columns)] : null;
        }
        return $view_columns->count() === 1 ? $view_columns[0] : null;
    }

    /**
     * The display options of the box menu named by `cd` ("labels"): the known ones, trimmed,
     * deduplicated, in a fixed order; anything else is dropped.
     *
     * @param mixed $cd
     * @return string[]
     */
    public static function displayOptions($cd): array
    {
        if (!is_string($cd) || $cd === '') {
            return [];
        }
        $known = ['labels'];
        $wanted = array_map('trim', explode(',', $cd));
        return array_values(array_filter($known, function ($option) use ($wanted) {
            return in_array($option, $wanted, true);
        }));
    }

    /**
     * The toolbar choices of a box worth remembering, from the request values ct / cs / cd:
     * a chart type that is a legal switch AND differs from the configured one, a well-formed
     * sort, the known display options. null when nothing is left (= the box setting).
     *
     * @param array<string, mixed> $input
     * @param string|null $configured  the box's configured chart type
     * @return array<string, string>|null
     */
    public static function toolbarState(array $input, $configured): ?array
    {
        $state = [];
        $ct = $input['ct'] ?? null;
        if (is_string($ct) && $ct !== $configured && ChartType::resolve($configured, $ct) === $ct) {
            $state['ct'] = $ct;
        }
        $sort = ChartSort::parse($input['cs'] ?? null);
        if ($sort !== null) {
            $state['cs'] = $sort->field . ':' . ($sort->desc ? ChartSort::DESC : ChartSort::ASC);
        }
        $display = static::displayOptions($input['cd'] ?? null);
        if (!empty($display)) {
            $state['cd'] = implode(',', $display);
        }
        return empty($state) ? null : $state;
    }

    /**
     * The result's points in the runtime sort order (`cs` on the request); untouched without one.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function sorted(array $result, bool $multi): array
    {
        if ($this->chart_sort === null) {
            return $result;
        }
        return $multi ? ChartSorter::applyMulti($result, $this->chart_sort) : ChartSorter::applySingle($result, $this->chart_sort);
    }

    /**
     * The fields a viewer can sort this chart by, in chart order: the X columns (`x{i}` — one
     * per group column of the aggregate view, the X item of a list view; a multi-series chart
     * has only its X column, the series column splits the legend) and the measure (`y`).
     * Metadata only — no query.
     *
     * @return array<int, array{key: string, label: string}>
     */
    protected function sortFields(): array
    {
        $fields = [];
        if ($this->isAggregateView()) {
            $columns = collect($this->custom_view->custom_view_columns)->values();
            if (ChartType::isMulti($this->chart_type)) {
                $columns = $columns->count() >= 2 ? collect([$columns[$this->multiSeriesXPos($columns)]]) : collect();
            }
            foreach ($columns as $i => $column) {
                $fields[] = ['key' => 'x' . $i, 'label' => array_get($column, 'view_column_name') ?? $column->column_item->label()];
            }
        } else {
            $view_column_x = CustomViewSummary::getSummaryViewColumn($this->axis_x);
            if ($view_column_x == Define::CHARTITEM_LABEL) {
                $fields[] = ['key' => 'x0', 'label' => $this->custom_table->table_view_name];
            } elseif (!is_nullorempty($view_column_x)) {
                $fields[] = ['key' => 'x0', 'label' => array_get($view_column_x, 'view_column_name') ?? $view_column_x->column_item->label()];
            }
        }
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        if (!is_nullorempty($view_column_y) && $view_column_y != Define::CHARTITEM_LABEL) {
            $column = $view_column_y->custom_column;
            $fields[] = ['key' => 'y', 'label' => array_get($view_column_y, 'view_column_name') ?? ($column ? $column->column_view_name : $view_column_y->column_item->label())];
        }
        return $fields;
    }

    /**
     * Position of the X column of a multi-series chart among the view's group columns: the
     * first column that is not the series column (chart_series, default = the 2nd column).
     *
     * @param \Illuminate\Support\Collection $view_columns  the group columns, re-indexed
     */
    protected function multiSeriesXPos($view_columns): int
    {
        $series_pos = 1;
        foreach ($view_columns as $pos => $column) {
            if (!is_nullorempty($this->chart_series) && (ViewKindType::DEFAULT . '_' . $column->id) === $this->chart_series) {
                $series_pos = $pos;
            }
        }
        return $series_pos === 0 ? 1 : 0;
    }

    /**
     * The chart filter's popover fields ([] when none is configured). Building them prunes
     * ticked values the current scope no longer offers, so this runs BEFORE the data query.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function chartFilterFields(): array
    {
        if (!$this->chart_filter->isConfigured()) {
            return [];
        }
        // option lists are scoped like the chart itself: the view's own filters first
        $viewScope = function ($query) {
            $this->custom_view->filterModel($query);
        };
        return $this->chart_filter->fields($this->dashboard_filter, $viewScope, $this->dashboardExcept());
    }

    /**
     * The toolbar above the chart: [フィルター ▾] [chart type ▾] [⋯]; '' when no control
     * applies.
     *
     * @param array<int, array<string, mixed>> $fields  chartFilterFields()
     */
    protected function toolbarHtml(array $fields): string
    {
        $types = [];
        foreach (ChartType::switchPool($this->configured_type) as $type) {
            $types[$type] = exmtrans('chart.chart_type_options.' . $type);
        }
        // the box menu: the chart's sort fields (the applied one checked, if still a field of the
        // chart), the display options the type offers, the export
        $sort_fields = $this->sortFields();
        $sort_field = null;
        foreach ($sort_fields as $field) {
            if ($this->chart_sort && $field['key'] === $this->chart_sort->field) {
                $sort_field = $field['key'];
            }
        }
        $labels_available = ChartType::supportsDataLabels($this->chart_type);
        $display_labels = $labels_available && in_array('labels', $this->chart_display, true);
        // painted colors can be taken back to the palette by whoever may paint them
        $colors_reset = $this->canEditColors() && !$this->chartColors()->isEmpty();
        $menu = !empty($sort_fields) || $labels_available || $colors_reset;
        if (empty($types) && empty($fields) && !$menu) {
            return '';
        }
        return view('exment::dashboard.chart.toolbar', [
            'types' => $types,
            'current_type' => $this->chart_type,
            'configured_type' => $this->configured_type,
            'menu' => $menu,
            'colors_reset' => $colors_reset,
            'sort_fields' => $sort_fields,
            'sort_field' => $sort_field,
            'sort_desc' => $this->chart_sort ? $this->chart_sort->desc : false,
            'labels_available' => $labels_available,
            'display_labels' => $display_labels,
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
            'chart_fields'  => [$chart_label->values()->all()],
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

        // get data (a box aggregate, when set, stands in for the view's summary condition)
        $datalist = $this->summaryRows($view_column_y);
        $chart_label = $datalist->map(function ($val) use ($item_x_list) {
            $labels = $item_x_list->map(function ($item_x) use ($val) {
                $item = $item_x->setCustomValue($val);
                return $item->text();
            });
            return $labels->implode(' ');
        });
        // the same texts per group column: what a sort by one X column (ChartSort x{i}) orders on
        $chart_fields = $item_x_list->map(function ($item_x) use ($datalist) {
            return $datalist->map(function ($val) use ($item_x) {
                return $item_x->setCustomValue($val)->text();
            })->values()->all();
        })->values()->all();
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
            'chart_fields'  => $chart_fields,
            'chart_counts'  => $this->aggregate === ChartAggregate::AVG ? $datalist->pluck($item_y->uniqueName() . SummaryAverage::COUNT_SUFFIX)->values()->all() : null,
            'axisx_label'   => $axisx_label,
            'axisy_label'   => $this->axisYLabel($view_column_y),
            'chart_click'   => $chart_click,
        ];
    }

    /**
     * Rows of the aggregate view for this box, filters applied. Without a box aggregate this
     * is the plain view query. With one, the measure's summary condition is swapped for the
     * engine run (sum / count / min / max) or, for avg — which the engine lacks — derived from
     * a SUM run and a COUNT run matched on their group values (SummaryAverage): a true mean
     * of the records, also across a joined child table. Averaged rows also carry each
     * group's sum and count.
     *
     * @param CustomViewSummary $view_column_y  the measure (chart_axisy)
     * @return \Illuminate\Support\Collection
     */
    protected function summaryRows($view_column_y)
    {
        $run = function () {
            $query = $this->custom_table->getValueQuery();
            $this->applyFilters($query);
            return $this->custom_view->getQuery($query)->get();
        };
        if ($this->aggregate === null) {
            return $run();
        }
        // every run of ours starts from a clean search service: another box sharing this view
        // leaves its joins and order-by registered on it
        $fresh = function () use ($run) {
            $this->custom_view->resetSearchService();
            return $run();
        };
        if ($this->aggregate !== ChartAggregate::AVG) {
            return $this->withSummaryCondition($view_column_y, ChartAggregate::summaryCondition($this->aggregate), $fresh);
        }

        $sums = $this->withSummaryCondition($view_column_y, SummaryCondition::SUM, $fresh);
        $counts = $this->withSummaryCondition($view_column_y, SummaryCondition::COUNT, $fresh);

        $group_columns = collect($this->custom_view->custom_view_columns_cache);
        $alias = $view_column_y->column_item->uniqueName();
        $rows = SummaryAverage::merge($sums, $counts, $group_columns->map(function ($column) {
            return $column->column_item->uniqueName();
        })->all(), $alias);

        // the engine ordered the SUM rows; when the measure leads that order, re-sort by the mean
        $group_sort_orders = $group_columns->map(function ($column) {
            return array_get($column->options, 'sort_order');
        })->all();
        if (SummaryAverage::valueLeadsOrder(array_get($view_column_y->options, 'sort_order'), $group_sort_orders)) {
            $rows = SummaryAverage::sortByValue($rows, $alias, isMatchString(array_get($view_column_y->options, 'sort_type'), '-1'));
        }
        return $rows;
    }

    /**
     * Run $fn with the measure's summary condition replaced in memory — the engine reads it
     * off the model while building the query — and put the stored value back afterwards.
     *
     * @param CustomViewSummary $view_column_y
     * @param string $condition  a SummaryCondition value
     * @return mixed  what $fn returned
     */
    protected function withSummaryCondition($view_column_y, $condition, \Closure $fn)
    {
        $item = $view_column_y->column_item;
        $original = $view_column_y->view_summary_condition;
        // the engine PREPENDS the condition's name to the item's label on every run, and both the
        // model and its item are shared for the whole request — keep the label we found
        $original_label = $item->label();
        $view_column_y->view_summary_condition = $condition;
        try {
            return $fn();
        } finally {
            $view_column_y->view_summary_condition = $original;
            $item->options(['summary_condition' => $original]);
            $item->setLabel($original_label);
        }
    }

    /**
     * The chart's Y-axis label: the view column's own name, else the item's label — which the
     * engine has decorated with the view's summary condition. With a box aggregate and no name
     * of its own, "<aggregate> ： <column>" in the engine's own wording instead.
     *
     * @param CustomViewSummary $view_column_y
     */
    protected function axisYLabel($view_column_y): ?string
    {
        $named = array_get($view_column_y, 'view_column_name');
        if (!is_nullorempty($named)) {
            return $named;
        }
        $item = $view_column_y->column_item;
        if ($this->aggregate === null) {
            return $item->label();
        }
        $column = $view_column_y->custom_column;
        return exmtrans('common.format_keyvalue', ChartAggregate::label($this->aggregate), $column ? $column->column_view_name : $item->label());
    }

    /**
     * Click-to-filter payload of a chart whose X column is an item of the dashboard filter
     * bar (clickColumn): {column, values[], selected[], highlight} with values[i] = the
     * stored value behind data point i (what a df_{column} param compares against), so
     * clicking a point picks it on the bar. `highlight` says the chart highlights the item
     * rather than filtering by it (highlightColumn); `selected` is then the bar's current
     * pick on it, drawn solid on the chart. null when no click applies.
     *
     * @param mixed $view_column  the view's group CustomViewColumn
     * @param iterable $raw_values  raw group values, index-aligned with the chart's points
     * @return array|null
     */
    protected function clickFilter($view_column, $raw_values)
    {
        $custom_column = $this->clickColumn($view_column);
        if ($custom_column === null) {
            return null;
        }
        $column = $custom_column->column_name;
        $highlight = $this->highlightColumn() === $column;
        return [
            'column' => $column,
            'values' => collect($raw_values)->map(function ($v) {
                return is_scalar($v) ? (string) $v : '';
            })->values()->all(),
            'selected' => $highlight ? $this->dashboard_filter->selected($column) : [],
            'highlight' => $highlight,
        ];
    }

    /**
     * The custom column a click on a point of $view_column picks on the filter bar, or null:
     * the rendered type must have pickable points (ChartType::supportsPointPick), the item
     * must show a list for its current value (DashboardFilter::styleOf — a from / to has no
     * one value to pick, and a chart then filters by it like every other box instead of
     * highlighting it), the column must belong to this box's table and not be a derived
     * bucket (date format) whose displayed value never equals the stored one.
     *
     * @param mixed $view_column  a group CustomViewColumn of the view
     * @return mixed CustomColumn|null
     */
    protected function clickColumn($view_column)
    {
        $config = $this->dashboard_filter->config();
        $custom_column = $view_column ? $view_column->custom_column : null;
        if ($config === null || is_nullorempty($custom_column) || is_nullorempty($this->custom_table) || !ChartType::supportsPointPick($this->chart_type)) {
            return null;
        }
        if (array_get($view_column, 'view_column_table_id') != $this->custom_table->id
            || !is_nullorempty(array_get($view_column, 'view_group_condition'))
            || $config->dim($custom_column->column_name) === null
            || $this->dashboard_filter->styleOf($custom_column) !== 'select') {
            return null;
        }
        return $custom_column;
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

        $x_pos = $this->multiSeriesXPos($view_columns);
        $series_pos = $x_pos === 0 ? 1 : 0;

        $items = $view_columns->map(function ($item) {
            return $item->column_item->options([
                'summary' => true,
                'summary_index' => ViewKindType::DEFAULT . '_' . $item->id,
            ]);
        });
        $item_x = $items[$x_pos];
        $item_series = $items[$series_pos];
        $item_y = $view_column_y->column_item;

        $datalist = $this->summaryRows($view_column_y);

        $x_texts = $datalist->map(function ($val) use ($item_x) {
            return $item_x->setCustomValue($val)->text();
        })->all();
        $series_texts = $datalist->map(function ($val) use ($item_series) {
            return $item_series->setCustomValue($val)->text();
        })->all();
        // averaged rows: the pivot combines their sums and record counts, then divides per cell
        $averaged = $this->aggregate === ChartAggregate::AVG;
        $alias = $item_y->uniqueName();
        $y_values = $datalist->pluck($averaged ? $alias . SummaryAverage::SUM_SUFFIX : $alias)->all();
        $y_counts = $averaged ? $datalist->pluck($alias . SummaryAverage::COUNT_SUFFIX)->all() : [];

        // strict unique so "7" and "007" stay distinct categories
        $x_categories = collect($x_texts)->unique(null, true)->values();
        $series_names = collect($series_texts)->unique(null, true)->values();

        $x_raws = $datalist->pluck($item_x->uniqueName())->all();
        $x_raw_by_category = [];
        $matrix = array_fill(0, $series_names->count(), array_fill(0, $x_categories->count(), 0));
        $counts = $matrix;
        foreach ($y_values as $i => $value) {
            $x_idx = $x_categories->search($x_texts[$i], true);
            $s_idx = $series_names->search($series_texts[$i], true);
            if ($x_idx !== false && $s_idx !== false) {
                // accumulate: a view grouped by 3+ columns yields several rows per cell
                $matrix[$s_idx][$x_idx] += is_numeric($value) ? floatval($value) : 0;
                $counts[$s_idx][$x_idx] += (int) ($y_counts[$i] ?? 0);
                $x_raw_by_category[$x_idx] = $x_raw_by_category[$x_idx] ?? ($x_raws[$i] ?? null);
            }
        }
        if ($averaged) {
            $matrix = SummaryAverage::cellMeans($matrix, $counts);
        }

        return [
            'x_categories' => $x_categories->all(),
            'series_names' => $series_names->all(),
            'matrix'       => $matrix,
            'axisx_label'  => array_get($view_columns[$x_pos], 'view_column_name') ?? $item_x->label(),
            'axisy_label'  => $this->axisYLabel($view_column_y),
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

        // TEMPORARILY DISABLED (2026-09-23): 集計方法 — aggregate of the Y value on this box (aggregate
        // views); empty = the view's own summary condition. See the constructor.
        // $form->select('chart_aggregate', exmtrans("dashboard.dashboard_box_options.chart_aggregate"))
        //     ->options(ChartAggregate::formOptions())
        //     ->help(exmtrans("dashboard.dashboard_box_options.chart_aggregate_help"));

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

        // TEMPORARILY DISABLED (2026-09-23): 集計方法 — the field is off the form, so nothing is posted.
        // aggregate: a known value only; empty (= as the view) stores nothing
        // if (ChartAggregate::resolve(array_get($options, 'chart_aggregate')) === null) {
        //     unset($options['chart_aggregate']);
        // }

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
     * The Chart.js dataset color: one color for a line, else the per-point colors as the
     * runtime sort left them.
     *
     * @param array<int, string> $point_colors  pointColors(), carried through the sort
     * @return array|string Chart color array
     */
    // @phpstan-ignore-next-line
    protected function getChartColor(array $point_colors)
    {
        // one line = one color; every other single-series type colors each point
        if ($this->chart_type == ChartType::LINE) {
            return $this->singleSeriesPalette()[0];
        }
        return $point_colors;
    }

    /**
     * One color per point: the one an editor painted on the category, else Exment's own
     * default — a palette color per slice on the circular types, the palette's first color on
     * every other point. Assigned in the view's own row order — body() hands the list to the
     * sorter, which keeps each color on its own point.
     *
     * @param array<int, mixed> $labels  the category texts, in the view's order
     * @return array<int, string>
     */
    protected function pointColors(array $labels): array
    {
        $palette = $this->getChartPalette();
        if (!in_array($this->chart_type, [ChartType::PIE, ChartType::DOUGHNUT, ChartType::FUNNEL], true)) {
            $palette = [$palette[0]];
        }
        return $this->chartColors()->colorsFor(ChartColors::POINT, $labels, $palette);
    }

    /**
     * The palette with its first color — the one a one-color type (line / area / radar /
     * gauge) draws its series in — replaced by the color an editor painted on that series.
     *
     * @return string[]
     */
    protected function singleSeriesPalette(): array
    {
        $palette = $this->getChartPalette();
        $palette[0] = $this->chartColors()->get(ChartColors::SERIES, ChartColors::SINGLE) ?? $palette[0];
        return $palette;
    }

    /**
     * The colors painted on this box (box option chart_colors).
     */
    protected function chartColors(): ChartColors
    {
        return ChartColors::fromOption(array_get($this->dashboard_box, 'options.chart_colors'));
    }

    /**
     * Whether the current user may paint this chart's colors: they edit the dashboard, and
     * the rendered type has something to paint.
     */
    protected function canEditColors(): bool
    {
        if ($this->can_edit_colors === null) {
            $dashboard = $this->dashboard_box->dashboard ?? null;
            $this->can_edit_colors = ChartType::supportsColorEdit($this->chart_type) && $dashboard !== null && (bool) $dashboard->hasEditPermission();
        }
        return $this->can_edit_colors;
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
