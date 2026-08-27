<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;

/**
 * Render data of the dashboard filter bar (dashboard/filter_bar.blade.php).
 *
 * Each select item lists the values present in the source table within the scope of the
 * OTHER selected items ("relevant values", Power BI slicer style); a selected value the
 * scope no longer offers stays listed so it can be removed.
 */
final class FilterBarView
{
    /**
     * null when the dashboard has no filter bar (or its source table is gone).
     *
     * @return array{dims: array, has_selection: bool, dashboard_suuid: string}|null
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
            $view = [
                'column' => $column,
                'label' => $dim['label'],
                'style' => FilterValue::style($customColumn),
                'kind' => FilterValue::kind($customColumn),
                'active' => $spec !== null,
            ];
            if ($view['style'] === 'range') {
                $view['range'] = ['from' => (string) ($spec['from'] ?? ''), 'to' => (string) ($spec['to'] ?? '')];
            } else {
                $scope = function ($query) use ($filter, $table, $column) {
                    $filter->applyFixedScope($query, $table);
                    foreach ($filter->values() as $other => $otherSpec) {
                        $otherColumn = $other === $column ? null : $table->custom_columns->firstWhere('column_name', $other);
                        if ($otherColumn !== null) {
                            FilterValue::apply($query, $otherColumn, $otherSpec);
                        }
                    }
                };
                $result = ColumnOptions::distinct($table, $customColumn, $scope, $config->maxOptions());
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

        return [
            'dims' => $dims,
            'has_selection' => !$filter->isEmpty(),
            'dashboard_suuid' => (string) $dashboard->suuid,
        ];
    }
}
