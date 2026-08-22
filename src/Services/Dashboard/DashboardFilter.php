<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * The dashboard filter bar's current selection (the df_{column} request params), and how
 * it narrows a box.
 *
 * Only configured items (FilterBarConfig) are read, so a stray df_ param can never filter
 * a dashboard that has no bar. A box is narrowed by an item when its table has the column
 * AND the item's targeting includes the box.
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
     * Active items that narrow this box, as column => CustomColumn of the box's table.
     */
    public function columnsFor($table, $box = null): array
    {
        if ($table === null || $this->config === null) {
            return [];
        }
        $out = [];
        foreach ($this->values as $column => $spec) {
            $customColumn = $table->custom_columns->firstWhere('column_name', $column);
            if ($customColumn !== null && $this->config->appliesTo($column, $box)) {
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
     * AND every active item this box can honour onto $query.
     *
     * @return string[] the columns applied
     */
    public function applyTo($query, $table, $box = null): array
    {
        $applied = [];
        foreach ($this->columnsFor($table, $box) as $column => $customColumn) {
            FilterValue::apply($query, $customColumn, $this->values[$column]);
            $applied[] = $column;
        }
        return $applied;
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
     * Stable string of the whole selection ('' when empty), for cache keys.
     */
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
}
