<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * The dashboard filter bar configuration, read from options.filter_bar:
 *
 *   {
 *     "source_table": "f_score",                       // table the option lists come from
 *     "dims": [
 *       {"column": "grade", "label": "学年"},            // one filter item per column
 *       {"column": "subject", "label": "教科", "targets": ["<box suuid>", ...]}
 *     ],
 *     "max_options": 500                               // optional option cap per item
 *   }
 *
 * `targets` (slicer targeting): with box suuids listed, ONLY those chart boxes are narrowed
 * by the item; empty / absent = every box whose table has the column.
 */
final class FilterBarConfig
{
    public const DEFAULT_MAX_OPTIONS = 500;

    /** @var string */
    private $sourceTable;
    /** @var array<string, array{column:string,label:string,targets:string[]}> */
    private $dims;
    /** @var int */
    private $maxOptions;

    private function __construct(string $sourceTable, array $dims, int $maxOptions)
    {
        $this->sourceTable = $sourceTable;
        $this->dims = $dims;
        $this->maxOptions = $maxOptions;
    }

    /**
     * null when the dashboard has no usable filter bar.
     */
    public static function fromDashboard($dashboard): ?self
    {
        return self::fromArray($dashboard ? $dashboard->getOption('filter_bar') : null);
    }

    public static function fromArray($raw): ?self
    {
        if (!is_array($raw) || is_nullorempty(array_get($raw, 'source_table')) || !is_array(array_get($raw, 'dims'))) {
            return null;
        }
        $dims = [];
        foreach ($raw['dims'] as $dim) {
            $column = array_get($dim, 'column');
            if (!FilterValue::isIdentifier($column) || isset($dims[$column])) {
                continue;
            }
            $label = trim((string) array_get($dim, 'label', ''));
            $dims[$column] = [
                'column' => $column,
                'label' => $label !== '' ? $label : $column,
                'targets' => array_values(array_filter((array) array_get($dim, 'targets', []), function ($t) {
                    return is_string($t) && $t !== '';
                })),
            ];
        }
        if (empty($dims)) {
            return null;
        }
        $max = (int) array_get($raw, 'max_options', self::DEFAULT_MAX_OPTIONS);
        return new self((string) $raw['source_table'], $dims, $max > 0 ? $max : self::DEFAULT_MAX_OPTIONS);
    }

    public function sourceTable(): string
    {
        return $this->sourceTable;
    }

    /**
     * @return array<int, array{column:string,label:string,targets:string[]}>
     */
    public function dims(): array
    {
        return array_values($this->dims);
    }

    public function dim(string $column): ?array
    {
        return $this->dims[$column] ?? null;
    }

    public function label(string $column): string
    {
        return $this->dims[$column]['label'] ?? $column;
    }

    public function maxOptions(): int
    {
        return $this->maxOptions;
    }

    /**
     * Slicer targeting gate: whether the item on $column narrows $box.
     */
    public function appliesTo(string $column, $box): bool
    {
        $dim = $this->dim($column);
        if ($dim === null) {
            return false;
        }
        if (empty($dim['targets']) || $box === null) {
            return true;
        }
        return in_array((string) array_get($box, 'suuid'), $dim['targets'], true);
    }
}
