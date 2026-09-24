<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * The dashboard filter bar configuration, read from options.filter_bar:
 *
 *   {
 *     "source_table": "f_score",                       // table whose rows narrow the lists
 *     "dims": [
 *       {"column": "grade", "label": "学年", "default": "1"},   // one filter item per column
 *       {"column": "subject", "label": "教科", "targets": ["<box suuid>", ...]},
 *       {"column": "revenue", "label": "売上", "style": "range"} // pick the control
 *     ],
 *     "max_options": 500,                              // optional option cap per item
 *     "scope": {"school": "17"}                        // optional fixed scope of the option lists
 *   }
 *
 * `targets` (slicer targeting): with box suuids listed, ONLY those chart boxes are narrowed
 * by the item; empty / absent = every box whose table has the column.
 * `default` (initial selection): stored values preselected when the dashboard is opened
 * with no filter state and no remembered selection, and restored by リセット —
 * comma-separated for several values, "min~max" for a range item (see defaultQuery /
 * DashboardController's entry redirect).
 * `style` ("select" | "range") picks the item's control, overriding what FilterValue::style
 * derives from the column: an item lists the values in the data unless it is told otherwise,
 * so a continuous column (money, a measurement) asks for "range" to get from / to inputs.
 * `scope` fixes the rows the option lists (filter bar and chart filters) are read from —
 * a one-school dashboard lists the marks found in that school's rows, not the nationwide
 * ones. A column with a catalogue (a master table, defined items) lists its whole catalogue
 * regardless, until a selection elsewhere cross-filters it (ColumnOptions::choices); only a
 * catalogue that outgrows `max_options` falls back to the values the rows in scope hold —
 * which is why a nationwide class or pupil master still lists one school's on such a
 * dashboard, while a small master would list every entry. `scope` never filters box data:
 * a box takes its scope from its view.
 */
final class FilterBarConfig
{
    public const DEFAULT_MAX_OPTIONS = 500;

    /** @var string */
    private $sourceTable;
    /** @var array<string, array{column:string,label:string,targets:string[],default:string,style:?string}> */
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
                'default' => trim((string) array_get($dim, 'default', '')),
                'style' => in_array(array_get($dim, 'style'), ['select', 'range'], true)
                    ? (string) array_get($dim, 'style') : null,
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
     * @return array<int, array{column:string,label:string,targets:string[],default:string,style:?string}>
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
     * The df_ request params of the configured item defaults ([] when none): the query the
     * entry redirect adds when the dashboard is opened with no filter state. $table resolves
     * each item's column — "min~max" is a range on a range item and on any number / date
     * item, comma-separated stored values otherwise.
     *
     * @return array<string, string|array> param name (df_{column}) => value
     */
    public function defaultQuery($table): array
    {
        if ($table === null) {
            return [];
        }
        $params = [];
        foreach ($this->dims as $column => $dim) {
            $raw = (string) ($dim['default'] ?? '');
            $customColumn = $raw === '' ? null : $table->custom_columns->firstWhere('column_name', $column);
            if ($customColumn === null) {
                continue;
            }
            // "min~max" on a number or a date is a range whatever control the item shows for
            // it (a long number list shows from / to on the bar; a typed range keeps its
            // inputs anyway). A text column never compares, so its "~" is just a character.
            $isRange = FilterValue::style($customColumn, $dim['style'] ?? null) === 'range'
                || (FilterValue::kind($customColumn) !== 'text' && strpos($raw, '~') !== false);
            if ($isRange) {
                $bounds = array_map('trim', explode('~', $raw, 2));
                $spec = array_filter(['from' => $bounds[0], 'to' => $bounds[1] ?? ''], function ($v) {
                    return $v !== '';
                });
                if (!empty($spec)) {
                    $params['df_' . $column] = $spec;
                }
            } else {
                $values = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($v) {
                    return $v !== '';
                }));
                if (count($values) === 1) {
                    $params['df_' . $column] = $values[0];
                } elseif (!empty($values)) {
                    $params['df_' . $column] = $values;
                }
            }
        }
        return $params;
    }

    /**
     * The df_ params of this bar's items found in $params (a request query, a remembered
     * selection), in the shape defaultQuery produces. Params of items no longer on the bar,
     * empty ones and malformed values are dropped.
     *
     * @param array<string, mixed> $params
     * @return array<string, string|array> param name (df_{column}) => value
     */
    public function selection(array $params): array
    {
        $out = [];
        foreach (array_keys($this->dims) as $column) {
            $spec = FilterValue::parse($params['df_' . $column] ?? null);
            if ($spec === null) {
                continue;
            }
            if (isset($spec['in'])) {
                $out['df_' . $column] = count($spec['in']) === 1 ? $spec['in'][0] : $spec['in'];
            } else {
                $out['df_' . $column] = array_filter(['from' => $spec['from'], 'to' => $spec['to']], function ($v) {
                    return $v !== null;
                });
            }
        }
        return $out;
    }

    /**
     * A dashboard URL query in the bar's own format — df_col=v, df_col[]=v per value,
     * df_col[from]=v — the one dashboard.js writes, so a URL built here (the entry redirect,
     * リセット) compares item by item with the bar's.
     *
     * @param array<string, string|int|array> $query
     */
    public static function queryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if (!is_array($value)) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
                continue;
            }
            foreach ($value as $sub => $v) {
                if (is_scalar($v)) {
                    $parts[] = rawurlencode($key . (is_int($sub) ? '[]' : '[' . $sub . ']')) . '=' . rawurlencode((string) $v);
                }
            }
        }
        return implode('&', $parts);
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
