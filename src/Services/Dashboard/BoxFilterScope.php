<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\DashboardBoxType;

/**
 * What part of the dashboard filter state one box's rendered output depends on.
 *
 * Emitted onto the box shell (data-df-cols / data-df-dynamic) so the dashboard JS can
 * skip re-fetching a box whose output provably cannot have changed — a filter click no
 * longer makes every box on the page blink, only the ones whose numbers (or disclosure
 * badge) actually differ.
 *
 * Two parts:
 *
 *   cols     the df_ columns that really narrow THIS box — its table's own columns,
 *            minus the dims whose slicer targeting excludes it. Exactly the whitelist
 *            FilterState::applyTo uses, so "this filter reaches the box" means the same
 *            thing on both sides.
 *
 *   dynamic  the box reads the filter state BEYOND those columns, so any change at all
 *            must re-render it: a drill/level swap (the view itself changes with the
 *            chain depth), a pinned-value swap or level visibility. Conservative by
 *            design — a box is only skippable
 *            when nothing in its config looks at the wider state.
 *
 * NOTE the caller must ALSO account for the "not affected / partially affected" badge,
 * which depends on the active columns this box does NOT honor. That part is filter-state
 * arithmetic (no config), so it lives in the JS signature next to the values.
 */
class BoxFilterScope
{
    /** Box options whose presence makes the box depend on the whole filter/chain state. */
    protected const DYNAMIC_KEYS = [
        'chart_level_views',
        'chart_pinned_views',
        'chart_level_visible',
        'chart_hide_when_pinned',
        'chart_level_max_groups',
        'chart_hide_when_capped',
    ];

    /**
     * @param mixed $box DashboardBox model (or null)
     * @return array{cols: string[], dynamic: bool}
     */
    public static function of($box)
    {
        $out = ['cols' => [], 'dynamic' => false];
        if (is_nullorempty($box)) {
            return $out;
        }
        // Only chart boxes apply df_ params at all (list/calendar/system keep their own scope).
        if ($box->dashboard_box_type != DashboardBoxType::CHART) {
            return $out;
        }
        $config = FilterBarConfig::fromDashboard($box->dashboard ?? null);
        if ($config === null) {
            return $out;
        }

        $table = CustomTable::getEloquent(array_get($box, 'options.target_table_id'));
        if (!is_nullorempty($table)) {
            $columns = $table->custom_columns->pluck('column_name')->all();
            foreach ($config->dims() as $dim) {
                $col = array_get($dim, 'column');
                if (is_nullorempty($col) || !in_array($col, $columns, true)) {
                    continue;
                }
                if (!FilterState::targetsAllow($box, $col)) {
                    continue; // slicer targeting excludes this box — the dim never narrows it
                }
                $out['cols'][] = $col;
            }
        }

        foreach (static::DYNAMIC_KEYS as $key) {
            if (!is_nullorempty(array_get($box, 'options.' . $key))) {
                $out['dynamic'] = true;
                return $out;
            }
        }
        return $out;
    }
}
