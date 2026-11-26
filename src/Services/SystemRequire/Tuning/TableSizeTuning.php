<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

/**
 * The largest tables (ISS02927): the rows and the sizes of the data and the indexes (MySQL,
 * MariaDB). A table that grew GROWTH times and by GROWTH_ROWS rows since the count before is
 * told: an import or an automatic job may run again and again.
 */
class TableSizeTuning extends DataTuningBase
{
    public const TOP = 5;

    public const GROWTH = 2;

    public const GROWTH_ROWS = 50000;

    protected function key(): string
    {
        return 'table_size';
    }

    /**
     * The tables that grew much since the count before.
     *
     * @return array<string, array<string, mixed>>
     */
    public function grown(): array
    {
        return array_filter($this->tables(), function ($counts) {
            $before = $counts['previous_rows'] ?? null;
            if (!is_int($before)) {
                return false;
            }
            $rows = intval($counts['rows']);
            return $rows - $before >= static::GROWTH_ROWS && $rows >= $before * static::GROWTH;
        });
    }

    /**
     * The largest tables, by the rows.
     *
     * @return array<string, array<string, mixed>>
     */
    public function top(): array
    {
        $tables = $this->tables();
        uasort($tables, function ($a, $b) {
            return intval($b['rows']) <=> intval($a['rows']);
        });
        return array_slice($tables, 0, static::TOP, true);
    }

    protected function warns(): bool
    {
        return count($this->grown()) > 0;
    }

    protected function found(): string
    {
        $texts = [];
        foreach ($this->top() as $counts) {
            $text = $counts['table']->table_view_name . ': ' . number_format(intval($counts['rows']));
            if (is_int($counts['data_bytes'] ?? null)) {
                $text .= ' (' . exmtrans('tuning.data_size', ['data' => static::bytes($counts['data_bytes']), 'index' => static::bytes($counts['index_bytes'] ?? null)]) . ')';
            }
            $texts[] = $text;
        }
        $grown = array_map(function ($counts) {
            return $counts['table']->table_view_name . ': ' . number_format(intval($counts['previous_rows'])) . ' → ' . number_format(intval($counts['rows']));
        }, $this->grown());
        if (count($grown) > 0) {
            $texts[] = exmtrans('tuning.item.table_size.grown', ['tables' => implode(', ', $grown)]);
        }
        return count($texts) > 0 ? implode(' / ', $texts) : '-';
    }
}
