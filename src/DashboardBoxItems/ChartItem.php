<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\AiChatService;
use Exceedone\Exment\Services\AnomalyDetector;
use Exceedone\Exment\Services\Dashboard\BoxChartConfig;
use Exceedone\Exment\Services\Dashboard\ChartRendererRegistry;
use Exceedone\Exment\Services\Dashboard\FilterBarConfig;
use Exceedone\Exment\Services\Dashboard\FilterBarContextBuilder;
use Exceedone\Exment\Services\Dashboard\FilterState;
use Exceedone\Exment\Services\Dashboard\LevelViewResolver;
use Exceedone\Exment\Enums\ChartAxisType;
use Exceedone\Exment\Enums\ChartOptionType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SummaryCondition;

class ChartItem implements ItemInterface
{
    use TableItemTrait;

    // @phpstan-ignore-next-line
    protected $dashboard_box;

    // Typed reader for this box's options.chart_* keys (see BoxChartConfig).
    // @phpstan-ignore-next-line
    protected $config;

    // @phpstan-ignore-next-line
    protected $custom_table;

    // @phpstan-ignore-next-line
    protected $custom_view;

    // @phpstan-ignore-next-line
    protected $axis_x;

    // @phpstan-ignore-next-line
    protected $axis_y;

    // @phpstan-ignore-next-line
    protected $chart_type;

    // @phpstan-ignore-next-line
    protected $chart_series;

    // @phpstan-ignore-next-line
    protected $chart_options;

    // @phpstan-ignore-next-line
    protected $chart_axis_label;

    // @phpstan-ignore-next-line
    protected $chart_axis_name;

    // @phpstan-ignore-next-line
    protected $chart_drill_urls;

    // When LevelViewResolver swapped this box to a deeper-level view, the swapped view's
    // name — rendered as a small caption so the user can see which level the chart is showing.
    // @phpstan-ignore-next-line
    protected $level_caption;

    // Memoized filter-bar chain info (see filterBarChain()) — computed once per request,
    // shared by level resolution, level visibility, and the AI scope benchmark.
    // @phpstan-ignore-next-line
    protected $filter_chain;

    // Name of the level view the readability cap (chart_level_max_groups) refused — the
    // caption then names it and tells the user to narrow the filters. Null = nothing refused.
    // @phpstan-ignore-next-line
    protected $level_hint = null;

    // Memoized human-readable context of this box's ACTIVE chart-level filters
    // (see boxFilterContext()) — shown as a caption under the toolbar and as extra
    // tooltip lines, so a bar filtered down to one child says WHICH child.
    // @phpstan-ignore-next-line
    protected $bf_context = null;

    // id => display name of the chart-level filter options the toolbar already resolved this
    // render (column => [id => name]); boxFilterContext() reads it first so the caption does
    // not re-query names the popover just fetched.
    // @phpstan-ignore-next-line
    protected $bf_option_names = [];


    // @phpstan-ignore-next-line
    public function __construct($dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;
        // one typed reader for every options.chart_* key (schema lives in BoxChartConfig)
        $this->config = BoxChartConfig::of($dashboard_box);

        // get table and view
        $this->custom_table = CustomTable::getEloquent($this->config->targetTableId());
        $this->custom_view = CustomView::getEloquent($this->config->targetViewId());

        $this->axis_x = $this->config->axisX();
        $this->axis_y = $this->config->axisY();
        // Runtime chart-type override (`ct` box-AJAX param, emitted by the on-dashboard
        // switcher): presentation-only — validated against the registry's same-dataset-shape
        // pool, anything else silently falls back to the configured type. Applied here so
        // every type-gated decision below (markers, blade family, view guards) stays
        // consistent, exactly as if the box had been configured with that type.
        // chart_type_lock hides the switcher AND makes the server ignore a hand-made ct.
        $this->chart_type = ChartRendererRegistry::effectiveType(
            $this->config->chartType(),
            $this->config->typeLock() ? null : request()->input('ct')
        );
        $this->chart_series = $this->config->series();
        $this->chart_options = $this->config->chartOptions();
        // saving() keeps only the option flags of the CONFIGURED family (LEGEND for circular
        // types, BEGIN_ZERO for the rest — the form never shows the other checkbox), so a
        // bar→pie switch would render a pie without its legend. A cross-family runtime switch
        // therefore gets its family's default flag; a same-family switch keeps the flags as is.
        $configuredType = $this->config->chartType();
        if ($this->chart_type !== $configuredType) {
            if (ChartType::isCircular($this->chart_type) && !ChartType::isCircular($configuredType)
                && !in_array(ChartOptionType::LEGEND, $this->chart_options)) {
                $this->chart_options[] = ChartOptionType::LEGEND;
            } elseif (!ChartType::isCircular($this->chart_type) && ChartType::isCircular($configuredType)
                && !in_array(ChartOptionType::BEGIN_ZERO, $this->chart_options)) {
                $this->chart_options[] = ChartOptionType::BEGIN_ZERO;
            }
        }
        $this->chart_axis_label = $this->config->axisLabel();
        $this->chart_axis_name = $this->config->axisName();
        // drill-down: map of x-axis label => URL. Clicking that bar/point navigates there
        // (e.g. school "score by grade" chart drills into the grade's own dashboard).
        $this->chart_drill_urls = $this->config->drillUrls();

        // Mutual exclusion, enforced at the SOURCE: strip df_ params locked out by an active
        // 'disables' dim from the request itself, BEFORE anything reads them. The filter bar
        // already drops them from its UI, but a deep link / stale URL / breadcrumb hop can
        // still carry the combination — without this, boxes would silently filter by an
        // invisible dim (numbers outside the selected band with no explanation on screen).
        $this->sanitizeExclusiveDfParams();

        // drill-by-filter (chart_level_views) + degenerate-pin swap (chart_pinned_views):
        // LevelViewResolver decides against the post-sanitize filter state; applying the
        // outcome up front keeps every consumer — body, AI insight, click-to-filter — on
        // one consistent level.
        $resolution = LevelViewResolver::resolve(
            $this->config,
            $this->custom_table,
            $this->custom_view,
            $this->chart_type,
            $this->filterBarChain()
        );
        if ($resolution->view !== null) {
            $this->custom_view = $resolution->view;
            $this->axis_y = $resolution->axisY;
        }
        $this->level_caption = $resolution->caption;
        $this->level_hint = $resolution->hint;

    }

    /**
     * Remove df_ request params that are locked out by the dashboard's mutual-exclusion
     * config (dim option 'disables' — see DashboardController::buildDashboardFilterContext).
     * Mutating the request is deliberate: every consumer — filters, badges, benchmarks,
     * AI insight — reads df_ straight off the request, so stripping here once
     * keeps them all consistent with what the filter bar shows. Idempotent per request.
     *
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function sanitizeExclusiveDfParams()
    {
        FilterState::sanitizeExclusive($this->dashboard_box ? $this->dashboard_box->dashboard : null);
    }

    /**
     * Filter-bar chain info for this box, memoized per request:
     *
     *   chain   — the hierarchy dim columns in declared (root→leaf) order. Hierarchy dims are
     *             linked by a parent edge (they have a parent, or another dim declares them as
     *             its parent); an independent cross-cut dim is never part of the chain.
     *   deepest — the LAST chain dim with an active df_ selection ('' when none). Selections
     *             need not be contiguous — "school only" still resolves to the school depth.
     *   applied — every active df_{column} whose column really exists on THIS box's table
     *             (same whitelist as applyDashboardFilter), for raw scope queries.
     *
     * @return array {chain: string[], deepest: string, applied: array<string,string>}
     */
    // @phpstan-ignore-next-line
    protected function filterBarChain()
    {
        if ($this->filter_chain !== null) {
            return $this->filter_chain;
        }
        $chain = [];
        $deepest = '';
        $applied = [];

        $barConfig = FilterBarConfig::fromDashboard($this->dashboard_box ? $this->dashboard_box->dashboard : null);
        if ($barConfig !== null) {
            $chain = $barConfig->chainColumns();
            foreach ($chain as $col) {
                // any value shape counts as "selected" here (one value, several, a range)
                if (FilterState::spec(request()->input('df_' . $col)) !== null) {
                    $deepest = $col;
                }
            }
        }

        if (!is_nullorempty($this->custom_table)) {
            // box-aware: a dim whose `targets` excludes this box does not count as applied here
            $applied = FilterState::columnsOn($this->custom_table, $this->dashboard_box);
        }

        return $this->filter_chain = ['chain' => $chain, 'deepest' => $deepest, 'applied' => $applied];
    }

    /**
     * Level-aware presence: a box whose chart_level_visible option lists drill depths
     * ('' = nothing selected, else a chain dim column) renders ONLY at those depths.
     * No option (the default) = always visible.
     *
     * @return bool
     */
    // @phpstan-ignore-next-line
    protected function isVisibleAtCurrentLevel()
    {
        // chart_hide_when_pinned: hide the box entirely while any of the listed df_ dims is
        // active — for charts whose values would fall OUTSIDE a selected measure band (e.g.
        // the per-subject chart under a score-range filter shows subject means below the
        // band, which reads as a contradiction). Coherence beats completeness there.
        foreach ($this->config->hideWhenPinned() as $col) {
            if (!FilterState::isIdentifier($col)) {
                continue;
            }
            if (FilterState::spec(request()->input('df_' . $col)) !== null) {
                return false;
            }
        }
        // chart_hide_when_capped: hide the whole box (rather than fall back to the shallower
        // geographic view) when the readability cap refused this box's deeper breakdown — i.e.
        // an academic level (grade/class) was picked without narrowing the geography enough to
        // make it readable. level_hint is set by the level resolver exactly in that case, so a
        // "grade selected nationwide" state simply removes the comparison chart instead of
        // showing a region chart the user did not ask for. A normal drill never sets it.
        if (!is_nullorempty($this->level_hint) && $this->config->hideWhenCapped()) {
            return false;
        }
        $visible = $this->config->levelVisible();
        if (empty($visible)) {
            return true;
        }
        return in_array($this->filterBarChain()['deepest'], array_map('strval', $visible), true);
    }

    /**
     * Small caption above the chart naming the active level view, only when
     * the level resolver actually swapped it (otherwise empty string).
     *
     * @return string
     */
    // @phpstan-ignore-next-line
    protected function levelCaptionHtml()
    {
        $html = '';
        if (!is_nullorempty($this->level_caption)) {
            $html .= '<div class="chart-level-caption" style="margin:0 8px 2px; font-size:11px; color:#666;">'
                . esc_html(exmtrans('dashboard.filter_bar.level_caption')) . ': '
                . '<span style="font-weight:600;">' . esc_html($this->level_caption) . '</span></div>';
        }
        // The readability cap keeps the shallower (geographic) chart when a deep breakdown
        // would explode — this is by design (an academic level picked without a school acts
        // as a FILTER on the geographic comparison, not a drill), so no apology is needed.
        // The hint is therefore OPT-IN per box (chart_level_hint); off by default = clean.
        if (!is_nullorempty($this->level_hint) && $this->config->levelHintEnabled()) {
            $html .= '<div class="chart-level-hint" style="margin:0 8px 2px; font-size:11px; color:#8a6d1a;">'
                . esc_html(str_replace(':name', $this->level_hint, exmtrans('dashboard.filter_bar.level_too_many'))) . '</div>';
        }
        return $html;
    }

    /**
     * The on-chart TOOLBAR — one 30px row unifying the runtime controls of a chart box:
     *
     *   ⋯  [フィルター ❷ ▾] [棒グラフ ▾]
     *
     * The filter pill opens a popover (chart-level filter fields); the chart-type select
     * sits inline. Each fragment renders only when its feature applies — a legacy box with
     * none configured gets at most the type select, an unconfigured widget gets nothing.
     *
     * Styling/JS live in DashboardController::home (shared CSS + delegated handlers);
     * everything here is server-rendered state, so UI and query can never disagree.
     *
     * @return string
     */
    // @phpstan-ignore-next-line
    protected function chartToolbarHtml()
    {
        $filter = $this->toolbarFilterHtml();
        $type = $this->toolbarTypeHtml();
        if ($filter === '' && $type === '') {
            return '';
        }
        // Active chart-level filters, spelled out under the toolbar ("児童: 竹内 湊 ・ クラス:
        // 渋谷ABC 4-B"): the popover only shows a count, and a bar filtered down to one child
        // must say WHICH child without a click — the Power BI "filters on this visual" cue.
        $caption = '';
        $context = $this->boxFilterContext();
        if (!empty($context)) {
            $caption = '<div class="ct-active"><i class="fa fa-filter"></i>'
                . implode('<span class="ct-active-sep">・</span>', array_map(function ($c) {
                    return '<span><b>' . esc_html($c['label']) . '</b>: ' . esc_html($c['value']) . '</span>';
                }, $context))
                . '</div>';
        }
        return '<div class="chart-toolbar">'
            . '<span class="ct-spacer"></span>' . $filter . $type
            . '</div>' . $caption;
    }

    /**
     * Human-readable context of this box's ACTIVE chart-level filters: one entry per
     * filtered column, values resolved to their display names (select_table labels,
     * select_valtext texts) through the same option machinery the popover uses; a range
     * reads "from – to". [] when no chart-level filter is active. Memoized per render.
     *
     * @return array<int, array{label: string, value: string}>
     */
    // @phpstan-ignore-next-line
    protected function boxFilterContext()
    {
        if ($this->bf_context !== null) {
            return $this->bf_context;
        }
        $out = [];
        $active = FilterState::boxFilters($this->dashboard_box, $this->custom_table);
        if (!empty($active) && !is_nullorempty($this->custom_table)) {
            $builder = new FilterBarContextBuilder();
            foreach ($active as $col => $val) {
                $columnModel = $this->custom_table->custom_columns->firstWhere('column_name', $col);
                $spec = FilterState::spec($val);
                if (is_nullorempty($columnModel) || $spec === null) {
                    continue;
                }
                if (isset($spec['in'])) {
                    // names the toolbar popover resolved this render come free; only values it
                    // did not offer (a stale pick, a capped list) cost one scoped lookup
                    $names = $this->bf_option_names[$col] ?? [];
                    $missing = array_values(array_filter($spec['in'], function ($v) use ($names) {
                        return !isset($names[(string) $v]);
                    }));
                    if (!empty($missing)) {
                        foreach ($builder->columnOptions($this->custom_table, $col, [$col => ['in' => $missing]], null) as $opt) {
                            $names[(string) $opt['id']] = (string) $opt['name'];
                        }
                    }
                    $text = implode(', ', array_map(function ($v) use ($names) {
                        return $names[(string) $v] ?? (string) $v;
                    }, $spec['in']));
                } else {
                    $text = FilterState::values($spec)[0] ?? '';
                }
                $out[] = ['label' => (string) $columnModel->column_view_name, 'value' => $text];
            }
        }
        return $this->bf_context = $out;
    }

    /**
     * Toolbar fragment: the chart-type select (`ct` param) — same behavior/class as ever
     * (`.exment-ct-switch`), restyled as a toolbar pill. Empty for widgets and locked boxes.
     *
     * @return string
     */
    // @phpstan-ignore-next-line
    protected function toolbarTypeHtml()
    {
        if ($this->config->typeLock()) {
            return '';
        }
        $pool = ChartRendererRegistry::switchPool($this->config->chartType());
        if (count($pool) < 2) {
            return '';
        }
        $html = '<select class="exment-ct-switch ct-sel"'
            . ' title="' . esc_html(exmtrans('dashboard.dashboard_box_options.chart_type')) . '">';
        foreach ($pool as $type) {
            $html .= '<option value="' . esc_html($type) . '"'
                . ($type === $this->chart_type ? ' selected' : '') . '>'
                . esc_html(exmtrans('chart.chart_type_options.' . $type)) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    /**
     * Toolbar fragment: the フィルター button (badge = active chart-level filters) +
     * popover grid of the declared bf fields. Same params/whitelist as ever — `bf_{column}`
     * on this box's AJAX only — with two field kinds (the Power BI slicer pair):
     *
     *   `.exment-bf-list[data-column]`   a checklist of the column's distinct values
     *                                    (multi-select → bf_{column}[]=…), option lists via
     *                                    the filter bar's machinery (index-backed DISTINCT,
     *                                    label resolve, cardinality cap with the "narrow
     *                                    first" hint on capped columns)
     *   `.exment-bf-range[data-column][data-bound=from|to]`
     *                                    a from / to pair for number and date columns
     *                                    (→ bf_{column}[from]=… / [to]=…)
     *
     * LAZY option lists: a render does not compute the DISTINCT list of any checklist — that
     * would cost one query per column on every reload of the box, popover open or not (and a
     * full scan on a non-indexed column). The body renders each checklist as
     * a shell (`data-lazy="1"`) holding only the CHECKED values (so the selection, the count
     * badge, the AI-insight params and the collect JS all still work), and the dashboard JS
     * fetches the full lists from `dashboardbox/chart_filter_options/{suuid}` (see
     * chartFilterOptions()) the first time the popover opens after a render. Per render this
     * costs nothing when nothing is selected, and one small id-scoped lookup per selected
     * column otherwise (names + stale detection).
     *
     * The re-rendered body echoes the current selection (checked / filled), so the
     * page-lifetime state survives every reload of the box.
     *
     * @return string
     */
    // @phpstan-ignore-next-line
    protected function toolbarFilterHtml()
    {
        $ctx = $this->chartFilterContext();
        if ($ctx === null) {
            return '';
        }
        $active = $ctx['active'];

        $fields = '';
        foreach ($ctx['columns'] as $col => $columnModel) {
            $spec = FilterState::spec($active[$col] ?? null);
            $selectedValues = ($spec !== null && isset($spec['in'])) ? $spec['in'] : [];
            $label = '<span>' . esc_html($columnModel->column_view_name)
                . ($spec !== null ? '<em class="ct-fsel">' . count(FilterState::values($spec)) . '</em>' : '')
                . '</span>';

            // number / date column → a from/to range (see FilterState::style); everything
            // else → the checklist of distinct values.
            if (FilterState::style($columnModel) === 'range') {
                $kind = FilterState::kind($columnModel);
                $range = ['from' => '', 'to' => ''];
                if ($spec !== null && !isset($spec['in'])) {
                    $range = ['from' => (string) ($spec['from'] ?? ''), 'to' => (string) ($spec['to'] ?? '')];
                } elseif (!empty($selectedValues)) {
                    $range = ['from' => (string) $selectedValues[0], 'to' => (string) end($selectedValues)];
                }
                $type = $kind === 'number' ? 'number' : (($kind === 'date' || $kind === 'datetime') ? 'date' : 'text');
                $input = function ($bound) use ($col, $type, $range) {
                    return '<input type="' . $type . '" class="exment-bf-range' . ($range[$bound] !== '' ? ' active' : '') . '"'
                        . ' data-column="' . esc_html($col) . '" data-bound="' . $bound . '"'
                        . ' value="' . esc_html($range[$bound]) . '"'
                        . ' placeholder="' . esc_html(exmtrans('dashboard.filter_bar.range_' . $bound)) . '">';
                };
                $fields .= '<div class="ct-fitem">' . $label
                    . '<div class="ct-frange">' . $input('from') . '<i>–</i>' . $input('to') . '</div></div>';
                continue;
            }

            // Checklist shell: only the CHECKED values are rendered here (resolved with one
            // id-scoped lookup); the full list arrives lazily (data-lazy). A selected value the
            // current scope no longer offers is STALE — typically a chart-level pick made before
            // the dashboard slicer moved elsewhere (class 1-B ticked, then 学年 = 3年): it can
            // only empty the chart. Flag the list; the dashboard JS then drops those values from
            // its page-lifetime map and reloads the box once (relevant-values rule, same as the
            // bar's cascade).
            $checkedIds = array_map('strval', $selectedValues);
            $options = $this->resolveCheckedOptions($col, $spec, $ctx);
            foreach ($options as $opt) {
                $this->bf_option_names[$col][(string) $opt['id']] = (string) $opt['name'];
            }
            $offered = array_map('strval', array_column($options, 'id'));
            $stale = count(array_diff($checkedIds, $offered)) > 0;

            $fields .= '<div class="ct-fitem">' . $label
                . '<div class="exment-bf-list' . (!empty($selectedValues) ? ' active' : '') . '"'
                . ' data-column="' . esc_html($col) . '" data-lazy="1"' . ($stale ? ' data-stale="1"' : '') . '>'
                . '<div class="exment-bf-opts">';
            foreach ($options as $opt) {
                $fields .= '<label class="on"><input type="checkbox" class="exment-bf-check"'
                    . ' value="' . esc_html($opt['id']) . '" checked>'
                    . '<span>' . esc_html($opt['name']) . '</span></label>';
            }
            $fields .= '</div></div></div>';
        }

        if ($fields === '') {
            return '';
        }
        $count = count($active);
        // wide enough for the fields to sit side by side (the toolbar JS pulls it back inside
        // the card when the card is narrower); 3 fields ≈ 3 columns, so a long list per field
        // scrolls inside its own column instead of the whole popover running off the card
        $nFields = substr_count($fields, '<div class="ct-fitem');
        $popWidth = min(190 * max(2, $nFields) + 30, 720);
        return '<div class="ct-item">'
            . '<button type="button" class="ct-btn" data-pop="filters">'
            . '<span class="ct-lbl">' . esc_html(exmtrans('dashboard.box_filter.label')) . '</span>'
            . ($count > 0 ? '<span class="ct-cnt">' . $count . '</span>' : '')
            . '<span class="ct-car">▾</span></button>'
            . '<div class="ct-pop right" data-pop-id="filters" data-pop-width="' . $popWidth . '">'
            . '<h4>' . esc_html(exmtrans('dashboard.dashboard_box_options.chart_filters')) . '</h4>'
            . '<div class="ct-fgrid">' . $fields . '</div>'
            . '<div class="ct-pfoot"><a class="exment-bf-reset">' . esc_html(trans('admin.reset')) . '</a></div>'
            . '</div></div>';
    }

    /**
     * Everything the chart-level filter UI and its option endpoint share for this render:
     * the declared columns that really exist on the table (in configured order), the active
     * bf values, the dashboard filters reaching this box, the bar's fixed scope restricted to
     * this table, the cardinality cap and an option builder. Null when the box has no usable
     * chart filters (nothing declared, no table, or no permission on it).
     *
     * @return array{columns: array<string, mixed>, active: array, applied_df: array, fixed_scope: array, cap: int, builder: FilterBarContextBuilder}|null
     */
    // @phpstan-ignore-next-line
    protected function chartFilterContext()
    {
        $filter_columns = $this->config->filters();
        if (empty($filter_columns) || is_nullorempty($this->custom_table)) {
            return null;
        }
        $columns = [];
        foreach ($filter_columns as $col) {
            $columnModel = $this->custom_table->custom_columns->firstWhere('column_name', $col);
            if (!is_nullorempty($columnModel)) {
                $columns[$col] = $columnModel; // a configured column no longer on the table is skipped safely
            }
        }
        if (empty($columns)) {
            return null;
        }
        // Same cardinality cap and the same fixed scope as the dashboard's filter bar
        // (options.filter_bar.max_options / .scope): a one-school dashboard lists the school's
        // 24 classes here too, not the 1,536 nationwide — only scope columns the box's own
        // table carries are applied. Without a bar config: cap 500, no scope (as before).
        $barConfig = FilterBarConfig::fromDashboard($this->dashboard_box ? $this->dashboard_box->dashboard : null);
        $fixedScope = [];
        foreach ($barConfig ? $barConfig->scope() : [] as $scol => $sspec) {
            if (!is_nullorempty($this->custom_table->custom_columns->firstWhere('column_name', $scol))) {
                $fixedScope[$scol] = $sspec;
            }
        }
        return [
            'columns' => $columns,
            // table-aware: a selected column that no longer exists on the table is neither counted
            // nor used as scope for the other lists (the query ignores it too)
            'active' => FilterState::boxFilters($this->dashboard_box, $this->custom_table),
            'applied_df' => FilterState::columnsOn($this->custom_table, $this->dashboard_box),
            'fixed_scope' => $fixedScope,
            'cap' => $barConfig ? $barConfig->maxOptions() : 500,
            'builder' => new FilterBarContextBuilder(),
        ];
    }

    /**
     * Scope of ONE checklist's option list = the dashboard's fixed scope + dashboard filters
     * reaching this box + the box's OTHER filter fields, so each list shows only values
     * compatible with the rest of the selection (a scope column the field itself is on is
     * not re-applied — the field lists that column).
     *
     * @param string $col
     * @param array $ctx  see chartFilterContext()
     * @return array column => spec
     */
    // @phpstan-ignore-next-line
    protected function chartFilterOptionScope($col, array $ctx)
    {
        $scope = $ctx['fixed_scope'];
        unset($scope[$col]);
        $scope = $ctx['applied_df'] + $scope;
        foreach ($ctx['active'] as $c => $v) {
            if ($c !== $col) {
                $scope[$c] = $v;
            }
        }
        return $scope;
    }

    /**
     * Resolve the CHECKED values of one checklist (ids → labels) with a single id-scoped
     * lookup: the field's scope (chartFilterOptionScope) with the selection itself on $col.
     * When a dashboard filter sits on the SAME column (slicer クラス = 2-A, 2-B and a chart
     * filter on クラス too) the two are intersected, so a value the slicer no longer offers is
     * simply not returned — which is exactly what flags it stale. Returns [] when nothing is
     * selected or the intersection is empty (never an unscoped query).
     *
     * NOTE: `$scope + [$col => $spec]` would KEEP the dashboard spec on $col and ignore the
     * selection — every slicer value then came back as "checked" (2-B ticked → 2-A and 2-B both
     * rendered checked, and the next collect() carried both).
     *
     * @param string $col
     * @param array|null $spec  the checklist's own selection (FilterState::spec, 'in' shape)
     * @param array $ctx  see chartFilterContext()
     * @return array<int, array{id: mixed, name: mixed}>
     */
    protected function resolveCheckedOptions($col, $spec, array $ctx)
    {
        if ($spec === null || empty($spec['in'])) {
            return [];
        }
        $scope = $this->chartFilterOptionScope($col, $ctx);
        $own = array_map('strval', $spec['in']);
        if (isset($scope[$col])) {
            $df = FilterState::spec($scope[$col]);
            if ($df !== null && isset($df['in'])) {
                $own = array_values(array_intersect($own, array_map('strval', $df['in'])));
                if (empty($own)) {
                    return []; // nothing selected survives the slicer → all stale
                }
            }
            // (a checklist column is a select/text column, so a slicer on it is an IN list too;
            // any other shape is simply replaced by the selection)
        }
        $scope[$col] = ['in' => $own];
        return $ctx['builder']->columnOptions($this->custom_table, $col, $scope, null);
    }

    /**
     * The option lists of this box's chart-level filter checklists under the CURRENT request
     * (df_ / bf_ params of the box AJAX), for the lazy popover — served by
     * DashboardBoxController::chartFilterOptions. Per select-type column:
     *
     *   options   [{id, name}, ...] checked values first, then the offered values (sorted
     *             numeric-aware by the builder); on a capped column only the checked values
     *   selected  [id, ...] the currently checked ids (echo)
     *   capped    the column has more than the cardinality cap of distinct values under the
     *             scope — the UI shows the "narrow first" hint instead of a list
     *   cap       the cap in force
     *
     * Range columns are not listed (they have no option list). [] when the box has no chart
     * filters, no table, or the viewer has no permission on the table (the body would say so).
     *
     * @param string|null $only  one column name, or null for every checklist column
     * @return array<string, array{options: array, selected: string[], capped: bool, cap: int}>
     */
    // @phpstan-ignore-next-line
    public function chartFilterOptions($only = null)
    {
        if ($this->hasPermission() !== true) {
            return [];
        }
        $ctx = $this->chartFilterContext();
        if ($ctx === null) {
            return [];
        }
        $out = [];
        foreach ($ctx['columns'] as $col => $columnModel) {
            if ($only !== null && $col !== $only) {
                continue;
            }
            if (FilterState::style($columnModel) === 'range') {
                continue;
            }
            $spec = FilterState::spec($ctx['active'][$col] ?? null);
            $selectedValues = ($spec !== null && isset($spec['in'])) ? $spec['in'] : [];
            $checkedIds = array_map('strval', $selectedValues);
            $scope = $this->chartFilterOptionScope($col, $ctx);

            $options = $ctx['builder']->columnOptions($this->custom_table, $col, $scope, $ctx['cap'] + 1);
            $capped = count($options) > $ctx['cap'];
            if ($capped) {
                // over the cap: offer only the currently-selected values (clearable) — same
                // treatment as a capped filter-bar dim.
                $options = $this->resolveCheckedOptions($col, $spec, $ctx);
            }
            // checked values first, so an active selection is visible without scrolling
            usort($options, function ($a, $b) use ($checkedIds) {
                return (int) in_array((string) $b['id'], $checkedIds, true) <=> (int) in_array((string) $a['id'], $checkedIds, true);
            });
            $out[$col] = [
                'options' => array_values(array_map(function ($opt) {
                    return ['id' => (string) $opt['id'], 'name' => (string) $opt['name']];
                }, $options)),
                'selected' => $checkedIds,
                'capped' => $capped,
                'cap' => $ctx['cap'],
            ];
        }
        return $out;
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

        // Level-aware presence (chart_level_visible): the box asked not to render at this
        // drill depth. The marker div tells the dashboard box loader to hide the whole card
        // (and DashboardBoxController to skip the filter badge) instead of leaving an empty frame.
        if (!$this->isVisibleAtCurrentLevel()) {
            return '<div data-exment-box-hidden="1"></div>';
        }

        if (is_null($this->custom_view)) {
            return null;
        }

        // Renderer dispatch is centralized in ChartRendererRegistry (type → render family);
        // an unknown/legacy type falls through to the Chart.js family, as it always did.
        $family = ChartRendererRegistry::family($this->chart_type);


        // Flags for any JSON that carries user-derived TEXT (labels/categories/series/anomaly)
        // and is printed raw into a <script> via {!! !!}. HEX_* makes '<', '>', '&', quotes
        // safe (< ...) — the browser's JSON.parse restores the real characters, so labels
        // display correctly on the canvas while a "</script>" or "&" can never break out.
        $LJSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

        // AI summary strip visibility: site-wide switch ON + this dashboard opted IN
        // (「AI要約」 switch, default OFF) + table not AI-blocked. UI-side only — the
        // insight endpoint enforces the same rule server-side.
        $ai_insight = AiChatService::summaryEnabledForBox($this->dashboard_box);

        // ECharts multi-series types: pivot a 2-column aggregate view into a matrix.
        // Branch before the single-series fetch below so a multi-series box does not
        // run an extra (unused) aggregate/list query.
        if ($family === ChartRendererRegistry::FAMILY_ECHARTS_MULTI) {
            $multi = $this->getMultiSeriesData();
            if ($multi === false) {
                return exmtrans('dashboard.message.need_multiseries');
            }
            return $this->chartToolbarHtml() . $this->levelCaptionHtml() . view('exment::dashboard.chart.echart_multi', [
                'ai_insight' => $ai_insight,
                'suuid' => $this->dashboard_box->suuid,
                'chart_type' => $this->chart_type,
                'chart_height' => 300,
                'x_categories' => json_encode($multi['x_categories'], $LJSON),
                'series_names' => json_encode($multi['series_names'], $LJSON),
                // $LJSON hex flags even though the matrix is normally numeric: a list-view /
                // text-measure edge case would otherwise inject raw strings into the <script>.
                'matrix' => json_encode($multi['matrix'], $LJSON),
                'chart_axisx' => $multi['axisx_label'],
                'chart_axisy' => $multi['axisy_label'],
                'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
                'chart_colors' => json_encode($this->getChartPalette()),
                'chart_filter' => json_encode($multi['chart_filter'] ?? null, $LJSON),
            ])->render();
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

        // Deterministic anomaly markers (Power-BI-style outliers + expected-range band),
        // computed from the SAME values the chart draws so a marked point matches the AI
        // insight exactly. Only axis charts get markers — the renderers gate by type.
        $anomaly = $this->getChartAnomalies($chart_label, $chart_data);

        // ECharts-rendered types: reuse the same data, render through ECharts.
        // Toolbar order = [Filter] [Type], then the level caption.
        if ($family === ChartRendererRegistry::FAMILY_ECHARTS) {
            return $this->chartToolbarHtml()
                . $this->levelCaptionHtml()
                . view('exment::dashboard.chart.echart', [
                'ai_insight' => $ai_insight,
                'suuid' => $this->dashboard_box->suuid,
                'chart_type' => $this->chart_type,
                'chart_data' => json_encode($chart_data, $LJSON),
                'chart_labels' => json_encode($chart_label, $LJSON),
                'chart_height' => 300,
                'chart_axisx' => $axisx_label,
                'chart_axisy' => $axisy_label,
                'chart_legend' => in_array(ChartOptionType::LEGEND, $this->chart_options),
                'chart_colors' => json_encode($this->getChartPalette()),
                'anomaly' => json_encode($anomaly, $LJSON),
                'chart_drill' => json_encode((object) $this->chart_drill_urls, $LJSON),
                'chart_filter' => json_encode($result['chart_filter'] ?? null, $LJSON),
            ])->render();
        }

        // Ordinal category shading (box option chart_category_shade_views): when the current
        // (level-resolved) view is listed, bars are tinted light→dark of the base color — the
        // "before → after" one-hue-two-shades pattern for ordered categories like semesters.
        // Deliberately per-view: the same box shows nominal categories (regions, schools) at
        // other depths, where per-bar colors would be a rainbow anti-pattern.
        $chart_shades = false;
        if ($this->chart_type === 'bar' && !is_nullorempty($this->custom_view)) {
            $shadeViews = $this->config->shadeViewIds();
            $chart_shades = !empty($shadeViews) && in_array((int) $this->custom_view->id, $shadeViews, true);
        }

        // Peer-average reference line (box option chart_benchmark): the mean of the plotted
        // values, so each bar reads against the average of its peers at the current level.
        // Computed from the rendered data — no extra query. Value-axis charts only.
        //
        // The label names the GROUPING ("平均（地方）" / "Avg per region"), because this is the
        // average of the BARS, not the scope-wide per-record average. The two
        // coincide when every group holds the same number of records (as in a symmetric demo
        // tree) and diverge when they don't — naming the unit keeps that honest. Formatting
        // is 1 decimal below 1000, thousands-separated integer above, so
        // the same number never appears with two different precisions on one dashboard.
        $chart_benchmark = null;
        $chart_benchmark_label = null;
        if ($this->config->benchmark()
            && in_array($this->chart_type, ['bar', 'line'], true)) {
            $benchNums = collect($chart_data)->filter(function ($v) {
                return is_numeric($v);
            })->map(function ($v) {
                return (float) $v;
            });
            if ($benchNums->count() > 1) {
                $chart_benchmark = round($benchNums->avg(), 4);
                $chart_benchmark_label = exmtrans('dashboard.chart.peer_average', ['group' => $axisx_label])
                    . ' ' . number_format($chart_benchmark, abs($chart_benchmark) < 1000 ? 1 : 0);
            }
        }

        return $this->chartToolbarHtml()
            . $this->levelCaptionHtml()
            . view('exment::dashboard.chart.chart', [
            'ai_insight' => $ai_insight,
            'suuid' => $this->dashboard_box->suuid,
            // $LJSON: list-view Y values are raw record strings — hex-encode so they can
            // never break out of the inline <script> (same treatment as the labels).
            'chart_data' => json_encode($chart_data, $LJSON),
            'chart_labels' => json_encode($chart_label, $LJSON),
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
            'chart_color' => json_encode($this->getChartColor(count($chart_data))),
            // Chart.js markers only apply to bar/line (an "expected range" needs a value axis).
            'anomaly' => json_encode(in_array($this->chart_type, ['bar', 'line'], true) ? $anomaly : null, $LJSON),
            'chart_drill' => json_encode((object) $this->chart_drill_urls, $LJSON),
            'chart_filter' => json_encode($result['chart_filter'] ?? null, $LJSON),
            // numeric or null — the blade draws a horizontal reference line at this value,
            // captioned with the server-formatted label below
            'chart_benchmark' => json_encode($chart_benchmark),
            'chart_benchmark_label' => json_encode($chart_benchmark_label, $LJSON),
            // bool — the blade tints bars light→dark of the base color (ordered categories)
            'chart_shades' => json_encode($chart_shades),
            // ["label: value", ...] of the box's active chart-level filters → extra tooltip lines
            'chart_filter_context' => json_encode(array_map(function ($c) {
                return $c['label'] . ': ' . $c['value'];
            }, $this->boxFilterContext()), $LJSON),
        ])->render();
    }

    /**
     * Compute deterministic anomaly markers for this chart's data via the shared
     * {@see AnomalyDetector} (the SAME detector the AI insight/chat uses), trimmed to what a
     * renderer needs: the expected-range band bounds and the flagged points by data index.
     *
     * Returns null when the detector finds nothing to flag (too few points, no spread, or no
     * outliers) — the renderers then draw a plain chart. Meaningful only on value-axis charts;
     * callers decide which chart types actually paint the markers.
     *
     * @param  iterable $chart_label  category labels, index-aligned with $chart_data
     * @param  iterable $chart_data   the plotted values
     * @return array|null  {lower, upper, points:[{index, value, direction}]}
     */
    // @phpstan-ignore-next-line
    protected function getChartAnomalies($chart_label, $chart_data)
    {
        $result = AnomalyDetector::detect(
            collect($chart_label)->values()->all(),
            collect($chart_data)->values()->all()
        );
        if ($result === null) {
            return null;
        }

        return [
            'lower'  => $result['lower'],
            'upper'  => $result['upper'],
            'points' => array_map(function ($p) {
                return ['index' => $p['index'], 'value' => $p['value'], 'direction' => $p['direction']];
            }, $result['points']),
        ];
    }

    /**
     * The raw grouped-query ingredients of the per-group MEAN path (meanAggregateData):
     * the (single, same-table, non-derived) group view column + its custom column, and the
     * SQL expressions of the group and the (same-table SUM) measure. Null when the view's
     * shape doesn't fit a raw grouped query.
     *
     * @return array|null [$group, $group_column, $gexpr, $mexpr]
     */
    // @phpstan-ignore-next-line
    protected function groupQueryParts()
    {
        $view_columns = collect($this->custom_view->custom_view_columns)->values();
        if ($view_columns->count() !== 1) {
            return null;
        }
        $group = $view_columns->first();
        $group_column = $group->custom_column ?? null;
        if (is_nullorempty($group_column)
            || array_get($group, 'view_column_table_id') != $this->custom_table->id
            || !is_nullorempty(array_get($group, 'view_group_condition'))) {
            return null;
        }
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        if (is_nullorempty($view_column_y)
            || array_get($view_column_y, 'view_summary_condition') != SummaryCondition::SUM
            || array_get($view_column_y, 'view_column_table_id') != $this->custom_table->id) {
            return null;
        }
        $measure = $view_column_y->custom_column;
        if (is_nullorempty($measure)) {
            return null;
        }

        return [$group, $group_column, FilterState::columnExpr($group_column), FilterState::columnExpr($measure)];
    }

    /**
     * Resolve display labels for raw group values, the same way the filter bar does:
     * select_table ids → target record label, select/select_valtext keys → configured
     * text, otherwise the stored value is shown as-is.
     *
     * @param  \Exceedone\Exment\Model\CustomColumn $group_column
     * @param  string[] $raws
     * @return array<string,string> raw value => label
     */
    // @phpstan-ignore-next-line
    protected function resolveGroupLabels($group_column, array $raws)
    {
        $labelMap = [];
        $targetTable = $group_column->select_target_table;
        if ($targetTable) {
            foreach ($targetTable->getValueQuery()->whereIn('id', $raws)->get() as $r) {
                $labelMap[(string) $r->id] = $r->getLabel();
            }
        } else {
            $valtext = $group_column->getOption('select_item_valtext');
            if (is_string($valtext) && $valtext !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $valtext) as $line) {
                    $parts = explode(',', $line, 2);
                    if (count($parts) === 2 && trim($parts[0]) !== '') {
                        $labelMap[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
        }
        return $labelMap;
    }

    /**
     * Cross-filter payload for a chart renderer (Power-BI-style click-to-filter).
     *
     * When this box's dashboard has a filter bar (options.filter_bar) and the chart's X-axis
     * group column is one of the configured dims, the renderer gets {column, values[]} where
     * values[i] is the RAW stored value (select_table id / select key) behind data point i —
     * exactly what a `df_{column}` param compares against. Clicking a bar then just selects
     * that value on the filter bar (the blade reuses the bar's own navigate logic).
     *
     * Returns null (no cross-filter) when: no filter bar, the column isn't a configured dim,
     * the group column is cross-table (its value wouldn't exist on this box's table), or the
     * grouping is a derived bucket (date format grouping) whose value never equals the stored
     * one. Drill URLs (chart_drill_urls) always take precedence client-side.
     *
     * @param  mixed $view_column  the aggregate view's X-axis custom_view_column
     * @param  iterable $raw_values  raw group values, index-aligned with the chart's labels
     * @return array|null  {column, values[]}
     */
    // @phpstan-ignore-next-line
    protected function getChartFilter($view_column, $raw_values)
    {
        $custom_column = $view_column ? $view_column->custom_column : null;
        if (is_nullorempty($custom_column) || is_nullorempty($this->custom_table)) {
            return null;
        }
        // Same-table only: a parent/child/select_table group column's values are not stored on
        // this box's table, so a df_ equality on them could never match.
        if (array_get($view_column, 'view_column_table_id') != $this->custom_table->id) {
            return null;
        }
        // Date-format (and similar) groupings aggregate a DERIVED value ("2026-07") that never
        // equals the stored value the filter compares against.
        if (!is_nullorempty(array_get($view_column, 'view_group_condition'))) {
            return null;
        }

        return $this->chartFilterPayload($custom_column, $raw_values);
    }

    /**
     * The click-to-filter payload for a grouping COLUMN (dim-membership guard + values),
     * split out of getChartFilter() so any grouping path can build it from a bare
     * CustomColumn (same table by construction, never derived).
     *
     * @param \Exceedone\Exment\Model\CustomColumn $custom_column
     * @param mixed $raw_values
     * @return array|null
     */
    // @phpstan-ignore-next-line
    protected function chartFilterPayload($custom_column, $raw_values)
    {
        $dashboard = $this->dashboard_box ? $this->dashboard_box->dashboard : null;
        $config = $dashboard ? $dashboard->getOption('filter_bar') : null;
        $dims = is_array($config) ? array_get($config, 'dims') : null;
        if (is_nullorempty($dims) || !is_array($dims)) {
            return null;
        }
        $column_name = $custom_column->column_name;
        $is_dim = collect($dims)->contains(function ($dim) use ($column_name) {
            return array_get($dim, 'column') === $column_name;
        });
        if (!$is_dim) {
            return null;
        }

        return [
            'column' => $column_name,
            'values' => collect($raw_values)->map(function ($v) {
                return is_scalar($v) ? (string) $v : '';
            })->values()->all(),
        ];
    }

    /**
     * Dashboard filter bar — apply the dashboard-level filter to this box's base query.
     *
     * Generic (NOT domain-specific): the filter bar (filter_bar.blade.php, configured per
     * dashboard via options.filter_bar) puts each selected value on the request as a `df_{column}`
     * param, forwarded to every box's AJAX request. Here we AND an equality where for each
     * `df_{column}` param whose {column} ACTUALLY EXISTS on this box's own table — so a box on the
     * filtered fact table narrows, while a box on an unrelated table (no such column) is left
     * untouched. No column names or table names are hard-coded here; the education demo is just
     * one dashboard whose options.filter_bar happens to list region/prefecture/city/school.
     *
     * Called right after getValueQuery() on every data path (list, aggregate, multi-series)
     * so filter, chart, and AI insight all see the same filtered rows.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $model
     * @param  string[] $except  column names whose df_ param should NOT be applied (parent-
     *                           scope queries: all filters minus one dim)
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function applyDashboardFilter($model, array $except = [])
    {
        // box-aware since chart-level filters exist: FilterState also ANDs this box's own
        // bf_ filters and skips df_ dims whose `targets` exclude this box.
        FilterState::applyTo($model, $this->custom_table, $except, $this->dashboard_box);
    }

    /**
     * Scope benchmark for the AI layer ("how does the current selection compare?").
     *
     * When the dashboard filter bar narrows this chart, compute the per-record MEAN of the
     * chart's SUM measure at three scopes — the current selection, one level up (the same
     * filters minus the DEEPEST hierarchy dim), and the whole table — so the AI can answer
     * "compared to the parent / national level?" with real numbers instead of guessing or
     * refusing. Deterministic SQL only, no LLM; ~3 indexed aggregate queries.
     *
     * Returns null when there is nothing meaningful to compare: no dashboard filter active
     * on this box's table (the chart already IS the overall scope), a non-SUM or cross-table
     * measure (per-record means of COUNT/MIN/MAX are not comparable across scopes), a
     * non-aggregate view, or a view carrying its own static filters (raw re-aggregation
     * would silently disagree with what the chart shows).
     *
     * @return array|null {measure_label, filtered_by:[dim labels], dropped:?dim label,
     *                     current:{mean,count}, parent:?{mean,count}, overall:{mean,count}}
     */
    // @phpstan-ignore-next-line
    public function getBenchmarkData()
    {
        if ($this->hasPermission() !== true) {
            return null;
        }
        // Data-egress gate: benchmark numbers are computed solely to be fed to the LLM.
        if (!AiChatService::aiAllowedForBox($this->dashboard_box)) {
            return null;
        }
        if (is_nullorempty($this->custom_table) || is_nullorempty($this->custom_view)) {
            return null;
        }
        if (array_get($this->custom_view, 'view_kind_type') != ViewKindType::AGGREGATE) {
            return null;
        }
        if (count($this->custom_view->custom_view_filters ?? []) > 0) {
            return null;
        }
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        if (is_nullorempty($view_column_y)
            || array_get($view_column_y, 'view_summary_condition') != SummaryCondition::SUM
            || array_get($view_column_y, 'view_column_table_id') != $this->custom_table->id) {
            return null;
        }
        $measure = $view_column_y->custom_column;
        if (is_nullorempty($measure)) {
            return null;
        }

        // Active df_ selections that really apply to this table — the SAME guards (and now
        // the same slicer-targeting gate) as applyDashboardFilter, so the benchmark scopes
        // always match the chart's filtering.
        $columns = $this->custom_table->custom_columns->keyBy('column_name');
        $applied = FilterState::columnsOn($this->custom_table, $this->dashboard_box);
        if (empty($applied)) {
            return null;
        }

        // Chart-level filters are part of this box's identity (like a view's static filter):
        // they narrow EVERY scope — current, parent AND overall — so the comparison stays
        // apples-to-apples. Only columns really on this table count (boxFilters guarantees
        // the declaration; membership re-checked for safety).
        $box_filters = [];
        foreach (FilterState::boxFilters($this->dashboard_box) as $col => $val) {
            if (!is_nullorempty($columns->get($col))) {
                $box_filters[$col] = $val;
            }
        }

        // Deepest applied hierarchy dim (per the dashboard's filter bar config, declared
        // root→leaf): dropping it yields the parent scope. Cross-cut dims (no parent link)
        // never define the parent level.
        $dashboard = $this->dashboard_box ? $this->dashboard_box->dashboard : null;
        $config = $dashboard ? $dashboard->getOption('filter_bar') : null;
        $dims = is_array($config) ? (array) array_get($config, 'dims') : [];
        // chain membership from the EFFECTIVE parents (explicit or metadata-inferred)
        $barConfig = FilterBarConfig::fromDashboard($dashboard);
        $dimLabels = [];
        $deepest = null;
        foreach ($dims as $dim) {
            $col = array_get($dim, 'column');
            if (is_nullorempty($col)) {
                continue;
            }
            $dimLabels[$col] = array_get($dim, 'label', $col);
            $isChain = $barConfig ? $barConfig->isChainColumn($col) : false;
            if ($isChain && array_key_exists($col, $applied)) {
                $deepest = $col;
            }
        }

        // SUM + COUNT of the measure under a filter set, on the physical table via the
        // generated index columns (validated identifiers only — same safety model as
        // applyDashboardFilter). COUNT($expr) skips null measures, matching the chart's SUM.
        $aggregate = function (array $filters) use ($measure, $columns) {
            $query = \DB::table(getDBTableName($this->custom_table))->whereNull('deleted_at');
            foreach ($filters as $col => $val) {
                // same SQL rule as the chart's own query (single value, IN list, range)
                FilterState::where($query, $columns->get($col), $col, $val);
            }
            $mexpr = FilterState::columnExpr($measure);
            $row = $query->selectRaw("SUM(CAST({$mexpr} AS DECIMAL(20,4))) as s, COUNT({$mexpr}) as c")->first();
            $count = (int) ($row->c ?? 0);
            return $count > 0 ? ['mean' => (float) $row->s / $count, 'count' => $count] : null;
        };

        // df wins a same-column collision with bf in these equality sets ($applied left of +):
        // the chart itself would show the intersection (usually empty) — a degenerate state
        // the numbers can't make more honest anyway.
        $current = $aggregate($applied + $box_filters);
        $overall = $aggregate($box_filters);
        if ($current === null || $overall === null) {
            return null;
        }
        $parent = null;
        if ($deepest !== null) {
            $parentFilters = $applied;
            unset($parentFilters[$deepest]);
            if (!empty($parentFilters)) {
                $parent = $aggregate($parentFilters + $box_filters);
            }
            // when the deepest dim was the ONLY filter, the parent scope IS the overall one —
            // leave parent null instead of repeating the same number twice.
        }

        return [
            'measure_label' => array_get($view_column_y, 'view_column_name') ?? $view_column_y->column_item->label(),
            'filtered_by'   => array_values(array_map(function ($c) use ($dimLabels) {
                return $dimLabels[$c] ?? $c;
            }, array_keys($applied))),
            'dropped'       => $deepest !== null ? ($dimLabels[$deepest] ?? $deepest) : null,
            'current'       => $current,
            'parent'        => $parent,
            'overall'       => $overall,
        ];
    }

    /**
     * Get the chart's real rendered data for AI analysis.
     * Works for both aggregate and list(default) views; returns exactly what the
     * chart shows. Returns null when the user lacks permission or there is no data.
     *
     * @return array|null  {title, chart_type, axis_x_label, axis_y_label, labels, values, is_aggregate}
     */
    // @phpstan-ignore-next-line
    public function getInsightData()
    {
        if ($this->hasPermission() !== true) {
            return null;
        }
        // Data-egress gate (blocked table / dashboard AI opt-out): this payload exists only
        // to be sent to the LLM (insight + chat context), so refuse at the source too —
        // defense in depth on top of the endpoint gates.
        if (!AiChatService::aiAllowedForBox($this->dashboard_box)) {
            return null;
        }
        if (is_null($this->custom_view)) {
            return null;
        }

        // Aggregate views yield computed measures (sum/count/avg); list(default) views
        // yield raw per-record Y values. AiChatService uses this flag to scan raw values
        // for PII more strictly than aggregated measures.
        $is_aggregate = array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE;

        if ($is_aggregate) {
            $result = $this->getAggregateData();
        } else {
            $result = $this->getListData();
        }

        if ($result === false) {
            return null;
        }

        $labels = collect($result['chart_label'])->values()->map(function ($v) {
            return is_scalar($v) ? (string) $v : $v;
        })->all();
        $values = collect($result['chart_data'])->values()->map(function ($v) {
            return is_numeric($v) ? floatval($v) : $v;
        })->all();

        return [
            'title'        => array_get($this->dashboard_box, 'dashboard_box_view_name'),
            'chart_type'   => $this->chart_type,
            'axis_x_label' => $result['axisx_label'],
            'axis_y_label' => $result['axisy_label'],
            'labels'       => $labels,
            'values'       => $values,
            'is_aggregate' => $is_aggregate,
        ];
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

        $this->applyDashboardFilter($model);
        $this->custom_view->filterModel($model);

        // get data
        $items = $model->get();

        $chart_label = $items->map(function ($val) use ($view_column_x) {
            // if get as CHARTITEM_LABEL, return label.
            if ($view_column_x == Define::CHARTITEM_LABEL) {
                return $val->getLabel();
            }
            // Raw plain text (NOT html-escaped): these labels are drawn on a <canvas> by
            // Chart.js/ECharts and are also fed to the AI insight — html-escaping here would
            // show literal "&amp;". XSS is handled where the JSON reaches the DOM (hex-encoded).
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

        // Mean display (box option chart_value_mean): per-group AVG of the measure instead of
        // the view's SUM — Exment summary views have no AVG condition, so this is the supported
        // way to chart averages. Falls through to the normal SUM path when the shape doesn't fit.
        $mean = $this->meanAggregateData();
        if ($mean !== null) {
            return $mean;
        }

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
        $this->applyDashboardFilter($query);

        // get data
        $datalist = $this->custom_view->getQuery($query)->get();
        $chart_label = $datalist->map(function ($val) use ($item_x_list) {
            $labels = $item_x_list->map(function ($item_x) use ($val) {
                $item = $item_x->setCustomValue($val);
                // Raw plain text — see getListData(): canvas-drawn + AI-consumed; the JSON is
                // hex-encoded before it reaches the DOM, so removing esc_html is XSS-safe.
                return $item->text();
            });
            return $labels->implode(' ');
        });
        $chart_data = $datalist->pluck($item_y->uniqueName());

        // Raw stored group values, index-aligned with chart_label/chart_data. Exposed
        // regardless of the cross-filter guards (click-to-filter and the mean path key
        // their queries on them even when the group column is not a filter dim).
        $chart_raw_x = null;
        if (count($view_column_x_list) === 1) {
            $chart_raw_x = $datalist->pluck($item_x_list->first()->uniqueName());
        }

        // Value-descending sort (chart_sort_value; single group column only — a compound label
        // has no one value to rank by) + top-N cut (chart_max_groups). Label / data / raw stay
        // index-aligned; the cross-filter payload below is built from the kept groups only.
        $order = $this->groupOrder(collect($chart_data)->values()->all(), count($view_column_x_list) === 1);
        if ($order !== null) {
            $chart_label = collect(static::pickIndexes(collect($chart_label)->values()->all(), $order));
            $chart_data = collect(static::pickIndexes(collect($chart_data)->values()->all(), $order));
            if ($chart_raw_x !== null) {
                $chart_raw_x = collect(static::pickIndexes(collect($chart_raw_x)->values()->all(), $order));
            }
        }

        // Cross-filter payload: only for a SINGLE group column (a compound label spans several
        // dims — clicking it has no single filter value). Raw values come from the summary
        // query's group alias (the item's uniqueName, `ckey_<view column suuid>`), which selects
        // the stored value the df_ filter compares against.
        $chart_filter = null;
        if (count($view_column_x_list) === 1) {
            $chart_filter = $this->getChartFilter(
                collect($view_column_x_list)->first(),
                $chart_raw_x
            );
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
            'chart_filter'  => $chart_filter,
            'chart_raw_x'   => $chart_raw_x,
        ];
    }

    /**
     * Display order of a chart's groups — the SUM and mean paths share this so both honour the
     * same options: chart_sort_value = 'desc' (descending by value; views in
     * chart_sort_natural_views keep their natural order) then chart_max_groups (keep the first
     * N groups after that sort — the "top 10" chart). Returns the indexes of $values to keep,
     * in display order, or null when neither option changes anything (arrays stay as they are).
     * Non-numeric values sort last. Callers re-index every array aligned with $values
     * (labels, raw keys, ...) with pickIndexes().
     *
     * @param array $values     0-based list of the plotted values
     * @param bool  $allowSort  false = this shape cannot be ranked (compound group label)
     * @return int[]|null
     */
    protected function groupOrder(array $values, $allowSort = true)
    {
        $idx = array_keys($values);
        $sort = $allowSort && $this->config->sortsByValue($this->custom_view ? $this->custom_view->id : null);
        $max = $this->config->maxGroups();
        if (!$sort && ($max <= 0 || count($idx) <= $max)) {
            return null;
        }
        if ($sort) {
            usort($idx, function ($a, $b) use ($values) {
                $av = is_numeric($values[$a] ?? null) ? (float) $values[$a] : -INF;
                $bv = is_numeric($values[$b] ?? null) ? (float) $values[$b] : -INF;
                return $bv <=> $av;
            });
        }
        if ($max > 0 && count($idx) > $max) {
            $idx = array_slice($idx, 0, $max);
        }
        return $idx;
    }

    /**
     * @param array $items  0-based list
     * @param int[] $order  indexes to keep, in order (groupOrder)
     * @return array
     */
    protected static function pickIndexes(array $items, array $order)
    {
        return array_values(array_map(function ($i) use ($items) {
            return $items[$i];
        }, $order));
    }

    /**
     * Per-group MEAN chart data (box option chart_value_mean) — one raw index-backed query:
     * AVG(CAST(measure)) grouped by the view's single group column, dashboard df_ filters +
     * the view's own static filters applied (Exment summary views have no AVG condition).
     * Labels resolve like the filter bar (select_table record label / valtext), natural
     * numeric-aware order, then the same chart_sort_value / chart_sort_natural_views
     * contract as the SUM path. Everything downstream (anomaly markers, benchmark line,
     * click-to-filter, AI insight) consumes the result unchanged — it sees averages.
     *
     * Returns null (→ caller falls back to the SUM path) when the option is off or the view
     * shape doesn't fit a raw grouped mean (multi-column grouping, cross-table or
     * non-SUM measure, derived bucket grouping).
     *
     * @return array|null  same shape as getAggregateData()
     */
    // @phpstan-ignore-next-line
    protected function meanAggregateData()
    {
        if (!$this->config->valueMean()) {
            return null;
        }
        $parts = $this->groupQueryParts();
        if ($parts === null) {
            return null;
        }
        list($group, $group_column, $gexpr, $mexpr) = $parts;

        $model = $this->custom_table->getValueQuery();
        $this->applyDashboardFilter($model);
        $this->custom_view->filterModel($model);

        // `gk + 0, gk` = numeric-aware natural order (ids sort 1,2,10; text falls back to
        // lexicographic) — matching the option-list ordering of the filter bar.
        $datalist = $model
            ->whereRaw("{$gexpr} IS NOT NULL AND {$gexpr} <> ''")
            ->selectRaw("{$gexpr} as gk, AVG(CAST({$mexpr} AS DECIMAL(20,4))) as avgv")
            ->groupBy(\DB::raw($gexpr))
            ->orderByRaw('gk + 0, gk')
            ->get();

        $raws = [];
        $values = [];
        foreach ($datalist as $row) {
            $key = (string) array_get($row, 'gk');
            if ($key === '') {
                continue;
            }
            $raws[] = $key;
            $avg = array_get($row, 'avgv');
            $values[] = is_numeric($avg) ? round((float) $avg, 1) : null;
        }

        // value-desc sort + top-N cut: same options / contract as the SUM path
        $order = $this->groupOrder($values, true);
        if ($order !== null) {
            $raws = static::pickIndexes($raws, $order);
            $values = static::pickIndexes($values, $order);
        }

        $labelMap = $this->resolveGroupLabels($group_column, $raws);
        $labels = [];
        foreach ($raws as $raw) {
            $labels[] = (string) ($labelMap[$raw] ?? $raw);
        }

        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);
        $measureLabel = array_get($view_column_y, 'view_column_name') ?? $view_column_y->column_item->label();

        return [
            'chart_data'   => collect($values),
            'chart_label'  => collect($labels),
            'axisx_label'  => array_get($group, 'view_column_name') ?? $group_column->column_item->label(),
            'axisy_label'  => $measureLabel . ' (' . exmtrans('dashboard.chart.mean') . ')',
            'chart_filter' => $this->getChartFilter($group, $raws),
            'chart_raw_x'  => collect($raws),
        ];
    }

    /**
     * Build pivoted multi-series data from a 2-column aggregate view.
     * Group-by column #1 => X axis categories, column #2 => series (legend),
     * the summary measure (chart_axisy) => each cell value.
     *
     * Returns false when the view is not aggregate, has fewer than 2 group-by
     * columns, or has no measure selected.
     *
     * @return array|false {x_categories[], series_names[], matrix[seriesIdx][xIdx], axisx_label, axisy_label}
     */
    // @phpstan-ignore-next-line
    protected function getMultiSeriesData()
    {
        if (array_get($this->custom_view, 'view_kind_type') != ViewKindType::AGGREGATE) {
            return false;
        }

        $view_column_x_list = collect($this->custom_view->custom_view_columns)->values();
        $view_column_y = CustomViewSummary::getSummaryViewColumn($this->axis_y);

        // need at least 2 group-by columns (X + series) and a measure
        if ($view_column_x_list->count() < 2 || is_nullorempty($view_column_y)) {
            return false;
        }

        // resolve which group-by column is the "series" (legend) split. chart_series
        // holds a "0_{id}" key; fall back to the 2nd column for boxes saved before
        // the selector existed.
        $series_pos = 1;
        if (!is_nullorempty($this->chart_series)) {
            foreach ($view_column_x_list as $pos => $col) {
                if ((ViewKindType::DEFAULT . '_' . $col->id) === $this->chart_series) {
                    $series_pos = $pos;
                    break;
                }
            }
        }
        // X axis = first group-by column that is not the series column
        $x_pos = null;
        foreach ($view_column_x_list as $pos => $col) {
            if ($pos !== $series_pos) {
                $x_pos = $pos;
                break;
            }
        }
        if ($x_pos === null) {
            return false;
        }

        // mirror getAggregateData()'s summary item setup for the group-by columns
        $item_list = $view_column_x_list->map(function ($item) {
            $summary_index = ViewKindType::DEFAULT . '_' . $item->id;
            return $item->column_item->options([
                'summary' => true,
                'summary_index' => $summary_index,
            ]);
        });
        $item_x = $item_list[$x_pos];
        $item_series = $item_list[$series_pos];
        $item_y = $view_column_y->column_item;

        $query = $this->custom_table->getValueQuery();
        $this->applyDashboardFilter($query);
        $datalist = $this->custom_view->getQuery($query)->get();

        // extract three parallel arrays: x text, series text, measure value
        // Raw plain text (see getListData): rendered on an ECharts canvas + fed to the AI;
        // the JSON is hex-encoded in body() before it reaches the DOM, so this is XSS-safe.
        $x_texts = $datalist->map(function ($val) use ($item_x) {
            return $item_x->setCustomValue($val)->text();
        })->all();
        $series_texts = $datalist->map(function ($val) use ($item_series) {
            return $item_series->setCustomValue($val)->text();
        })->all();
        $y_values = $datalist->pluck($item_y->uniqueName())->all();

        // unique categories / series, preserving first-seen order. STRICT so numeric-looking
        // labels ("7" vs "007" vs "7.0", or null vs "") stay distinct instead of loosely merging.
        $x_categories = collect($x_texts)->unique(null, true)->values();
        $series_names = collect($series_texts)->unique(null, true)->values();

        // Raw stored value behind each X category (first-seen row per category), for the
        // cross-filter payload — index-aligned with $x_categories. The summary query aliases
        // each group column as the item's uniqueName (`ckey_<view column suuid>`).
        $x_raws = $datalist->pluck($item_x->uniqueName())->all();
        $x_raw_by_idx = [];

        // dense matrix [seriesIndex][xIndex], missing combos default to 0
        $matrix = [];
        foreach ($series_names as $s_idx => $s) {
            $matrix[$s_idx] = array_fill(0, $x_categories->count(), 0);
        }
        for ($i = 0; $i < count($y_values); $i++) {
            $x_idx = $x_categories->search($x_texts[$i], true);
            $s_idx = $series_names->search($series_texts[$i], true);
            if ($x_idx === false || $s_idx === false) {
                continue;
            }
            if (!array_key_exists($x_idx, $x_raw_by_idx)) {
                $x_raw_by_idx[$x_idx] = $x_raws[$i] ?? null;
            }
            // Accumulate (+=), not assign: an aggregate view grouped by 3+ columns — or by 2
            // columns whose values render to identical text — produces multiple rows per
            // (x, series) cell; assigning would keep only the last and understate the total.
            $matrix[$s_idx][$x_idx] += is_numeric($y_values[$i]) ? floatval($y_values[$i]) : 0;
        }

        $chart_filter = $this->getChartFilter(
            $view_column_x_list[$x_pos],
            $x_categories->keys()->map(function ($idx) use ($x_raw_by_idx) {
                return $x_raw_by_idx[$idx] ?? null;
            })
        );

        $axisx_label = array_get($view_column_x_list[$x_pos], 'view_column_name') ?? $item_x->label();
        $axisy_label = array_get($view_column_y, 'view_column_name') ?? $item_y->label();

        return [
            'x_categories' => $x_categories->all(),
            'series_names' => $series_names->all(),
            'matrix'       => $matrix,
            'axisx_label'  => $axisx_label,
            'axisy_label'  => $axisy_label,
            'chart_filter' => $chart_filter,
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
                    // chart-level filter fields follow the table (they are its columns)
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

        // series column: only used by multi-series charts. Toggled by JS below.
        $form->select('chart_series', exmtrans("dashboard.dashboard_box_options.chart_series"))
            ->help(exmtrans('dashboard.message.need_multiseries'))
            ->options(function ($value, $model) {
                $target_view_id = array_get(request()->all(), 'options.target_view_id') ?? array_get($model->data(), 'target_view_id');
                if (!isset($target_view_id)) {
                    return [];
                }

                $custom_view = CustomView::getEloquent($target_view_id);
                if (!isset($custom_view)) {
                    return [];
                }

                // tên lớp viết đầy đủ: laravel-admin gọi closure này với scope của MODEL
                // (DashboardBox), nên static:: / self:: sẽ trỏ nhầm lớp và gây lỗi.
                return array_column(ChartItem::seriesSelectOptions($custom_view), 'text', 'id');
            });

        // Shared option builder: columns of the currently chosen table, keyed by COLUMN NAME
        // (template-portable). Used by the chart-filter, dimension-pool and dimension-default
        // selects; all three re-point live via the linkage on the table select above.
        $tableColumnOptions = function ($value, $model) {
            $target_table_id = array_get(request()->all(), 'options.target_table_id') ?? array_get($model->data(), 'target_table_id');
            if (!isset($target_table_id)) {
                return [];
            }
            $custom_table = CustomTable::getEloquent($target_table_id);
            if (!isset($custom_table)) {
                return [];
            }
            $options = [];
            foreach ($custom_table->custom_columns as $custom_column) {
                $options[$custom_column->column_name] = $custom_column->column_view_name . ' (' . $custom_column->column_name . ')';
            }
            return $options;
        };

        // Chart-level filter fields: rendered as small selects above the chart; each narrows
        // ONLY this box (bf_{column} on the box AJAX) — WHERE, never GROUP BY.
        $form->multipleSelect('chart_filters', exmtrans("dashboard.dashboard_box_options.chart_filters"))
            ->options($tableColumnOptions)
            ->help(exmtrans("dashboard.dashboard_box_options.chart_filters_help"));

        // Ordering + top-N: sort the groups by value (descending) and/or keep only the first N
        // groups — together they turn an ordinary bar chart into a "top 10" ranking that still
        // follows the dashboard filter, targeting and the chart-level filter like any chart.
        $form->select('chart_sort_value', exmtrans("dashboard.dashboard_box_options.chart_sort_value"))
            ->options(['desc' => exmtrans("dashboard.dashboard_box_options.chart_sort_value_desc")])
            ->help(exmtrans("dashboard.dashboard_box_options.chart_sort_value_help"));
        $form->number('chart_max_groups', exmtrans("dashboard.dashboard_box_options.chart_max_groups"))
            ->min(0)->max(500)
            ->help(exmtrans("dashboard.dashboard_box_options.chart_max_groups_help"));


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


        $legendTypesJson = json_encode(ChartType::legendTypes());
        $multiTypesJson = json_encode(ChartType::multiSeriesTypes());
        $script = <<<EOT
        // types showing the "legend" option (circular + multi-series); the rest show "begin at zero"
        var exmentLegendCharts = $legendTypesJson;
        // types needing a 2nd group-by column split into series (show the series select)
        var exmentMultiCharts = $multiTypesJson;
        function setChartOptions(val) {
            if (exmentLegendCharts.indexOf(val) >= 0) {
                $('#chart_options > .icheck:nth-child(1)').show();
                $('#chart_options > .icheck:nth-child(2)').hide();
            } else {
                $('#chart_options > .icheck:nth-child(1)').hide();
                $('#chart_options > .icheck:nth-child(2)').show();
            }
            // series column select: only for multi-series charts
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
        $new_options = [];
        if (ChartType::isCircular($chart_type) || ChartType::isMultiSeries($chart_type)) {
            if (ChartType::isCircular($chart_type)) {
                $options['chart_axis_label'] = [];
                $options['chart_axis_name'] = [];
            }
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

        // chart_filters: a multipleSelect with nothing picked submits NO value, and the
        // merge below would then resurrect the stored list — but this form ALWAYS exposes
        // the field, so absence means "cleared by the user". Normalize to plain column-name
        // strings; keep the (possibly empty) key only when the user picked something or the
        // box HAD the key (a real clear) — a box that never used the feature stays pristine,
        // so pre-existing boxes' stored options don't grow a stray empty key on every save.
        $chart_filters = array_values(array_filter(
            (array) array_get($options, 'chart_filters', []),
            function ($c) {
                return FilterState::isIdentifier($c);
            }
        ));
        $stored_model = $form->model();
        $had_chart_filters = $stored_model && $stored_model->exists && is_array($stored_model->options)
            && array_key_exists('chart_filters', $stored_model->options);
        if (count($chart_filters) || $had_chart_filters) {
            $options['chart_filters'] = $chart_filters;
        } else {
            unset($options['chart_filters']);
        }

        // chart_sort_value / chart_max_groups: a cleared select / empty number means "off". Keep
        // the key (as null) only when the box HAD it — an explicit clear must not be resurrected
        // by the stored-key merge below — and never add a stray key to a pristine box.
        foreach (['chart_sort_value' => function ($v) { return $v === 'desc' ? 'desc' : null; },
                  'chart_max_groups' => function ($v) { return (is_numeric($v) && (int) $v > 0) ? (int) $v : null; }] as $key => $normalize) {
            $value = $normalize(array_get($options, $key));
            $had = $stored_model && $stored_model->exists && is_array($stored_model->options)
                && array_key_exists($key, $stored_model->options);
            if ($value !== null || $had) {
                $options[$key] = $value;
            } else {
                unset($options[$key]);
            }
        }

        // Preserve option keys this form does not expose (chart_level_views, chart_pinned_views,
        // chart_level_max_groups, ...): laravel-admin's embeds REPLACES the whole options JSON
        // with just the form's own fields, silently wiping anything written by seeds / feature
        // code. At saving time the model still holds the stored values, so merge back every key
        // the submitted form does not carry. A key the form carries — even cleared/empty — is
        // the user's edit and always wins.
        $model = $form->model();
        if ($model && $model->exists && is_array($model->options)) {
            foreach ($model->options as $key => $value) {
                if (!array_key_exists($key, $options)) {
                    $options[$key] = $value;
                }
            }
            // The merge above is defeated in the REAL admin flow: laravel-admin runs the
            // embeds field's prepare (which strips undeclared keys) AFTER this callback.
            // Arm the model-level one-shot guard (DashboardBox::boot) that re-merges the
            // stored keys at Eloquent save time, where the original JSON is still at hand.
            $model->mergeStoredOptions = true;
        }

        $form->options = $options;
    }

    /**
     * get chart color array.
     *
     * @return array Chart color array
     */
    // @phpstan-ignore-next-line
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

    /**
     * Get a color palette (array) for ECharts charts.
     * Falls back to a built-in multi-color palette so ECharts charts look good
     * even when only a single config color is set.
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    protected function getChartPalette()
    {
        $chart_color = config('exment.chart_backgroundColor');
        // guard null before stringToArray (config may be unset) — avoids a PHP 8.1+
        // deprecation on string ops over null; empty falls through to the palette below.
        $chart_color = is_nullorempty($chart_color) ? [] : stringToArray($chart_color);

        // need at least a few colors for circular/multi-slice charts
        if (count($chart_color) < 3) {
            return [
                '#5b8ff9', '#5ad8a6', '#5d7092', '#f6bd16', '#e8684a',
                '#6dc8ec', '#9270ca', '#ff9d4d', '#269a99', '#ff99c3',
            ];
        }
        return $chart_color;
    }

    /**
     * Select options for the "series" column of a multi-series chart: the group-by columns
     * of an AGGREGATE view, each offered with the same "0_{id}" key the chart pivot uses
     * (a non-aggregate view has no group-by columns to split into series → []).
     *
     * @param  CustomView|null $custom_view
     * @return array<int, array{id: string, text: string|null}>
     */
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

    // @phpstan-ignore-next-line
    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
