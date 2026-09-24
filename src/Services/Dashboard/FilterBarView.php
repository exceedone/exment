<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;

/**
 * Render data of the dashboard filter bar (dashboard/filter_bar.blade.php).
 *
 * Each select item lists its column's catalogue (ColumnOptions::choices — the master's
 * records, the defined items; the values held for a plain column), narrowed to what the
 * source table's rows hold within the OTHER selected items and the fixed scope ("relevant
 * values", Power BI slicer style). A selected value the scope no longer offers stays listed
 * so it can be removed.
 */
final class FilterBarView
{
    /**
     * null when the dashboard has no filter bar (or its source table is gone).
     *
     * @return array{dims: array, can_reset: bool, reset_query: string, dashboard_suuid: string}|null
     */
    public static function build($dashboard, DashboardFilter $filter): ?array
    {
        $config = $filter->config();
        if ($config === null) {
            return null;
        }
        $table = CustomTable::getEloquent($config->sourceTable());
        if ($table === null) {
            return null;
        }

        $dims = [];
        foreach ($config->dims() as $dim) {
            $column = $dim['column'];
            $customColumn = $table->custom_columns->firstWhere('column_name', $column);
            if ($customColumn === null) {
                continue;
            }
            $spec = $filter->spec($column);
            // the item's control by the one rule (DashboardFilter::styleOf): a from / to
            // already on a number or a date keeps its from / to inputs, whatever its list
            // would be now (a pick elsewhere can bring it back under the cap)
            $style = $filter->styleOf($customColumn);
            $kind = FilterValue::kind($customColumn);
            [$scope, $narrowed] = self::listScope($filter, $table, $column);
            $result = null;
            if ($style === 'select') {
                $result = ColumnOptions::choices($table, $customColumn, $scope, $narrowed, $config->maxOptions());
                // a list too long to render falls back to from / to, which stays usable at
                // any cardinality — but only where a range compares, on a number or a date,
                // and only while nothing is picked on the item: picked values stay listed
                // (below) so they can be seen and removed, never hidden behind two empty
                // inputs while they still filter. A text column keeps its list and asks to
                // be narrowed down first instead.
                if ($result['capped'] && $kind !== 'text' && !isset($spec['in'])) {
                    $style = 'range';
                    $result = null;
                }
            }
            $view = [
                'column' => $column,
                'label' => $dim['label'],
                'style' => $style,
                'kind' => $kind,
                'active' => $spec !== null,
            ];
            if ($result === null) {
                // the two inputs show the data's ends (min / max within the same scope as a
                // list) while nothing is typed on them, as a Power BI numeric range slicer
                // does; a typed bound takes its end's place. dashboard.js puts only a bound
                // that differs from its end on the URL, so an untouched input filters nothing.
                $view['range'] = ['from' => (string) ($spec['from'] ?? ''), 'to' => (string) ($spec['to'] ?? '')]
                    + ColumnOptions::bounds($table, $customColumn, $scope);
            } else {
                $selected = $spec['in'] ?? [];
                $missing = array_values(array_diff($selected, array_column($result['options'], 'id')));
                $labels = ColumnOptions::labels($customColumn, $missing);
                foreach (array_reverse($missing) as $v) {
                    array_unshift($result['options'], ['id' => $v, 'name' => (string) ($labels[$v] ?? $v)]);
                }
                $view['options'] = $result['options'];
                $view['selected'] = $selected;
                $view['capped'] = $result['capped'];
            }
            $dims[] = $view;
        }

        // リセット returns the bar to the configured defaults (none configured: to no
        // selection, marked dfr) — offered while the selection differs from them
        $defaults = $config->defaultQuery($table);
        return [
            'dims' => $dims,
            'can_reset' => !$filter->sameAs(DashboardFilter::of($dashboard, $defaults)),
            'reset_query' => FilterBarConfig::queryString(['dashboard' => (string) $dashboard->suuid] + ($defaults ?: ['dfr' => 1])),
            'dashboard_suuid' => (string) $dashboard->suuid,
        ];
    }

    /**
     * The scope of an item's option list — the bar's fixed scope and the OTHER items'
     * selections, as far as the source table carries their columns; null when neither
     * applies — and whether a selection is among them. Only a selection cross-filters a
     * catalogue column: with nothing selected it lists its whole catalogue, so a record
     * created on the master is a choice at once, on a fixed-scope dashboard too.
     *
     * @param mixed $table  the source CustomTable
     * @return array{0: callable|null, 1: bool}  [scope over the source table's rows, narrowed]
     */
    private static function listScope(DashboardFilter $filter, $table, string $column): array
    {
        $others = [];
        foreach ($filter->values() as $other => $otherSpec) {
            $otherColumn = $other === $column ? null : $table->custom_columns->firstWhere('column_name', $other);
            if ($otherColumn !== null) {
                $others[] = [$otherColumn, $otherSpec];
            }
        }
        if (empty($others) && empty($filter->fixedScopeColumnsFor($table))) {
            return [null, false];
        }
        $scope = function ($query) use ($filter, $table, $others) {
            $filter->applyFixedScope($query, $table);
            foreach ($others as [$otherColumn, $otherSpec]) {
                FilterValue::apply($query, $otherColumn, $otherSpec);
            }
        };
        return [$scope, !empty($others)];
    }
}
