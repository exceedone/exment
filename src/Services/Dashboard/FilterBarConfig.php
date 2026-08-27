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
 *     "max_options": 500,                              // optional option cap per item
 *     "scope": {"school": "17"}                        // optional fixed scope of the option lists
 *   }
 *
 * `targets` (slicer targeting): with box suuids listed, ONLY those chart boxes are narrowed
 * by the item; empty / absent = every box whose table has the column.
 * `scope` narrows every option list (filter bar and chart filters) to the given column
 * values — a one-school dashboard lists that school's classes, not the nationwide ones.
 * It never filters box data: a box takes its scope from its view.
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
    /** @var array<string, array> column => spec */
    private $scope;

    private function __construct(string $sourceTable, array $dims, int $maxOptions, array $scope)
    {
        $this->sourceTable = $sourceTable;
        $this->dims = $dims;
        $this->maxOptions = $maxOptions;
        $this->scope = $scope;
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
        $scope = [];
        foreach ((array) array_get($raw, 'scope', []) as $column => $value) {
            $spec = FilterValue::parse($value);
            if (FilterValue::isIdentifier($column) && $spec !== null) {
                $scope[$column] = $spec;
            }
        }
        return new self((string) $raw['source_table'], $dims, $max > 0 ? $max : self::DEFAULT_MAX_OPTIONS, $scope);
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
     * Fixed scope of the option lists, as column => spec.
     *
     * @return array<string, array>
     */
    public function scope(): array
    {
        return $this->scope;
    }

    /**
     * The items that can narrow $box at all — its table has the column and the targeting
     * includes the box — regardless of the current selection. Rendered as the box's
     * `data-df-dims` attribute, so a bar change only reloads the boxes it narrows.
     *
     * @return string[] column names
     */
    public function dimsFor($table, $box): array
    {
        if ($table === null) {
            return [];
        }
        $out = [];
        foreach ($this->dims as $column => $dim) {
            if ($table->custom_columns->firstWhere('column_name', $column) !== null && $this->appliesTo($column, $box)) {
                $out[] = $column;
            }
        }
        return $out;
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
