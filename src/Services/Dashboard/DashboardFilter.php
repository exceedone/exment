<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * The dashboard filter bar's current selection (the df_{column} request params), and how
 * it narrows a box.
 *
 * Only configured items (FilterBarConfig) are read, so a stray df_ param can never filter
 * a dashboard that has no bar. A box is narrowed by an item when its table has the column
 * AND the item's targeting includes the box — except a chart's own X item, which the chart
 * highlights instead of filtering by (ChartItem::highlightColumn; applyTo's $except).
 */
final class DashboardFilter
{
    /** @var FilterBarConfig|null */
    private $config;
    /** @var array<string, array> column => spec */
    private $values = [];

    private function __construct(?FilterBarConfig $config, array $params)
    {
        $this->config = $config;
        if ($config === null) {
            return;
        }
        foreach ($config->dims() as $dim) {
            $spec = FilterValue::parse($params['df_' . $dim['column']] ?? null);
            if ($spec !== null) {
                $this->values[$dim['column']] = $spec;
            }
        }
    }

    public static function fromRequest($dashboard): self
    {
        return new self(FilterBarConfig::fromDashboard($dashboard), request()->all());
    }

    public static function of($dashboard, array $params): self
    {
        return new self(FilterBarConfig::fromDashboard($dashboard), $params);
    }

    public function config(): ?FilterBarConfig
    {
        return $this->config;
    }

    public function isEmpty(): bool
    {
        return empty($this->values);
    }

    /**
     * @return array<string, array> column => spec
     */
    public function values(): array
    {
        return $this->values;
    }

    public function spec(string $column): ?array
    {
        return $this->values[$column] ?? null;
    }

    /**
     * The control the bar shows for this item NOW — 'select' (a list to pick from) or
     * 'range' (from / to) — given its column, its configured style and its current value
     * (FilterValue::styleFor). The ONE rule every consumer decides by: the bar renders by
     * it, columnsFor honours a selection by it, a chart highlights its X item by it. A
     * number or date item that lists its values shows from / to once a range is on it
     * (its list outgrew the cap), and then it is a range item to everyone at once.
     *
     * @param mixed $customColumn  CustomColumn of the item's column
     */
    public function styleOf($customColumn): string
    {
        $column = (string) $customColumn->column_name;
        $dim = $this->config ? $this->config->dim($column) : null;
        return FilterValue::styleFor($customColumn, $dim['style'] ?? null, $this->values[$column] ?? null);
    }

    /**
     * Active items that narrow this box, as column => CustomColumn of the box's table.
     *
     * A selection counts only in the shape its item's control shows for it (styleOf):
     * picked values need a select item; a "from / to" needs a range item, or a number /
     * date item, which shows from / to for it. A from / to on a text item, which always
     * lists its values, would narrow every chart while the bar displayed nothing selected,
     * so it is dropped.
     */
    public function columnsFor($table, $box = null): array
    {
        if ($table === null || $this->config === null) {
            return [];
        }
        $out = [];
        foreach ($this->values as $column => $spec) {
            $customColumn = $table->custom_columns->firstWhere('column_name', $column);
            if ($customColumn === null || !$this->config->appliesTo($column, $box)) {
                continue;
            }
            $style = $this->styleOf($customColumn);
            if (isset($spec['in']) ? $style === 'select' : $style === 'range') {
                $out[$column] = $customColumn;
            }
        }
        return $out;
    }

    /**
     * Active items that do NOT narrow this box (no such column, or targeted elsewhere).
     *
     * @return string[] column names
     */
    public function ignoredFor($table, $box = null): array
    {
        return array_values(array_diff(array_keys($this->values), array_keys($this->columnsFor($table, $box))));
    }

    /**
     * The values picked on a select item ([] when none, or a range item).
     *
     * @return string[]
     */
    public function selected(string $column): array
    {
        return $this->values[$column]['in'] ?? [];
    }

    /**
     * AND every active item this box can honour onto $query.
     *
     * @param string[] $except items to leave out — a chart's own X item, which it highlights
     *                         instead of filtering by (ChartItem::highlightColumn)
     * @return string[] the columns applied
     */
    public function applyTo($query, $table, $box = null, array $except = []): array
    {
        $applied = [];
        foreach ($this->columnsFor($table, $box) as $column => $customColumn) {
            if (in_array($column, $except, true)) {
                continue;
            }
            FilterValue::apply($query, $customColumn, $this->values[$column]);
            $applied[] = $column;
        }
        return $applied;
    }

    /**
     * AND the bar's fixed scope (config `scope`) onto an OPTION-LIST query, for the scope
     * columns $table carries. Option lists only — box data is never narrowed by it.
     */
    public function applyFixedScope($query, $table): void
    {
        foreach ($this->fixedScopeColumnsFor($table) as $column => $customColumn) {
            FilterValue::apply($query, $customColumn, $this->config->scope()[$column]);
        }
    }

    /**
     * The fixed scope entries (filter_bar.scope) $table carries, as column => CustomColumn;
     * [] for a table without those columns — nothing narrows its lists.
     */
    public function fixedScopeColumnsFor($table): array
    {
        if ($table === null || $this->config === null) {
            return [];
        }
        $out = [];
        foreach (array_keys($this->config->scope()) as $column) {
            $customColumn = $table->custom_columns->firstWhere('column_name', $column);
            if ($customColumn !== null) {
                $out[$column] = $customColumn;
            }
        }
        return $out;
    }

    /**
     * The option cap of this dashboard's lists.
     */
    public function maxOptions(): int
    {
        return $this->config ? $this->config->maxOptions() : FilterBarConfig::DEFAULT_MAX_OPTIONS;
    }

    /**
     * Display labels of the given items.
     *
     * @param string[] $columns
     * @return string[]
     */
    public function labels(array $columns): array
    {
        return array_map(function ($column) {
            return $this->config ? $this->config->label($column) : $column;
        }, $columns);
    }

    /**
     * Whether $other selects the same values on the same items (in any order).
     */
    public function sameAs(self $other): bool
    {
        $tokens = function (array $values) {
            $out = array_map([FilterValue::class, 'token'], $values);
            ksort($out);
            return $out;
        };
        return $tokens($this->values) === $tokens($other->values);
    }

    /**
     * Stable string of the selection ('' when empty), for cache keys.
     *
     * @param string[] $except items left out (a pick on a highlighted item changes no data)
     */
    public function fingerprint(array $except = []): string
    {
        $parts = [];
        foreach ($this->values as $column => $spec) {
            if (!in_array($column, $except, true)) {
                $parts[] = $column . '=' . FilterValue::token($spec);
            }
        }
        if (empty($parts)) {
            return '';
        }
        sort($parts);
        return md5(implode('&', $parts));
    }
}
