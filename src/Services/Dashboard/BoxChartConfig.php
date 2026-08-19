<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * Typed view of a chart dashboard-box's `options.*` — THE place that knows the chart
 * option schema. Every key is generic (view ids, column names, flags); nothing is
 * domain-specific. Storage format unchanged: this class only reads the same JSON
 * `options` the box always had, so every existing box keeps working untouched.
 *
 * Full key schema (every key has a getter here — ChartItem never reads options.chart_* raw):
 *
 *   target_table_id / target_view_id      base table + configured view
 *   chart_type                            renderer type (see ChartType / renderer registry)
 *   chart_axisx / chart_axisy             group / measure ("{1=summary|0=column}_{id}")
 *   chart_series                          series column (multi-series types)
 *   chart_options[]                       LEGEND / BEGIN_ZERO flags (ChartOptionType)
 *   chart_axis_label[] / chart_axis_name[]  X/Y axis label + name toggles (ChartAxisType)
 *   chart_drill_urls{label=>url}          click-through navigation map
 *   chart_level_views{depth=>view_id}     drill: swap view per filter-bar depth ('' = root)
 *   chart_level_visible[depth,...]        drill: render only at the listed depths
 *   chart_level_max_groups                drill: refuse a swap that would exceed N groups
 *   chart_level_hint                      opt-in caption naming the refused breakdown
 *   chart_hide_when_capped                hide the box entirely when the cap refused
 *   chart_pinned_views{column=>view_id}   degenerate-chart swap while df_{column} pinned
 *   chart_hide_when_pinned[column,...]    hide the box while any listed dim is pinned
 *   chart_category_shade_views[view_id]   tint single-series bars light→dark on these views
 *   chart_benchmark                       peer-average reference line (bar/line)
 *   chart_sort_value / chart_sort_natural_views   value sort + natural-order exceptions
 *   chart_max_groups                      keep only the first N groups after that sort (top N)
 *   chart_value_mean                      plot per-group MEAN instead of SUM
 *   chart_type_lock                       hide the runtime chart-type switcher on this box
 *   chart_filters[column,...]             chart-level filter fields (bf_{column} params)
 *
 * Each getter reproduces its original call site's default EXACTLY (`?? []`,
 * `(int) array_get(..., 0)`, boolval, ...) so behavior cannot drift.
 */
class BoxChartConfig
{
    /** @var mixed DashboardBox model (array access via array_get) */
    protected $box;

    protected function __construct($dashboard_box)
    {
        $this->box = $dashboard_box;
    }

    /** @param mixed $dashboard_box @return static */
    public static function of($dashboard_box)
    {
        return new static($dashboard_box);
    }

    /** @return mixed */
    protected function raw($key, $default = null)
    {
        return array_get($this->box, 'options.' . $key, $default);
    }

    // ---- identity ---------------------------------------------------------

    /** @return mixed */
    public function targetTableId()
    {
        return $this->raw('target_table_id');
    }

    /** @return mixed */
    public function targetViewId()
    {
        return $this->raw('target_view_id');
    }

    // ---- presentation -----------------------------------------------------

    /** @return string|null */
    public function chartType()
    {
        return $this->raw('chart_type');
    }

    /** @return mixed */
    public function axisX()
    {
        return $this->raw('chart_axisx');
    }

    /** @return mixed */
    public function axisY()
    {
        return $this->raw('chart_axisy');
    }

    /** @return mixed */
    public function series()
    {
        return $this->raw('chart_series');
    }

    /** @return array */
    public function chartOptions()
    {
        return $this->raw('chart_options') ?? [];
    }

    /** @return array */
    public function axisLabel()
    {
        return $this->raw('chart_axis_label') ?? [];
    }

    /** @return array */
    public function axisName()
    {
        return $this->raw('chart_axis_name') ?? [];
    }

    /** @return array label => url */
    public function drillUrls()
    {
        return $this->raw('chart_drill_urls') ?? [];
    }

    /** @return bool peer-average reference line on bar/line charts */
    public function benchmark()
    {
        return boolval($this->raw('chart_benchmark'));
    }

    /** @return bool hide the runtime chart-type switcher on this box */
    public function typeLock()
    {
        return boolval($this->raw('chart_type_lock'));
    }

    /** @return bool plot the per-group MEAN of the measure instead of the view's SUM */
    public function valueMean()
    {
        return boolval($this->raw('chart_value_mean'));
    }

    /**
     * Whether the groups of $viewId are ordered by value, descending (chart_sort_value = 'desc'),
     * except for the views listed in chart_sort_natural_views — ordinal categories (grades,
     * semesters) that keep their natural order even when the sort is on.
     *
     * @param mixed $viewId
     * @return bool
     */
    public function sortsByValue($viewId)
    {
        if ($this->raw('chart_sort_value') !== 'desc') {
            return false;
        }
        $natural = array_map('intval', (array) ($this->raw('chart_sort_natural_views') ?? []));
        return !in_array((int) $viewId, $natural, true);
    }

    /**
     * Top-N groups: after the box's own ordering (chart_sort_value = desc → by value), keep
     * only the first N groups — the "top 10 students" chart. 0 / absent = every group.
     *
     * @return int
     */
    public function maxGroups()
    {
        $n = $this->raw('chart_max_groups');
        return (is_numeric($n) && (int) $n > 0) ? (int) $n : 0;
    }

    /**
     * Chart-level filter fields: column names of the box's own table, rendered as small
     * selects above the chart, each narrowing ONLY this box (bf_{column} request params).
     * Sanitized to plain identifiers here so nothing else re-validates the shape.
     *
     * @return string[]
     */
    public function filters()
    {
        $cols = $this->raw('chart_filters');
        if (!is_array($cols)) {
            return [];
        }
        return array_values(array_filter($cols, function ($c) {
            return FilterState::isIdentifier($c);
        }));
    }

    /**
     * View ids whose single-series bars are tinted light→dark (ordered categories).
     *
     * @return int[]
     */
    public function shadeViewIds()
    {
        return array_map('intval', (array) ($this->raw('chart_category_shade_views') ?? []));
    }

    // ---- drill / level behavior ------------------------------------------

    /** @return array depth ('' = root) => view id; [] when unconfigured */
    public function levelViews()
    {
        $map = $this->raw('chart_level_views');
        return is_array($map) ? $map : [];
    }

    /** @return array depths this box renders at; [] = always visible */
    public function levelVisible()
    {
        $visible = $this->raw('chart_level_visible');
        return is_array($visible) ? $visible : [];
    }

    /** @return int 0 = unlimited */
    public function levelMaxGroups()
    {
        return (int) $this->raw('chart_level_max_groups', 0);
    }

    /** @return bool opt-in hint caption when the readability cap refused a swap */
    public function levelHintEnabled()
    {
        return boolval($this->raw('chart_level_hint'));
    }

    /** @return bool hide the whole box when the readability cap refused a swap */
    public function hideWhenCapped()
    {
        return boolval($this->raw('chart_hide_when_capped'));
    }

    /** @return array dim column => view id; [] when unconfigured */
    public function pinnedViews()
    {
        $map = $this->raw('chart_pinned_views');
        return is_array($map) ? $map : [];
    }

    /** @return array dim columns that hide this box while pinned; [] when unconfigured */
    public function hideWhenPinned()
    {
        $cols = $this->raw('chart_hide_when_pinned');
        return is_array($cols) ? $cols : [];
    }
}
