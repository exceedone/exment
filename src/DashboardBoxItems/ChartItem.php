<?php

namespace Exceedone\Exment\DashboardBoxItems;

use ExmentAdminCore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Dashboard\ChartColors;
use Exceedone\Exment\Services\Dashboard\ChartDetail;
use Exceedone\Exment\Services\Dashboard\ChartFilter;
use Exceedone\Exment\Services\Dashboard\ChartPivot;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartSort;
use Exceedone\Exment\Services\Dashboard\ChartSorter;
use Exceedone\Exment\Services\Dashboard\ChartSummarySwitch;
use Exceedone\Exment\Services\Dashboard\ChartThresholds;
use Exceedone\Exment\Services\Dashboard\ChartTopN;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Validator\ChartThresholdRule;

/**
 * Chart box. On top of the configured chart, the box renders a toolbar (runtime chart-type
 * switcher, summary switch, the box's own chart filter). Every data path applies the dashboard filter bar
 * (df_*) and the chart filter (bf_*) of the request, so chart and popover options see the
 * same rows.
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

    /** px per row of a heatmap: each cell prints its number, which needs the room */
    protected const HEATMAP_ROW_HEIGHT = 26;

    // @phpstan-ignore-next-line
    protected $dashboard_box;

    // @phpstan-ignore-next-line
    protected $custom_table;

    /** @var CustomView|null */
    protected $custom_view;

    // @phpstan-ignore-next-line
    protected $axis_x;

    // @phpstan-ignore-next-line
    protected $axis_y;

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

    /** @var ChartSummarySwitch|null summarySwitch() memo */
    protected $summary_switch;

    /** @var ChartShowAs|null showAs() memo */
    protected $show_as;

    /** @var ChartTopN|null topN() memo */
    protected $top_n;

    /** @var int|null the number of points of the chart before 表示件数 cuts them (ranked()) */
    protected $point_count;

    /** @var array<string, mixed> the box's request params (ct, cs, cd, ca, sv, tn, df_*, bf_*) */
    protected $params;

    /** @var ChartDetail|null|false detail() memo (false = not resolved yet) */
    protected $detail = false;

    /**
     * @param mixed $dashboard_box
     * @param array<string, mixed>|null $params  the box's request params; null = the current
     *   request's. A chart's 明細 list (ChartDetail) rebuilds the box from the params of the
     *   box request the chart was drawn by, on a request of its own.
     */
    public function __construct($dashboard_box, ?array $params = null)
    {
        $this->dashboard_box = $dashboard_box;
        $this->params = $params ?? request()->all();

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
        $this->chart_type = ChartType::resolve($this->configured_type, $this->params['ct'] ?? null);
        if ($this->chart_type !== $this->configured_type && ChartType::isFullStacked($this->chart_type) && !$this->measureAddsUp()) {
            // no switch to a 100% stacked type of a measure that does not add up (a choice remembered
            // before the view changed): it would draw a message only, with no picker to switch back
            $this->chart_type = $this->configured_type;
        }
        $this->chart_sort = ChartSort::parse($this->params['cs'] ?? null);
        $this->chart_display = static::displayOptions($this->params['cd'] ?? null);
        if ($this->chart_type !== $this->configured_type) {
            // the saved flags belong to the configured type — give the switched type its own
            // defaults: its option flag, and, on a switch to a Chart.js axis type from a type
            // that stores no axis options (saving keeps them on bar / line only), both axes'
            // labels and names, the way the ECharts types always draw them
            $this->chart_options = static::defaultChartOptions($this->chart_type);
            $axisTypes = ChartType::axisOptionTypes();
            if (in_array($this->chart_type, $axisTypes, true) && !in_array($this->configured_type, $axisTypes, true)) {
                $this->chart_axis_label = [ChartAxisType::X, ChartAxisType::Y];
                $this->chart_axis_name = [ChartAxisType::X, ChartAxisType::Y];
            }
        }

        $this->dashboard_filter = DashboardFilter::fromRequest($this->dashboard_box->dashboard ?? null, $this->params);
        $this->chart_filter = ChartFilter::of($this->dashboard_box, $this->custom_table, $this->params);
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
        $result = $this->drawnData();
        if (!is_array($result)) {
            return $result;
        }

        $common = [
            'suuid' => $this->dashboard_box->suuid,
            'chart_type' => $this->chart_type,
            'chart_height' => 300,
            'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
            'chart_labels_on' => in_array('labels', $this->chart_display, true),
            // right-click a point / series to paint it (dashboard.js colorMenu)
            'chart_color_edit' => $this->canEditColors(),
            // the values shown as percents (表示方法, a 100% stacked type): drawn with "%"
            'chart_percent' => $this->showAs()->isPercent() || ChartType::isFullStacked($this->chart_type),
            // the その他 point of 表示件数 (ChartTopN): its index and the points in it, for its tooltip
            'chart_rest' => json_encode($this->restTip($result), static::JSON_FLAGS),
        ] + $this->valueNames($result);

        if (ChartType::isMulti($this->chart_type)) {
            $full = ChartType::isFullStacked($this->chart_type);
            $values = $result['numbers'];
            $thresholds = $this->thresholds();
            if ($this->chart_type === ChartType::HEATMAP) {
                // a heatmap is read by its numbers, as the cells of a sheet: a row tall enough for
                // its label (the axes and a legend under the plot besides); a few rows keep 300px
                $common['chart_height'] = max($common['chart_height'], static::HEATMAP_ROW_HEIGHT * count($result['series_names']) + 110);
            }
            $chart = view('exment::dashboard.chart.echart_multi', $common + [
                // a 100% stacked chart's own numbers, for its tooltip and the CSV, and their texts
                'chart_values' => $full ? json_encode(array_map(function ($row) {
                    return array_values((array) $row);
                }, array_values($values)), static::JSON_FLAGS) : 'null',
                'chart_full_texts' => $full ? $this->fullStackTexts() : null,
                // a stack of calculated values is labelled with its total
                'chart_stack_total' => $this->showAs()->active() && $this->chart_type === ChartType::SBAR,
                'x_categories' => json_encode($result['x_categories'], static::JSON_FLAGS),
                'series_names' => json_encode($result['series_names'], static::JSON_FLAGS),
                'matrix' => json_encode($result['matrix'], static::JSON_FLAGS),
                'chart_axisx' => $result['axisx_label'],
                'chart_colors' => json_encode($this->seriesColors($result['series_names'])),
                'chart_click' => json_encode($result['chart_click'], static::JSON_FLAGS),
                'chart_detail' => json_encode($this->detailPayload($result), static::JSON_FLAGS),
                // the heatmap colored by the box's thresholds: each cell's class and the legend
                // (of the measure's own numbers; another type hands it to a heatmap's preview)
                'chart_thresholds' => $thresholds === null ? 'null' : json_encode($thresholds->payload($values), static::JSON_FLAGS),
            ]);
        } else {
            $vars = $common + [
                'chart_data' => json_encode($result['chart_data'], static::JSON_FLAGS),
                'chart_labels' => json_encode($result['chart_label'], static::JSON_FLAGS),
                'chart_axisx' => $result['axisx_label'],
                'chart_click' => json_encode($result['chart_click'] ?? null, static::JSON_FLAGS),
                'chart_detail' => json_encode($this->detailPayload($result), static::JSON_FLAGS),
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

        return $this->toolbarHtml($fields, $result['axisx_label'], $result['axisy_label']) . $chart->render() . $this->topNNote();
    }

    /**
     * The その他 point's tooltip data: {index, count, items [[label, value]], more}; null when
     * the chart has none.
     *
     * @param array<string, mixed> $result  drawnData()
     * @return array<string, mixed>|null
     */
    protected function restTip(array $result): ?array
    {
        $rest = $result['top_n'] ?? null;
        if (!is_array($rest)) {
            return null;
        }
        unset($rest['excluded']);
        return $rest;
    }

    /**
     * The line under a chart cut to its top N without その他, saying what is left out
     * (※ 上位5件のみ表示（ほか 13 件は非表示）); '' otherwise.
     */
    protected function topNNote(): string
    {
        $top_n = $this->topN();
        if (!$top_n->active() || $top_n->withOther() || $top_n->hidden() === 0) {
            return '';
        }
        return '<div class="ct-topn-note">' . esc_html(exmtrans('dashboard.chart_top_n.note', $top_n->n(), $top_n->hidden())) . '</div>';
    }

    /**
     * The numbers the chart draws — the box's filters, the 集計方法, the sort and the 表示方法
     * applied — for body() and the Excel download alike; the message the box shows instead
     * when the setting cannot be drawn. Run after chartFilterFields().
     *
     * A multi-series chart: {x_categories, series_names, matrix (as drawn), numbers (the cells
     * before the 表示方法 / the 100% stack made them shares), ...}; a single-series one:
     * {chart_label, chart_data (as drawn), point_colors, ...} (getMultiSeriesData /
     * getAggregateData / getListData).
     *
     * @return array<string, mixed>|string
     */
    protected function drawnData()
    {
        if (ChartType::isMulti($this->chart_type)) {
            if ($this->isAverageOfExtraGroups()) {
                return exmtrans('dashboard.message.need_average_groups');
            }
            if ($this->isFullStackOfNonAdditive()) {
                return exmtrans('dashboard.message.need_additive');
            }
            $result = $this->summarySwitch()->apply(function () {
                return $this->getMultiSeriesData();
            });
            if ($result === false) {
                return exmtrans('dashboard.message.need_multiseries');
            }
            // the 表示方法 runs on the cells in the order they are drawn, as Excel's on the pivot;
            // a 100% stacked type draws each cell as its share of its X item the same way
            $result = $this->ranked($result, true);
            $result['numbers'] = $result['matrix'];
            $full = ChartType::isFullStacked($this->chart_type);
            $result['matrix'] = ($full ? ChartShowAs::fullStacked() : $this->showAs())->applyMatrix($result['numbers']);
            if ($this->restHasNoDifference($result)) {
                foreach (array_keys($result['matrix']) as $s) {
                    $result['matrix'][$s][$result['top_n']['index']] = null;
                }
            }
            return $result;
        }

        $result = $this->isAggregateView()
            ? $this->summarySwitch()->apply(function () {
                return $this->getAggregateData();
            })
            : $this->getListData();
        if ($result === false) {
            return exmtrans('dashboard.message.need_setting');
        }
        // a point's color is decided in the VIEW's own order and then travels with the
        // point through the runtime sort, so re-sorting moves the colors instead of
        // repainting the categories (a viewer recognises a bar by its color)
        $result['point_colors'] = $this->pointColors(collect($result['chart_label'])->values()->all());
        $result = $this->ranked($result, false);
        $result['chart_data'] = $this->showAs()->applyValues($result['chart_data']);
        if ($this->restHasNoDifference($result)) {
            $result['chart_data'][$result['top_n']['index']] = null;
        }
        return $result;
    }

    /**
     * The result's points cut to the top N of 表示件数 (ChartTopN), in the runtime sort; just
     * sorted when the chart is not cut.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function ranked(array $result, bool $multi): array
    {
        $labels = ChartSorter::listOf($multi ? ($result['x_categories'] ?? null) : ($result['chart_label'] ?? null));
        $this->point_count = count($labels);
        $top_n = $this->top_n = $this->makeTopN($labels);
        if (!$top_n->active()) {
            return $this->sorted($result, $multi);
        }
        if ($multi) {
            $x = $this->xViewColumn();
            return $top_n->applyMulti($result, $this->chart_sort, $this->restHasDetail(), $x ? $x->id : null);
        }
        return $top_n->applySingle($result, $this->chart_sort, $this->restHasDetail());
    }

    /**
     * Whether the その他 point shows no 前の項目との差分 (表示方法): it is no item that follows
     * the one before it — the points it groups are anywhere in the order.
     *
     * @param array<string, mixed> $result
     */
    protected function restHasNoDifference(array $result): bool
    {
        return isset($result['top_n']['index']) && $this->showAs()->isDifference();
    }

    /**
     * Whether a double click on その他 lists its rows: the chart has a 明細 and the rows of the
     * points left out can be told apart (ChartDetail::supportsRest).
     */
    protected function restHasDetail(): bool
    {
        $detail = $this->detail();
        return $detail !== null && $detail->supportsRest(collect($this->xViewColumns())->pluck('id')->all());
    }

    /**
     * The 明細 of the その他 point (ChartDetailRequest): the keys of the points kept — a row of
     * その他 is a row of none of them — and the caption naming what is listed; null when the
     * chart draws no その他 with a 明細. Run after settleFilters().
     *
     * @return array{excluded: array<int, array<int|string, mixed>>, caption: array{label: string, value: string}}|null
     */
    public function restDetail(): ?array
    {
        $result = $this->drawnData();
        $excluded = is_array($result) ? ($result['top_n']['excluded'] ?? null) : null;
        if (!is_array($excluded)) {
            return null;
        }
        return [
            'excluded' => $excluded,
            'caption' => [
                'label' => (string) $result['axisx_label'],
                'value' => (string) exmtrans('dashboard.chart_top_n.detail', $this->topN()->n(), $result['top_n']['count']),
            ],
        ];
    }

    /**
     * What the box's Excel download writes (ChartExcel): the chart as body() draws it for the same
     * request — filters, sort, 集計方法, 表示方法, type, colors — or the message the box shows
     * instead (no table, no permission, a setting it cannot draw); null without a view.
     *
     * `matrix` holds a row per series, a cell per category, as drawn: the 表示方法's values
     * (percents when `percent`, 32.4 = 32.4%); a 100% stacked type's own numbers, whose shares
     * the Excel chart draws itself. `adds_up`: the values may be summed (a SUM / COUNT measure
     * shown as it is). `point_colors`: per category, for the types that paint each point.
     *
     * @return array<string, mixed>|string|null
     */
    public function excelData()
    {
        if (($permission = $this->hasPermission()) !== true) {
            return is_string($permission) ? $permission : null;
        }
        if (is_null($this->custom_view)) {
            return null;
        }
        $this->chartFilterFields();
        $result = $this->drawnData();
        if (!is_array($result)) {
            return $result;
        }

        $type = $this->chart_type;
        $axisy = (string) $this->valueNames($result)['chart_axisy'];
        if (ChartType::isMulti($type)) {
            $categories = $result['x_categories'];
            $series = $result['series_names'];
            $matrix = ChartType::isFullStacked($type) ? $result['numbers'] : $result['matrix'];
            $colors = $this->seriesColors($series);
            $point_colors = null;
        } else {
            $labels = $result['chart_label'];
            $categories = $labels instanceof \Illuminate\Support\Collection ? $labels->values()->all() : array_values((array) $labels);
            $series = [$axisy];
            $matrix = [$result['chart_data']];
            // the one-color types draw the series in the first color, the others paint each point
            $one_color = in_array($type, [ChartType::LINE, ChartType::AREA, ChartType::RADAR, ChartType::GAUGE], true);
            $colors = [$this->singleSeriesPalette()[0]];
            $point_colors = $one_color ? null : $result['point_colors'];
        }

        return [
            'type' => $type,
            'title' => $this->boxTitle($result['axisx_label'], $result['axisy_label']) ?? (string) array_get($this->dashboard_box, 'dashboard_box_view_name'),
            'dashboard' => (string) data_get($this->dashboard_box, 'dashboard.dashboard_view_name'),
            'view' => (string) $this->custom_view->view_view_name,
            'captions' => $this->filterCaptions(),
            'axisx' => (string) $result['axisx_label'],
            'series_label' => (string) ($result['series_label'] ?? ''),
            'axisy' => $axisy,
            'categories' => array_values($categories),
            'series' => array_values($series),
            'matrix' => array_map(function ($row) {
                return array_values((array) $row);
            }, array_values($matrix)),
            'percent' => $this->showAs()->isPercent(),
            'adds_up' => !$this->showAs()->active() && ChartSummarySwitch::isAdditive($this->summarySwitch()->condition()),
            'colors' => $colors,
            'point_colors' => $point_colors,
            'labels' => ChartType::supportsDataLabels($type) && in_array('labels', $this->chart_display, true),
            'legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
            'thresholds' => $type === ChartType::HEATMAP ? $this->thresholds() : null,
        ];
    }

    /**
     * The names of the drawn values, from the data's Y label: the Y axis — and the CSV — name
     * the way they are shown (合計 ： 売上金額（累計）), and so do the tooltips, which name the
     * values only when they are not the view's own (switched by 集計方法 or 表示方法).
     *
     * @param array<string, mixed> $result  the chart data, its axisy_label the measure's label
     * @return array{chart_axisy: mixed, chart_value_name: string|null}
     */
    protected function valueNames(array $result): array
    {
        $axisy = $this->showAs()->decorate($result['axisy_label']);
        return [
            'chart_axisy' => $axisy,
            'chart_value_name' => $this->showAs()->active() ? (string) $axisy : $this->summarySwitch()->valueLabel(),
        ];
    }

    protected function isAggregateView(): bool
    {
        return array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE;
    }

    /**
     * The 明細 of this chart (Excel's 詳細の表示): the rows behind a point, which a double click
     * on it lists. null when the chart has none — a list view, a type whose point is no one
     * group of the view (ChartType::supportsDetail), a measure whose rows cannot be listed
     * (ChartDetail::resolve).
     */
    public function detail(): ?ChartDetail
    {
        if ($this->detail !== false) {
            return $this->detail;
        }
        $this->detail = null;
        if (is_null($this->custom_table) || !$this->isAggregateView() || !is_string($this->axis_y) || !ChartType::supportsDetail($this->chart_type)) {
            return null;
        }
        $measure = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        if (!$measure instanceof CustomViewSummary || $measure->custom_view_id != $this->custom_view->id) {
            return null;
        }
        $this->detail = ChartDetail::resolve($this->custom_table, $this->custom_view, $measure, function () {
            return $this->filteredRows();
        });
        return $this->detail;
    }

    /**
     * Settle the chart filter the way drawing the chart does (body(): ticked values the scope
     * no longer offers are dropped), for a request that reads the box's rows without drawing.
     */
    public function settleFilters(): void
    {
        $this->chartFilterFields();
    }

    /**
     * The box's filters narrowing what the chart draws, human-readable: the filter bar's
     * items it honours (not its highlighted X item) and its own chart filter.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function filterCaptions(): array
    {
        return array_merge(
            $this->dashboard_filter->captions($this->custom_table, $this->dashboard_box, $this->dashboardExcept()),
            $this->chart_filter->captions()
        );
    }

    /**
     * What the chart hands its double click: the list to open and the keys of each point
     * (ChartDetail::keys) — per point for a single-series chart (`points`), per X category
     * and per series for a multi-series one (`x` / `series`: the view column ids, `x_keys` /
     * `series_keys`: their values, index-aligned with the categories and the series). null
     * when the chart has no 明細.
     *
     * @param array<string, mixed> $result  the chart data, sorted
     * @return array<string, mixed>|null
     */
    protected function detailPayload(array $result): ?array
    {
        $detail = $this->detail();
        $keys = $result['detail'] ?? null;
        if ($detail === null || !is_array($keys)) {
            return null;
        }
        return ['url' => admin_url('data/' . $detail->table()->table_name)] + $keys;
    }

    /**
     * Group values per point of an aggregate view's rows: [{view column id: stored value}].
     *
     * @param \Illuminate\Support\Collection<array-key, mixed> $datalist  the view's rows
     * @param \Illuminate\Support\Collection<array-key, mixed> $view_columns  its group columns
     * @param \Illuminate\Support\Collection<array-key, mixed> $items  their summary items, index-aligned
     * @return array<int, array<int|string, mixed>>
     */
    protected function detailKeys($datalist, $view_columns, $items): array
    {
        $view_columns = $view_columns->values();
        $items = $items->values();
        return $datalist->values()->map(function ($val) use ($view_columns, $items) {
            $keys = [];
            foreach ($view_columns as $i => $column) {
                $keys[$column->id] = data_get($val, $items[$i]->uniqueName());
            }
            return $keys;
        })->all();
    }

    /**
     * The 集計 switch of the toolbar (`ca` on the request): the measure of an aggregate view
     * drawn by another summary condition. Offers no AVG where the rendered type could not
     * draw it (isAverageOfExtraGroups), unless the view sets it.
     */
    protected function summarySwitch(): ChartSummarySwitch
    {
        if ($this->summary_switch === null) {
            $aggregate = $this->isAggregateView() && is_string($this->axis_y);
            $this->summary_switch = new ChartSummarySwitch(
                $aggregate ? $this->custom_view : null,
                $aggregate ? CustomViewSummary::getSummaryViewColumn($this->axis_y) : null,
                $this->params[ChartSummarySwitch::PARAM] ?? null,
                !(ChartType::isMulti($this->chart_type) && $this->groupColumnCount() > 2),
                ChartType::isFullStacked($this->chart_type)
            );
        }
        return $this->summary_switch;
    }

    /**
     * The 表示方法 of the box menu (`sv` on the request): the drawn values shown as a share of
     * the total, a running total, a difference from the previous item. Offered on an aggregate
     * view's summary column only, by the rendered type and the condition it is drawn by
     * (ChartShowAs::choicesFor).
     */
    protected function showAs(): ChartShowAs
    {
        if ($this->show_as === null) {
            $view_column_y = $this->isAggregateView() && is_string($this->axis_y) ? CustomViewSummary::getSummaryViewColumn($this->axis_y) : null;
            $numeric = $view_column_y instanceof CustomViewSummary && ColumnType::isCalc(data_get($view_column_y->custom_column, 'column_type'));
            // condition(): null unless the measure is a summary column of the view
            $this->show_as = new ChartShowAs(
                ChartShowAs::choicesFor($this->chart_type, $this->summarySwitch()->condition(), $numeric),
                $this->params[ChartShowAs::PARAM] ?? null
            );
        }
        return $this->show_as;
    }

    /**
     * The 表示件数 of the box menu (`tn` on the request): the chart cut to its N largest points,
     * the rest grouped into その他 (ChartTopN). Offered on a type read across its categories,
     * along an X axis that is no date — a month folded into その他 would break the time line —
     * and grouped into その他 only for a summary that adds up (SUM / COUNT, as drawn).
     */
    protected function topN(): ChartTopN
    {
        return $this->top_n ?? ($this->top_n = $this->makeTopN([]));
    }

    /**
     * The 表示件数 of the chart drawing points labelled $labels: a time line by its labels too
     * (ChartTopN::isTimeline: a text column of months). ranked() makes it from the drawn points.
     *
     * @param array<int, mixed> $labels
     */
    protected function makeTopN(array $labels): ChartTopN
    {
        return new ChartTopN(
            ChartTopN::supportsType($this->chart_type) && !$this->xIsDate() && !ChartTopN::isTimeline($labels),
            $this->isAggregateView() && ChartSummarySwitch::isAdditive($this->summarySwitch()->condition()),
            $this->params[ChartTopN::PARAM] ?? null
        );
    }

    /**
     * The view columns a point of the rendered type is labelled by: the group columns of an
     * aggregate view (the X column only of a multi-series chart, its series split the
     * legend), the X item of a list view. [] for none (the table's label).
     *
     * @return array<int, mixed>  CustomViewColumn / CustomViewSummary
     */
    protected function xViewColumns(): array
    {
        if (is_null($this->custom_view)) {
            return [];
        }
        if ($this->isAggregateView()) {
            $columns = collect($this->custom_view->custom_view_columns)->values();
            if (ChartType::isMulti($this->chart_type)) {
                return $columns->count() >= 2 ? [$columns[$this->multiSeriesXPos($columns)]] : [];
            }
            return $columns->all();
        }
        $view_column_x = CustomViewSummary::getSummaryViewColumn($this->axis_x);
        return is_object($view_column_x) ? [$view_column_x] : [];
    }

    /**
     * Whether the points run along time: a column labelling them is a date (a date / datetime
     * column, the created / updated dates, grouped by year, month or day or not).
     */
    protected function xIsDate(): bool
    {
        foreach ($this->xViewColumns() as $column) {
            $item = data_get($column, 'column_item');
            if (is_object($item) && method_exists($item, 'isDate') && $item->isDate()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The 表示件数 submenu of the box menu (⋯); null when the chart cannot be cut, or has no
     * more points than the fewest it could be cut to (unless it is cut: the pick stays to be
     * taken back).
     *
     * @param mixed $axisx_label  the X axis label of the drawn data
     * @param mixed $axisy_label  the measure's label of the drawn data
     * @return array<string, mixed>|null
     */
    protected function topNMenu($axisx_label, $axisy_label): ?array
    {
        $top_n = $this->topN();
        if (!$top_n->available() || $this->point_count === null) {
            return null;
        }
        if (!$top_n->active() && $this->point_count <= min(ChartTopN::CHOICES)) {
            return null;
        }
        $choices = ['' => (string) exmtrans('dashboard.chart_top_n.all', $this->point_count)];
        foreach (ChartTopN::CHOICES as $n) {
            $choices[(string) $n] = (string) exmtrans('dashboard.chart_top_n.top', $n);
        }
        if ($top_n->groupable()) {
            $other_hint = null;
        } else {
            $other_hint = (string) exmtrans($this->isAggregateView() ? 'dashboard.chart_top_n.other_not_additive' : 'dashboard.chart_top_n.other_list_view');
        }
        return [
            'choices' => $choices,
            'current' => $top_n->active() ? (string) $top_n->n() : '',
            'label' => $top_n->active() ? $top_n->label() : $choices[''],
            'applied' => $top_n->applied(),
            'other' => $top_n->groupable() && $top_n->otherRequested(),
            'other_hint' => $other_hint,
            'column' => (string) $axisx_label,
            'hint' => (string) exmtrans('dashboard.chart_top_n.hint', (string) $axisy_label),
        ];
    }

    /**
     * The box's color thresholds (色分けのしきい値), in the units of the measure as the view
     * summarizes it: none when it has none, and none while the chart draws other numbers — the
     * measure summarized by another condition (集計方法: 60 / 75 points mean nothing to a count)
     * or shown another way (表示方法); the heatmap then keeps its continuous scale. Any type of
     * the box is handed them: the type picker previews a heatmap by them.
     */
    protected function thresholds(): ?ChartThresholds
    {
        $thresholds = ChartThresholds::fromOption(array_get($this->dashboard_box, 'options.' . ChartThresholds::OPTION));
        if ($thresholds->isEmpty() || $this->summarySwitch()->switched() || $this->showAs()->active()) {
            return null;
        }
        return $thresholds;
    }

    /**
     * The 表示方法 submenu of the box menu (⋯); null when the chart has no way to show its values
     * but as they are.
     *
     * @return array{choices: array<string, string>, current: string, column: string}|null
     */
    protected function showAsMenu(): ?array
    {
        $show_as = $this->showAs();
        if (!$show_as->available()) {
            return null;
        }
        $choices = [];
        foreach ($show_as->choices() as $choice) {
            $choices[$choice] = ChartShowAs::label($choice);
        }
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        return [
            'choices' => $choices,
            'current' => $show_as->current(),
            // the heading names the measure's column as the 集計方法 one does, else the measure
            'column' => $this->summarySwitch()->columnLabel() !== '' ? $this->summarySwitch()->columnLabel() : (string) $this->axisYLabel($view_column_y),
        ];
    }

    /**
     * The box title while the chart draws its measure other than the view does — summarized by
     * another condition (集計方法) or shown another way (表示方法): what it draws, 教科別 最小値 ：
     * 点数 / 受注月別 合計 ： 売上金額（累計）. null otherwise (the configured title stays).
     *
     * @param mixed $axisx_label  the X axis label of the drawn data
     * @param mixed $axisy_label  the measure's label, before the 表示方法 decorates it
     */
    protected function boxTitle($axisx_label, $axisy_label): ?string
    {
        $switch = $this->summarySwitch();
        if (!$switch->switched() && !$this->showAs()->active() && !$this->topN()->active()) {
            return null;
        }
        $title = exmtrans('dashboard.chart_summary.title', (string) $axisx_label, $switch->valueLabel() ?? (string) $axisy_label);
        return (string) $this->topN()->decorate($this->showAs()->decorate($title));
    }

    /**
     * The summary condition the measure is drawn by: the 集計 switch's pick, else the view's.
     *
     * @param mixed $view_column_y  the measure (CustomViewSummary / CustomViewColumn)
     * @return mixed
     */
    protected function summaryCondition($view_column_y)
    {
        return $this->summarySwitch()->condition() ?? array_get($view_column_y, 'view_summary_condition');
    }

    protected function groupColumnCount(): int
    {
        return collect($this->custom_view->custom_view_columns ?? [])->count();
    }

    /**
     * The 集計方法 submenu of the box menu (⋯); null when the measure has no other condition to take.
     *
     * @return array{choices: array<string, string>, configured: string|null, current: string|null, column: string}|null
     */
    protected function summaryMenu(): ?array
    {
        $switch = $this->summarySwitch();
        if (!$switch->available()) {
            return null;
        }
        $choices = [];
        foreach ($switch->choices() as $condition) {
            $choices[$condition] = ChartSummarySwitch::conditionLabel($condition);
        }
        return [
            'choices' => $choices,
            'configured' => $switch->configured(),
            'current' => $switch->condition(),
            'column' => $switch->columnLabel(),
        ];
    }

    /**
     * AND the dashboard filter (targeting-aware, minus the highlighted X item) and this
     * box's chart filter onto a query.
     *
     * @param mixed $query  query on the table's rows
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
        $wanted = array_map('trim', explode(',', $cd));
        $options = [];
        foreach (['labels'] as $option) { // the known ones, in their order
            if (in_array($option, $wanted, true)) {
                $options[] = $option;
            }
        }
        return $options;
    }

    /**
     * The toolbar choices of a box worth remembering, from the request values ct / cs / cd / tn:
     * a chart type that is a legal switch AND differs from the configured one, a well-formed
     * sort, the known display options, a well-formed 表示件数 (kept whether the chart can be cut
     * now or not, as a sort is). null when nothing is left (= the box setting).
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
        $top_n = ChartTopN::normalize($input[ChartTopN::PARAM] ?? null);
        if ($top_n !== null) {
            $state[ChartTopN::PARAM] = $top_n;
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
            $fields[] = ['key' => 'y', 'label' => $this->summarySwitch()->valueLabel()
                ?? array_get($view_column_y, 'view_column_name') ?? ($column ? $column->column_view_name : $view_column_y->column_item->label())];
        }
        return $fields;
    }

    /**
     * Position of the X column of a multi-series chart among the view's group columns: the
     * first column that is not the series column (chart_series, default = the 2nd column).
     *
     * @param \Illuminate\Support\Collection<int, \Exceedone\Exment\Model\CustomViewColumn> $view_columns  the group CustomViewColumns, re-indexed
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
            $this->viewForQuery()->filterModel($query);
        };
        return $this->chart_filter->fields($this->dashboard_filter, $viewScope, $this->dashboardExcept());
    }

    /**
     * The toolbar above the chart: [フィルター ▾] [chart type ▾] [⋯] (with 表示件数 ◀, 集計方法 ◀
     * and 表示方法 ◀ in ⋯); '' when no control applies.
     *
     * @param array<int, array<string, mixed>> $fields  chartFilterFields()
     * @param mixed $axisx_label  the X axis label of the drawn data
     * @param mixed $axisy_label  the measure's label of the drawn data
     */
    protected function toolbarHtml(array $fields, $axisx_label, $axisy_label): string
    {
        $types = [];
        foreach (ChartType::switchPool($this->configured_type) as $type) {
            // a 100% stacked type where the view sums or counts the measure only (else it draws a
            // message, isFullStackOfNonAdditive) — the box's own type stays on the picker
            if (ChartType::isFullStacked($type) && $type !== $this->configured_type && !$this->measureAddsUp()) {
                continue;
            }
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
        // the 集計方法 and 表示方法 submenus of the box menu
        $summary = $this->summaryMenu();
        $show_as = $this->showAsMenu();
        $top_n = $this->topNMenu($axisx_label, $axisy_label);
        $menu = !empty($sort_fields) || $labels_available || $colors_reset || $summary !== null || $show_as !== null || $top_n !== null;
        if (empty($types) && empty($fields) && !$menu) {
            return '';
        }
        return view('exment::dashboard.chart.toolbar', [
            'summary' => $summary,
            'show_as' => $show_as,
            'top_n' => $top_n,
            'title' => $this->boxTitle($axisx_label, $axisy_label),
            'types' => $types,
            // the type picker's preview draws in the box's palette
            'palette' => $this->getChartPalette(),
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
     * get chart data from list-view
     *
     * @return array{chart_data: \Illuminate\Support\Collection<array-key, mixed>, chart_label: \Illuminate\Support\Collection<int, mixed>, chart_fields: array<int, array<int, mixed>>, sort_keys: array<int, array<int, mixed>>, axisx_label: mixed, axisy_label: mixed}|false
     */
    protected function getListData()
    {
        $view_column_x = CustomViewSummary::getSummaryViewColumn($this->axis_x);
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);

        if (is_nullorempty($view_column_x) || is_nullorempty($view_column_y)) {
            return false;
        }

        // create model for getting data --------------------------------------------------
        // the rows' own columns only: a view filter on a related table joins it, and its `value`
        // would otherwise stand in for the row's
        $model = $this->custom_table->getValueQuery()->select(getDBTableName($this->custom_table) . '.*');
        $this->applyFilters($model);
        // in the order the view lists its rows (a view without a sort keeps the database's)
        $this->viewForQuery()->filterModel($model, ['sort' => true]);

        // get data
        $items = $model->get();

        $item_x = $view_column_x == Define::CHARTITEM_LABEL ? null : $this->listColumnItem($view_column_x);
        $chart_label = $items->map(function ($val) use ($item_x) {
            // if get as CHARTITEM_LABEL, return label.
            if ($item_x === null) {
                return $val->getLabel();
            }
            // plain text: drawn on a canvas and hex-encoded before reaching the DOM
            return $item_x->setCustomValue($val)->text();
        });
        $item_y = $this->listColumnItem($view_column_y);
        $chart_data = $items->map(function ($val) use ($item_y) {
            return $item_y->setCustomValue($val)->pureValue();
        });

        if ($view_column_x == Define::CHARTITEM_LABEL) {
            $axisx_label = $this->custom_table->table_view_name;
        } else {
            $axisx_label = array_get($view_column_x, 'view_column_name') ?? $view_column_x->column_item->label();
        }

        $sort_keys = [];
        if ($item_x !== null) {
            $keys = $this->numericSortKeys($view_column_x, function () use ($items, $item_x) {
                return $items->map(function ($val) use ($item_x) {
                    return $item_x->setCustomValue($val)->pureValue();
                });
            });
            if ($keys !== null) {
                $sort_keys[0] = $keys;
            }
        }

        return [
            'chart_data'    => $chart_data,
            'chart_label'   => $chart_label,
            'chart_fields'  => [$chart_label->values()->all()],
            'sort_keys'     => $sort_keys,
            'axisx_label'   => $axisx_label,
            'axisy_label'   => array_get($view_column_y, 'view_column_name') ?? $view_column_y->column_item->label(),
        ];
    }

    /**
     * get chart data from aggregate-view
     *
     * @return array{chart_data: \Illuminate\Support\Collection<array-key, mixed>, chart_label: \Illuminate\Support\Collection<int, mixed>, chart_fields: array<int, array<int, mixed>>, sort_keys: array<int, array<int, mixed>>, axisx_label: mixed, axisy_label: string|null, chart_click: array{column: string, values: array<int, string>, selected: string[], highlight: bool}|null, detail: array{points: array<int, array<int|string, mixed>>}|null}|false
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

        // get data
        $datalist = $this->summaryRows();
        // the texts per group column, read once per row and column — a select_table column
        // resolves its label with a query per row, so a second pass would double them — are what
        // a sort by one X column (ChartSort x{i}) orders on, and joined they label the point
        $chart_fields = $item_x_list->map(function ($item_x) use ($datalist) {
            return $datalist->map(function ($val) use ($item_x) {
                return $item_x->setCustomValue($val)->text();
            })->values()->all();
        })->values()->all();
        $chart_label = $datalist->values()->keys()->map(function ($i) use ($chart_fields) {
            return implode(' ', array_column($chart_fields, $i));
        });
        $chart_data = $datalist->pluck($item_y->uniqueName());

        // a numeric group column sorts (ChartSort x{i}) by its stored numbers, not its text
        $sort_keys = [];
        foreach (collect($view_column_x_list)->values() as $i => $view_column_x) {
            $item_x = $item_x_list->values()[$i];
            $keys = $this->numericSortKeys($view_column_x, function () use ($datalist, $item_x) {
                return $datalist->pluck($item_x->uniqueName());
            });
            if ($keys !== null) {
                $sort_keys[$i] = $keys;
            }
        }

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
            'sort_keys'     => $sort_keys,
            'axisx_label'   => $axisx_label,
            'axisy_label'   => $this->axisYLabel($view_column_y),
            'chart_click'   => $chart_click,
            // a double click on a point lists the rows of its group values (明細)
            'detail'        => $this->detail() === null ? null : ['points' => $this->detailKeys($datalist, collect($view_column_x_list), $item_x_list)],
        ];
    }

    /**
     * The item that reads a list view column off a row, as the view's list (DefaultGrid) reads
     * it: a column of a related table — the parent, a select_table target — reads that record
     * through the view column's pivot; without it, the row's own column of the same name.
     *
     * @param mixed $view_column  CustomViewColumn
     * @return mixed  the column item
     */
    protected function listColumnItem($view_column)
    {
        return $view_column->column_item->options([
            'view_pivot_column' => $view_column->view_pivot_column_id ?? null,
            'view_pivot_table' => $view_column->view_pivot_table_id ?? null,
        ]);
    }

    /**
     * The stored numbers of a numeric X column (integer, decimal, currency), one per point,
     * for ChartSorter's `sort_keys`: such a column is sorted by number, not by its formatted
     * text ("1,000" before "200"). null for any other column, which keeps sorting by text.
     *
     * @param mixed $view_column  CustomViewColumn / CustomViewSummary of the X axis
     * @param callable(): iterable<mixed> $values  the points' stored values, read on demand
     * @return array<int, mixed>|null
     */
    protected function numericSortKeys($view_column, callable $values): ?array
    {
        $column = is_object($view_column) ? data_get($view_column, 'custom_column') : null;
        if ($column === null || !ColumnType::isCalc(data_get($column, 'column_type'))) {
            return null;
        }
        return collect($values())->values()->all();
    }

    /**
     * Rows of the aggregate view for this box, filters applied.
     *
     * @return \Illuminate\Support\Collection<array-key, mixed>
     */
    protected function summaryRows()
    {
        return $this->viewForQuery()->getQuery($this->filteredRows())->get();
    }

    /**
     * The rows of the box's table this chart summarizes: the box's filters applied (the
     * view's own, its query adds).
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected function filteredRows()
    {
        $query = $this->custom_table->getValueQuery();
        $this->applyFilters($query);
        return $query;
    }

    /**
     * The box's view, ready for one more query. The view keeps the joins its filters added
     * (a filter on a parent / select_table / workflow column) in the search service it caches,
     * and adds them to the first query only: the next one would get the WHERE without its
     * JOIN ("Unknown column"). A box runs several — the chart filter's option lists, then the
     * data — so each starts afresh, as CustomTable does when it reuses a view.
     *
     * @return \Exceedone\Exment\Model\CustomView
     */
    protected function viewForQuery()
    {
        $this->custom_view->resetSearchService();
        return $this->custom_view;
    }

    /**
     * The chart's Y-axis label: the view column's own name, else the item's label — which the
     * engine has decorated with the view's summary condition. Switched by the 集計 menu, the
     * label of what is drawn instead: 最小値 ： 点数.
     *
     * @param CustomViewSummary $view_column_y
     */
    protected function axisYLabel($view_column_y): ?string
    {
        if (($switched = $this->summarySwitch()->valueLabel()) !== null) {
            return $switched;
        }
        $named = array_get($view_column_y, 'view_column_name');
        if (!is_nullorempty($named)) {
            return $named;
        }
        return $view_column_y->column_item->label();
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
     * @param iterable<mixed> $raw_values  raw group values, index-aligned with the chart's points
     * @return array{column: string, values: array<int, string>, selected: string[], highlight: bool}|null
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
     * bucket (date format) whose displayed value never equals the stored one, nor a list
     * column (FilterValue::isMultiple), whose point is a combination of values, not one.
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
            || FilterValue::isMultiple($custom_column)
            || $config->dim($custom_column->column_name) === null
            || $this->dashboard_filter->styleOf($custom_column) !== 'select') {
            return null;
        }
        return $custom_column;
    }

    /**
     * Whether a multi-series chart would draw the AVG of a view grouped by more columns than
     * its X axis and series: each cell would hold several averages, which ChartPivot cannot
     * combine into the cell's own average.
     */
    protected function isAverageOfExtraGroups(): bool
    {
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        return isset($view_column_y)
            && static::averageOfExtraGroups($this->custom_view, $this->summaryCondition($view_column_y));
    }

    /**
     * Whether a 100% stacked chart would draw shares of a measure that adds up to nothing: one
     * summarized by AVG / MIN / MAX (the view's, the 集計方法 offers a 100% stacked type no other).
     */
    protected function isFullStackOfNonAdditive(): bool
    {
        if (!ChartType::isFullStacked($this->chart_type)) {
            return false;
        }
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        return isset($view_column_y) && !ChartSummarySwitch::isAdditive($this->summaryCondition($view_column_y));
    }

    /**
     * Whether the view summarizes the measure so that it adds up (SUM / COUNT, its own setting):
     * a 100% stacked type is offered only then.
     */
    protected function measureAddsUp(): bool
    {
        $view_column_y = $this->isAggregateView() && is_string($this->axis_y) ? CustomViewSummary::getSummaryViewColumn($this->axis_y) : null;
        return $view_column_y instanceof CustomViewSummary && ChartSummarySwitch::isAdditive($view_column_y->view_summary_condition);
    }

    /**
     * The tooltip texts of a 100% stacked chart, {n} for a number: the total of a bar (（計 62件）)
     * and the value behind a share (（12件）) — a count in 件, a sum as it is.
     *
     * @return array{total: string, value: string}
     */
    protected function fullStackTexts(): array
    {
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        $n = (string) $this->summaryCondition($view_column_y) === SummaryCondition::COUNT
            ? (string) exmtrans('dashboard.chart_full_stack.count', '{n}')
            : '{n}';
        return [
            'total' => (string) exmtrans('dashboard.chart_full_stack.total', $n),
            'value' => (string) exmtrans('dashboard.chart_full_stack.value', $n),
        ];
    }

    /**
     * Whether a multi-series chart of $custom_view, its measure summarized by $condition, would
     * draw an average of more group columns than its X axis and series (isAverageOfExtraGroups).
     *
     * @param mixed $custom_view
     * @param mixed $condition  the measure's summary condition
     */
    public static function averageOfExtraGroups($custom_view, $condition): bool
    {
        return $custom_view instanceof CustomView
            && $custom_view->view_kind_type == ViewKindType::AGGREGATE
            && (string) $condition === SummaryCondition::AVG
            && collect($custom_view->custom_view_columns ?? [])->count() > 2;
    }

    /**
     * Whether the box form posts a multi-series chart that could draw nothing but the
     * need_average_groups message: an AVG measure of a view grouped by 3+ columns. The form
     * refuses it on save; a view changed afterwards still meets the message when drawn.
     *
     * @param mixed $chart_type
     */
    public static function formAverageOfExtraGroups($chart_type): bool
    {
        if (!is_string($chart_type) || !ChartType::isMulti($chart_type)) {
            return false;
        }
        $measure = static::formMeasure();
        return $measure !== null && static::averageOfExtraGroups($measure[0], $measure[1]->view_summary_condition);
    }

    /**
     * Whether the box form posts a 100% stacked chart of a measure summarized other than by SUM /
     * COUNT, which could draw nothing but the need_additive message. A view changed afterwards
     * still meets the message when drawn.
     *
     * @param mixed $chart_type
     */
    public static function formFullStackOfNonAdditive($chart_type): bool
    {
        if (!is_string($chart_type) || !ChartType::isFullStacked($chart_type)) {
            return false;
        }
        $measure = static::formMeasure();
        return $measure !== null && !ChartSummarySwitch::isAdditive($measure[1]->view_summary_condition);
    }

    /**
     * The aggregate view and its summary column the box form posts as the measure; null when
     * the posted Y axis is no summary column of the posted view.
     *
     * @return array{0: CustomView, 1: CustomViewSummary}|null
     */
    protected static function formMeasure(): ?array
    {
        $view_id = request()->input('options.target_view_id');
        $axis_y = request()->input('options.chart_axisy');
        if (!is_scalar($view_id) || !is_string($axis_y)) {
            return null;
        }
        $custom_view = CustomView::getEloquent($view_id);
        $view_column_y = CustomViewSummary::getSummaryViewColumn($axis_y);
        if (!$custom_view instanceof CustomView || !$view_column_y instanceof CustomViewSummary
            || $view_column_y->custom_view_id != $custom_view->id) {
            return null;
        }
        return [$custom_view, $view_column_y];
    }

    /**
     * Pivoted data of a multi-series chart from an aggregate view grouped by 2+ columns:
     * the series column (chart_series, default = 2nd column) splits the legend, the first
     * other group column is the X axis, the measure (chart_axisy) fills each cell —
     * ChartPivot: a category per stored X value, the rows of a cell folded as the
     * measure's summary condition folds rows.
     *
     * @return array|false {x_categories[], series_names[], matrix[seriesIdx][xIdx], axisx_label, series_label, axisy_label, chart_click}
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

        $x_key = $item_x->uniqueName();
        $series_key = $item_series->uniqueName();
        $y_key = $item_y->uniqueName();
        $rows = [];
        // the stored value behind each category / series, as ChartPivot tells them apart (the
        // first one met: a NULL stays NULL for the 明細, which the pivot's texts cannot keep)
        $x_raw = [];
        $series_raw = [];
        foreach ($this->summaryRows() as $val) {
            $x_raw += [ChartPivot::key(data_get($val, $x_key)) => data_get($val, $x_key)];
            $series_raw += [ChartPivot::key(data_get($val, $series_key)) => data_get($val, $series_key)];
            $rows[] = [
                'x' => data_get($val, $x_key),
                'x_text' => $item_x->setCustomValue($val)->text(),
                // a series per stored value: two same-name records stay two series, as the
                // single-series chart keeps them two points
                'series_key' => data_get($val, $series_key),
                'series' => $item_series->setCustomValue($val)->text(),
                'y' => data_get($val, $y_key),
            ];
        }
        $pivot = ChartPivot::build($rows, $this->summaryCondition($view_column_y));

        return [
            'x_categories' => $pivot['x_categories'],
            'series_names' => $pivot['series_names'],
            'matrix'       => $pivot['matrix'],
            // a numeric X column sorts (ChartSort x0) by its stored numbers, not its text
            'sort_keys'    => array_filter([0 => $this->numericSortKeys($view_columns[$x_pos], function () use ($pivot) {
                return $pivot['x_values'];
            })], 'is_array'),
            'axisx_label'  => array_get($view_columns[$x_pos], 'view_column_name') ?? $item_x->label(),
            'series_label' => array_get($view_columns[$series_pos], 'view_column_name') ?? $item_series->label(),
            'axisy_label'  => $this->axisYLabel($view_column_y),
            // a click on a category picks the stored value its rows hold
            'chart_click'  => $this->clickFilter($view_columns[$x_pos], $pivot['x_values']),
            // a double click on a cell lists the rows of its category and series (明細)
            'detail'       => $this->detail() === null ? null : [
                'x' => $view_columns[$x_pos]->id,
                'series' => $view_columns[$series_pos]->id,
                'x_keys' => array_map(function ($v) use ($x_raw) {
                    return $x_raw[$v] ?? null;
                }, $pivot['x_values']),
                'series_keys' => array_values($series_raw),
            ],
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
                ->options(ChartType::transArray("chart.chart_type_options"))
                // a multi-series type of an AVG measure grouped by 3+ columns would draw a message only
                ->rules([function ($attribute, $value, $fail) {
                    if (ChartItem::formAverageOfExtraGroups($value)) {
                        $fail(exmtrans('dashboard.message.need_average_groups_form'));
                    } elseif (ChartItem::formFullStackOfNonAdditive($value)) {
                        // shares of an average / minimum / maximum mean nothing
                        $fail(exmtrans('dashboard.message.need_additive_form'));
                    }
                }]);

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
                // the admin form binds this closure to the model, so the class is named explicitly
                return array_column(ChartItem::seriesSelectOptions(ChartItem::formCustomView($model)), 'text', 'id');
            });

        // chart filter: columns of the table offered as filter fields on this chart only
        $form->multipleSelect('chart_filters', exmtrans("dashboard.dashboard_box_options.chart_filters"))
            ->options(function ($value, $model) {
                $table_id = array_get(request()->all(), 'options.target_table_id') ?? array_get($model->data(), 'target_table_id');
                // of a table this user may view only, as its ajax list (chart_filter_columns): the
                // form is open to anyone who may view the dashboard, with any table id posted back
                $custom_table = is_scalar($table_id) ? CustomTable::getEloquent($table_id) : null;
                if ($custom_table !== null && !$custom_table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                    $custom_table = null;
                }
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
        $axisTypes = json_encode(ChartType::axisOptionTypes());
        $multiTypes = json_encode(ChartType::multiTypes());
        $thresholdsTable = ChartThresholds::OPTION;
        $heatmap = ChartType::HEATMAP;
        $script = <<<EOT
        var exmentLegendCharts = $legendTypes;
        var exmentAxisCharts = $axisTypes;
        var exmentMultiCharts = $multiTypes;
        function setChartOptions(val) {
            var legend = exmentLegendCharts.indexOf(val) >= 0;
            var axis = exmentAxisCharts.indexOf(val) >= 0;
            $('#chart_options > .icheck:nth-child(1)').toggle(legend);
            $('#chart_options > .icheck:nth-child(2)').toggle(axis);
            // hbar / area / scatter / gauge carry neither flag: no empty オプション row
            $('#chart_options').closest('.form-group').toggle(legend || axis);
            // the axis options are read by the Chart.js axis types only (ChartType::axisOptionTypes)
            $('#chart_axis_label, #chart_axis_name').closest('.form-group').toggle(axis);
            $('.options_chart_series').closest('.form-group').toggle(exmentMultiCharts.indexOf(val) >= 0);
            // the color thresholds color a heatmap only (setAdminExtraFields)
            $('#has-many-table-{$thresholdsTable}').toggle(val === '{$heatmap}');
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
        // the axis options are read by the Chart.js axis types only: elsewhere the form hides
        // them (setChartOptions above) and nothing of them is stored
        if (!in_array($chart_type, ChartType::axisOptionTypes(), true)) {
            $options['chart_axis_label'] = [];
            $options['chart_axis_name'] = [];
        }
        $keep = static::defaultChartOptions($chart_type);
        $options['chart_options'] = array_values(array_filter($chart_options, function ($option) use ($keep) {
            return in_array($option, $keep);
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
     * The fields of the box form out of its embedded options: the 色分けのしきい値 of a heatmap
     * (ChartThresholds), as a table of rules — an embedded form keeps only its own fields, so
     * the table is bound to the box (DashboardBox::chart_thresholds) and saved into the options
     * by savedOptions(). Shown for a heatmap only (setAdminOptions' script).
     */
    // @phpstan-ignore-next-line
    public static function setAdminExtraFields(&$form): void
    {
        $form->hasManyJsonTable(ChartThresholds::OPTION, exmtrans('dashboard.chart_threshold.label'), function ($form) {
            // #rrggbb, hidden behind a button that picks it (dashboard.js ExmentChartThresholdForm)
            // — not the color field, whose picker rounds a color it is given (#f8696b comes back
            // #f8686b), so a box would change its colors on every save
            $form->text('color', exmtrans('dashboard.chart_threshold.color'))
                ->attribute(['maxlength' => 7, 'placeholder' => '#rrggbb'])
                ->rules([new ChartThresholdRule('color')]);
            $form->select('condition', exmtrans('dashboard.chart_threshold.condition'))
                ->options(ChartThresholds::conditionOptions())
                ->default(ChartThresholds::LT)
                ->disableClear()
                ->rules([new ChartThresholdRule('condition')]);
            $form->text('value', exmtrans('dashboard.chart_threshold.value'))
                ->attribute(['inputmode' => 'decimal'])
                ->rules([new ChartThresholdRule('value')]);
            // the upper end of a range (A以上B未満); the other conditions have none
            $form->text('value2', exmtrans('dashboard.chart_threshold.value2'))
                ->attribute(['inputmode' => 'decimal'])
                ->rules([new ChartThresholdRule('value2')]);
        })->rowUpDown('order')
            ->setTableColumnWidth(3, 3, 2, 2, 2)
            ->description(sprintf(exmtrans('dashboard.chart_threshold.help'), ChartThresholds::MAX));

        // the rows are no column of the box (DashboardBox::setChartThresholdsAttribute takes
        // nothing): savedOptions() stores them
        $form->editing(function ($form) {
            $form->model()->append([ChartThresholds::OPTION]);
        });
        Admin::script('ExmentChartThresholdForm.init(' . json_encode([
            'palette' => ChartThresholds::palette(),
            'table' => ChartThresholds::OPTION,
            'range' => ChartThresholds::RANGE,
            'labels' => [
                'title' => exmtrans('dashboard.chart_threshold.color_select'),
                'other' => exmtrans('dashboard.chart_threshold.color_other'),
                'custom' => exmtrans('dashboard.chart_threshold.color_custom'),
                'placeholder' => exmtrans('dashboard.chart_threshold.color_placeholder'),
                'picker' => exmtrans('dashboard.chart_threshold.custom_color'),
            ],
        ], static::JSON_FLAGS) . ');');
    }

    /**
     * The options a save of the box form stores: the embedded fields' and the 色分けのしきい値
     * of a heatmap (setAdminExtraFields; validated by ChartThresholdRule) — none on another
     * type, whose form hides the table as it does the options the type does not read.
     *
     * @param array<string, mixed> $options  the embedded fields' options as saved
     * @return array<string, mixed>
     */
    public static function savedOptions(array $options): array
    {
        $rules = array_get($options, 'chart_type') === ChartType::HEATMAP
            ? ChartThresholds::normalize(request()->input(ChartThresholds::OPTION))
            : [];
        if (count($rules)) {
            $options[ChartThresholds::OPTION] = $rules;
        } else {
            unset($options[ChartThresholds::OPTION]);
        }
        return $options;
    }

    /**
     * The option flag a type can carry (ChartOptionType): the legend on the types that draw
     * one, "begin at zero" on the Chart.js axis types, nothing on the other ECharts types.
     *
     * @return string[]
     */
    protected static function defaultChartOptions(?string $type): array
    {
        if (in_array($type, ChartType::legendTypes(), true)) {
            return [ChartOptionType::LEGEND];
        }
        return in_array($type, ChartType::axisOptionTypes(), true) ? [ChartOptionType::BEGIN_ZERO] : [];
    }

    /**
     * The view chosen on the box form (the posted one while editing, else the stored one):
     * of a table this user may view only, as its ajax list (chart_axis): the form is open to
     * anyone who may view the dashboard, with any view id posted back.
     */
    // @phpstan-ignore-next-line
    public static function formCustomView($model)
    {
        $view_id = array_get(request()->all(), 'options.target_view_id') ?? array_get($model->data(), 'target_view_id');
        $custom_view = is_scalar($view_id) ? CustomView::getEloquent($view_id) : null;
        if ($custom_view === null || !$custom_view->custom_table?->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return null;
        }
        return $custom_view;
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
     * One color per series of a multi-series chart (index-aligned with the series names): the
     * one an editor painted on it, else the palette's; the heatmap and the boxplot paint no
     * series, they keep the palette.
     *
     * @param array<int, mixed> $series_names
     * @return string[]
     */
    protected function seriesColors(array $series_names): array
    {
        return ChartType::supportsColorEdit($this->chart_type)
            ? $this->chartColors()->colorsFor(ChartColors::SERIES, $series_names, $this->getChartPalette())
            : $this->getChartPalette();
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
