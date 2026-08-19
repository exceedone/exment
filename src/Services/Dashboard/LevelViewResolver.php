<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomView;

/**
 * Decides which VIEW a chart box renders for the current dashboard-filter state —
 * the drill-down brain of the filter bar, extracted 1:1 from ChartItem:
 *
 *  1. Level swap (box option chart_level_views): map "deepest selected hierarchy dim"
 *     → view, walking down through single-child levels (a one-bar comparison says
 *     nothing) and refusing any depth whose group count would exceed the readability
 *     cap (chart_level_max_groups) — the refusal is reported via $hint.
 *  2. Pinned swap (box option chart_pinned_views): a chart GROUPED BY a cross-cut dim
 *     collapses to a single bar once that dim is pinned — swap to the configured
 *     alternate view. Runs after the level pass, against its outcome, exactly like the
 *     original constructor order.
 *
 * Pure computation against config + request state: the caller applies the outcome
 * ($view / $axisY when swapped; $caption / $hint always) to its own fields, so every
 * consumer (body, AI insight, click-to-filter) keeps seeing one consistent
 * level. An unconfigured box comes out completely untouched.
 */
class LevelViewResolver
{
    /** @var BoxChartConfig */
    protected $config;

    /** @var mixed CustomTable|null */
    protected $table;

    /** @var string|null */
    protected $chartType;

    /** @var array {chain: string[], deepest: string, applied: array<string,string>} */
    protected $chainInfo;

    /** @var mixed CustomView the view the box is currently on (tracks across both passes) */
    protected $current;

    /** @var mixed CustomView|null the swapped view; null = keep the configured view */
    public $view = null;

    /** @var string|null re-pointed measure key ("{AGGREGATE}_{summary id}") when swapped */
    public $axisY = null;

    /** @var string|null 「表示中」 caption naming the swapped view */
    public $caption = null;

    /** @var string|null name of the breakdown the readability cap refused (null = none) */
    public $hint = null;

    /**
     * @param BoxChartConfig $config
     * @param mixed $table  the box's CustomTable (null tolerated — both passes bail)
     * @param mixed $currentView  the box's configured CustomView
     * @param string|null $chartType
     * @param array $chainInfo  ChartItem::filterBarChain() result (post-sanitize)
     * @return static
     */
    public static function resolve(BoxChartConfig $config, $table, $currentView, $chartType, array $chainInfo)
    {
        $resolver = new static();
        $resolver->config = $config;
        $resolver->table = $table;
        $resolver->chartType = $chartType;
        $resolver->chainInfo = $chainInfo;
        $resolver->current = $currentView;

        $resolver->resolveLevel();
        $resolver->resolvePinned();

        return $resolver;
    }

    /** Level-aware grouping (chart_level_views) — see class doc. */
    protected function resolveLevel()
    {
        $map = $this->config->levelViews();
        if (empty($map) || is_nullorempty($this->table)) {
            return;
        }
        if (empty($this->chainInfo['chain'])) {
            return; // dashboard has no hierarchy dims — nothing to resolve
        }

        // Walk down from the current depth, collapsing single-child levels: when the mapped
        // view's group column has exactly ONE distinct value under the current filters (e.g.
        // a region containing a single prefecture), a one-bar "comparison" says nothing — so
        // advance to the next mapped depth and compare the grandchildren instead (the BI
        // single-child collapse). Bounded by the chain length; each hop costs one indexed
        // DISTINCT ... LIMIT 2 probe, and only runs when a deeper mapped view exists.
        // Readability cap (option chart_level_max_groups, 0 = unlimited): when the depth the
        // user jumped to would explode into an unreadable wall of bars (e.g. Grade selected
        // with no School → every class in the country), REFUSE the swap and stay at the
        // shallower view — the filters still scope the data, so the chart stays meaningful
        // ("grade-3 cohort compared by region") instead of broken. A hint says why.
        $cap = $this->config->levelMaxGroups();

        $depth = $this->chainInfo['deepest'];
        $chosen = null;
        $steps = count($this->chainInfo['chain']) + 1;
        while ($steps-- > 0) {
            if (!array_key_exists($depth, $map) || is_nullorempty($map[$depth])) {
                break; // no view configured for this depth — keep what we have so far
            }
            $view = $this->candidate($map[$depth]);
            if ($view === null) {
                break;
            }
            $next = $this->nextChainDepth($depth);
            $nextMapped = $next !== null && array_key_exists($next, $map) && !is_nullorempty($map[$next]);
            // one indexed DISTINCT ... LIMIT probe serves both guards (only when needed)
            $count = ($cap > 0 || $nextMapped)
                ? $this->groupDistinctCount($view, max($cap + 1, 2))
                : PHP_INT_MAX;
            if ($cap > 0 && $count > $cap && $count !== PHP_INT_MAX) {
                $this->hint = $view->view_view_name; // name the breakdown we refused
                break; // too many groups at this depth — keep the shallower view
            }
            $chosen = $view;
            if (!$nextMapped || $count > 1) {
                break;
            }
            $depth = $next; // single-child collapse: advance to the grandchildren
        }
        if ($chosen === null) {
            return; // no valid view for this depth — the box keeps its own view
        }
        if (!is_nullorempty($this->current) && $chosen->id == $this->current->id) {
            return; // already at this level
        }
        $this->swapTo($chosen);
    }

    /** Degenerate-chart swap for pinned cross-cut dims (chart_pinned_views) — see class doc. */
    protected function resolvePinned()
    {
        $map = $this->config->pinnedViews();
        if (empty($map) || is_nullorempty($this->table)) {
            return;
        }
        foreach ($map as $col => $viewId) {
            if (!FilterState::isIdentifier($col) || is_nullorempty($viewId)) {
                continue;
            }
            if (FilterState::spec(request()->input('df_' . $col)) === null) {
                continue; // dim not active (any value shape counts, see FilterState::spec)
            }
            $view = $this->candidate($viewId);
            if ($view === null) {
                continue;
            }
            if (!is_nullorempty($this->current) && $view->id == $this->current->id) {
                return; // already showing it
            }
            $this->swapTo($view);
            return;
        }
    }

    /** Apply a swap: expose the view, re-point the measure, caption the level. */
    protected function swapTo($view)
    {
        $summary = collect($view->custom_view_summaries)->first();

        $this->current = $view;
        $this->view = $view;
        // chart_axisy keys are "{1=summary|0=view column}_{id}" — a measure is always a summary.
        $this->axisY = ViewKindType::AGGREGATE . '_' . $summary->id;
        $this->caption = $view->view_view_name;
    }

    /**
     * Validate one chart_level_views / chart_pinned_views entry: the view must exist, be an
     * aggregate view on this box's own table, have a summary, and (for multi-series charts)
     * at least 2 group columns.
     *
     * @param  mixed $viewId
     * @return mixed CustomView|null
     */
    protected function candidate($viewId)
    {
        $view = CustomView::getEloquent($viewId);
        if (is_nullorempty($view)
            || $view->custom_table_id != $this->table->id
            || array_get($view, 'view_kind_type') != ViewKindType::AGGREGATE) {
            return null;
        }
        if (ChartType::isMultiSeries($this->chartType) && count($view->custom_view_columns) < 2) {
            return null; // a multi-series chart needs X + series group columns
        }
        if (is_nullorempty(collect($view->custom_view_summaries)->first())) {
            return null;
        }
        return $view;
    }

    /**
     * The chain depth one level below $depth ('' = nothing selected → the first chain dim),
     * or null at the leaf.
     *
     * @param  string $depth
     * @return string|null
     */
    protected function nextChainDepth($depth)
    {
        $chain = $this->chainInfo['chain'];
        if ($depth === '') {
            return $chain[0] ?? null;
        }
        $i = array_search($depth, $chain, true);
        if ($i === false || $i + 1 >= count($chain)) {
            return null;
        }
        return $chain[$i + 1];
    }

    /**
     * How many distinct (non-blank) values the view's group column has under the current
     * dashboard filters — probed with DISTINCT ... LIMIT $limit (callers only care about
     * "1", "more than 1", or "over the cap"), index-backed on the physical table. Returns
     * "many" (PHP_INT_MAX) for shapes that can't be probed (cross-table / derived grouping)
     * so neither the collapse nor the cap ever fires on them.
     *
     * @param  mixed $view CustomView
     * @param  int $limit
     * @return int
     */
    protected function groupDistinctCount($view, $limit = 2)
    {
        $vc = collect($view->custom_view_columns)->first();
        $cc = $vc ? ($vc->custom_column ?? null) : null;
        if (is_nullorempty($cc)
            || array_get($vc, 'view_column_table_id') != $this->table->id
            || !is_nullorempty(array_get($vc, 'view_group_condition'))) {
            return PHP_INT_MAX;
        }
        $expr = FilterState::columnExpr($cc);
        $query = \DB::table(getDBTableName($this->table))->whereNull('deleted_at');
        $columns = $this->table->custom_columns->keyBy('column_name');
        foreach ($this->chainInfo['applied'] as $col => $val) {
            // same SQL rule as the box query — single value, IN list or range alike
            FilterState::where($query, $columns->get($col), $col, $val);
        }
        return $query->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''")
            ->selectRaw("{$expr} as v")->distinct()->limit(max(2, (int) $limit))->pluck('v')->count();
    }
}
