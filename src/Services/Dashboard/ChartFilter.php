<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * A chart box's own filter (box option chart_filters = [column_name, ...]) and its current
 * selection (the bf_{column} params of the box's AJAX request).
 *
 * Page-lifetime by design: the selection lives in the dashboard JS per box and is sent
 * with THIS box's request only — the page URL, the filter bar and every other box stay
 * untouched. A bf_ param counts only for a column the box declares AND its table has.
 */
final class ChartFilter
{
    /** @var mixed DashboardBox|null */
    private $box;
    /** @var mixed CustomTable|null */
    private $table;
    /** @var array<string, mixed> column => CustomColumn (declared + existing) */
    private $columns = [];
    /** @var array<string, array> column => spec (active only) */
    private $values = [];

    private function __construct($box, $table, array $params)
    {
        $this->box = $box;
        $this->table = $table;
        $declared = $box ? array_get($box, 'options.chart_filters') : null;
        if (!is_array($declared) || $table === null) {
            return;
        }
        foreach ($declared as $column) {
            if (!FilterValue::isIdentifier($column) || isset($this->columns[$column])) {
                continue;
            }
            $customColumn = $table->custom_columns->firstWhere('column_name', $column);
            if ($customColumn === null) {
                continue;
            }
            $this->columns[$column] = $customColumn;
            $spec = FilterValue::parse($params['bf_' . $column] ?? null);
            if ($spec !== null) {
                $this->values[$column] = $spec;
            }
        }
    }

    public static function fromRequest($box, $table): self
    {
        return new self($box, $table, request()->all());
    }

    public static function of($box, $table, array $params): self
    {
        return new self($box, $table, $params);
    }

    /**
     * @return array<string, mixed> column => CustomColumn
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function isConfigured(): bool
    {
        return !empty($this->columns);
    }

    /**
     * @return array<string, array> column => spec
     */
    public function values(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return empty($this->values);
    }

    /**
     * AND every active filter onto $query.
     *
     * @param string[] $except columns to skip (when listing a column's own options)
     */
    public function applyTo($query, array $except = []): void
    {
        foreach ($this->values as $column => $spec) {
            if (!in_array($column, $except, true)) {
                FilterValue::apply($query, $this->columns[$column], $spec);
            }
        }
    }

    public function fingerprint(): string
    {
        if (empty($this->values)) {
            return '';
        }
        $parts = [];
        foreach ($this->values as $column => $spec) {
            $parts[] = $column . '=' . FilterValue::token($spec);
        }
        sort($parts);
        return md5(implode('&', $parts));
    }

    /**
     * Human-readable active filters for the caption under the toolbar.
     *
     * @return array<int, array{label:string, value:string}>
     */
    public function captions(): array
    {
        $out = [];
        foreach ($this->values as $column => $spec) {
            $values = FilterValue::values($spec);
            if (isset($spec['in'])) {
                $labels = ColumnOptions::labels($this->columns[$column], $values);
                $values = array_map(function ($v) use ($labels) {
                    return $labels[$v] ?? $v;
                }, $values);
            }
            $out[] = ['label' => (string) $this->columns[$column]->column_view_name, 'value' => implode(', ', $values)];
        }
        return $out;
    }

    /**
     * View-model of the toolbar popover: one field per declared column. A select field's
     * options are the values present in the box's current scope (the view's own filters,
     * the dashboard filter and the box's OTHER chart filters).
     *
     * Relevant-values rule: a ticked value the scope no longer offers (a class picked before
     * the dashboard filter moved to another grade) could only empty the chart, so it is
     * DROPPED from the selection here — call this before querying the chart data. A capped
     * list (too many values to show) keeps the selection as is.
     *
     * The option cap and the fixed scope are the dashboard's own (options.filter_bar
     * max_options / scope), so a chart filter lists what the bar would, within the chart's own scope (its view, the
     * other filters); as on the bar, only a pick elsewhere cross-filters a catalogue column.
     *
     * @param callable|null $viewScope  narrows a query to the box's view (its static filters)
     * @param string[] $dashboardExcept  bar items the box does not filter by (its highlighted X item)
     * @return array<int, array>
     */
    public function fields(DashboardFilter $dashboardFilter, ?callable $viewScope = null, array $dashboardExcept = []): array
    {
        $cap = $dashboardFilter->maxOptions();
        $fields = [];
        foreach ($this->columns as $column => $customColumn) {
            $spec = $this->values[$column] ?? null;
            // a from / to already on a number or a date keeps its from / to inputs, whatever
            // its list would be now (a tick elsewhere can bring it back under the cap)
            $style = FilterValue::styleFor($customColumn, null, $spec);
            $kind = FilterValue::kind($customColumn);
            // the chart's own scope, minus this column: what its list, or its range's ends, are read within
            $scope = function ($query) use ($dashboardFilter, $viewScope, $column, $dashboardExcept) {
                if ($viewScope !== null) {
                    $viewScope($query);
                }
                $dashboardFilter->applyFixedScope($query, $this->table);
                $dashboardFilter->applyTo($query, $this->table, $this->box, $dashboardExcept);
                $this->applyTo($query, [$column]);
            };
            $result = null;
            if ($style === 'select') {
                // a pick elsewhere — on the bar, on another field of this filter — cross-filters
                // a catalogue column; the view's own filters and the fixed scope do not
                $narrowed = !empty(array_diff_key($dashboardFilter->columnsFor($this->table, $this->box), array_flip($dashboardExcept)))
                    || !empty(array_diff_key($this->values, [$column => true]));
                $result = ColumnOptions::choices($this->table, $customColumn, $scope, $narrowed, $cap);
                // same fallback as the bar (FilterBarView): a list that will not fit gives
                // way to from / to, which a number or a date can always answer — unless
                // values are ticked on it, which stay listed (below) so they can be unticked
                if ($result['capped'] && $kind !== 'text' && !isset($spec['in'])) {
                    $style = 'range';
                    $result = null;
                }
            }
            $field = [
                'column' => $column,
                'label' => (string) $customColumn->column_view_name,
                'style' => $style,
                'kind' => $kind,
                'active' => $spec !== null,
            ];
            if ($result === null) {
                // the inputs show the data's ends while nothing is typed (as on the bar,
                // FilterBarView): dashboard.js keeps a bound equal to its end off the request
                $field['range'] = ['from' => (string) ($spec['from'] ?? ''), 'to' => (string) ($spec['to'] ?? '')]
                    + ColumnOptions::bounds($this->table, $customColumn, $scope);
            } else {
                $selected = $spec['in'] ?? [];
                if (!$result['capped']) {
                    $selected = array_values(array_intersect($selected, array_column($result['options'], 'id')));
                    if (count($selected)) {
                        $this->values[$column] = ['in' => $selected];
                    } else {
                        unset($this->values[$column]);
                    }
                }
                // a capped list (no options) still shows what is ticked, so it can be unticked
                $missing = array_values(array_diff($selected, array_column($result['options'], 'id')));
                if (count($missing)) {
                    $labels = ColumnOptions::labels($customColumn, $missing);
                    foreach (array_reverse($missing) as $v) {
                        array_unshift($result['options'], ['id' => $v, 'name' => (string) ($labels[$v] ?? $v)]);
                    }
                }
                $field['options'] = $result['options'];
                $field['selected'] = $selected;
                $field['active'] = count($selected) > 0;
                $field['capped'] = $result['capped'];
            }
            $fields[] = $field;
        }
        return $fields;
    }
}
