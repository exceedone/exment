<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;

/**
 * Option lists for a filter control.
 *
 * choices() lists a column the way a Power BI slicer on a dimension does: the CATALOGUE
 * behind the column when it has one — every record of the master a select_table column
 * points at, every item defined on a select / select_valtext column — so a value is a
 * choice as soon as it exists, before any row uses it. A SELECTION elsewhere (another item
 * of the bar, another field of a chart filter) cross-filters the catalogue to the values the
 * rows within the scope hold; a scope that only fixes the data in view (filter_bar.scope, a
 * chart's view filters) hides no catalogue entry — a slicer on a dimension shows the
 * dimension, as Power BI does. A column without a catalogue — a number, a date, free
 * text — lists the DISTINCT values of the rows (distinct()); so does a catalogue too long
 * for the option cap (a nationwide list of schools), where the values in use are the
 * useful list.
 *
 * The rows are always read the same way (values(): DISTINCT with a cap); the master is then
 * read by a literal id list, never by an IN (subquery), which MariaDB runs row by row.
 */
final class ColumnOptions
{
    /**
     * @param CustomTable $table    the table whose rows are filtered
     * @param mixed $column         CustomColumn of $table
     * @param callable|null $scope  receives a query on $table's rows: what the rows in view are
     *                              restricted to (fixed scope, view filters, selections); null = none
     * @param bool $narrowed        the scope carries a selection: cross-filter the catalogue to
     *                              the values the rows within the scope hold
     * @param int $limit            option cap: a catalogue over it falls back to the values in
     *                              use, which are withheld when over it too (capped = true)
     * @return array{options: array<int, array{id:string,name:string}>, capped: bool}
     */
    public static function choices(CustomTable $table, $column, ?callable $scope, bool $narrowed, int $limit = FilterBarConfig::DEFAULT_MAX_OPTIONS): array
    {
        $master = $column->select_target_table;
        $defined = $master ? null : self::definedChoices($column);
        if (!$master && $defined === null) {
            return self::distinct($table, $column, $scope, $limit); // no catalogue: the rows decide
        }
        $present = null;
        if ($narrowed) {
            $rows = self::values($table, $column, $scope, $limit);
            if ($rows['capped']) {
                return ['options' => [], 'capped' => true];
            }
            $present = $rows['values'];
        }
        $options = $master
            ? self::masterOptions($master, $present, $limit)
            : self::definedOptions($defined, $present);
        if ($options === null || count($options) > $limit) {
            // a catalogue too long to list gives way to the values the rows hold, as a plain
            // column lists them (capped in turn when those are too many); the rows already
            // read for the cross-filter are not read again
            return $present === null ? self::distinct($table, $column, $scope, $limit) : self::fromValues($column, $present);
        }
        return ['options' => self::uniqueNames($options), 'capped' => false];
    }

    /**
     * The lowest and highest non-empty value of $column over $table's rows within $scope:
     * what a range item's two inputs show while nothing is typed on them, the way a Power
     * BI numeric range slicer shows the data's ends (an input left at its end filters
     * nothing — dashboard.js keeps such a bound off the URL). Numbers compare as numbers.
     *
     * @param CustomTable $table
     * @param mixed $column  CustomColumn of $table
     * @param callable|null $scope  receives the query builder to narrow it
     * @return array{min: string, max: string}  as the inputs show them (FilterValue::formatBound); '' with no rows
     */
    public static function bounds(CustomTable $table, $column, ?callable $scope = null): array
    {
        $expr = FilterValue::columnExpr($column);
        $compare = FilterValue::compareExpr($column);
        $kind = FilterValue::kind($column);
        $query = $table->getValueQuery();
        if ($scope !== null) {
            $scope($query);
        }
        $row = $query
            ->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''")
            ->selectRaw("MIN({$compare}) as lo, MAX({$compare}) as hi")
            ->first();
        return [
            'min' => FilterValue::formatBound($row ? $row->lo : null, $kind),
            'max' => FilterValue::formatBound($row ? $row->hi : null, $kind),
        ];
    }

    /**
     * The DISTINCT values one column of a table holds, resolved to display labels.
     *
     * @param CustomTable $table
     * @param mixed $column  CustomColumn of $table
     * @param callable|null $scope  receives the query builder to narrow it
     * @param int $limit  cardinality cap; over it the list is withheld (capped = true)
     * @return array{options: array<int, array{id:string,name:string}>, capped: bool}
     */
    public static function distinct(CustomTable $table, $column, ?callable $scope = null, int $limit = FilterBarConfig::DEFAULT_MAX_OPTIONS): array
    {
        $rows = self::values($table, $column, $scope, $limit);
        if ($rows['capped']) {
            return ['options' => [], 'capped' => true];
        }
        return self::fromValues($column, $rows['values']);
    }

    /**
     * Stored values as options: in numeric-aware order, with their display labels, same-name
     * options told apart.
     *
     * @param mixed $column  CustomColumn
     * @param string[] $values
     * @return array{options: array<int, array{id:string,name:string}>, capped: bool}
     */
    private static function fromValues($column, array $values): array
    {
        usort($values, function ($a, $b) {
            // numeric-aware: ids sort 1, 2, 10 — not "1", "10", "2"
            return (is_numeric($a) && is_numeric($b)) ? ($a + 0 <=> $b + 0) : strcmp($a, $b);
        });

        $labels = self::labels($column, $values);
        $options = [];
        foreach ($values as $v) {
            $options[] = ['id' => $v, 'name' => (string) ($labels[$v] ?? $v)];
        }
        return ['options' => self::uniqueNames($options), 'capped' => false];
    }

    /**
     * The DISTINCT non-empty values of $column over $table's rows within $scope, as strings,
     * unordered; capped when more than $limit.
     *
     * @return array{values: string[], capped: bool}
     */
    private static function values(CustomTable $table, $column, ?callable $scope, int $limit): array
    {
        $expr = FilterValue::columnExpr($column);
        $query = $table->getValueQuery(); // the value model's query: soft-deleted rows excluded
        if ($scope !== null) {
            $scope($query);
        }
        $values = $query
            ->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''")
            ->selectRaw("{$expr} as v")
            ->distinct()
            ->limit($limit + 1)
            ->pluck('v')
            ->map(function ($v) {
                return (string) $v;
            })
            ->unique()
            ->values()
            ->all();
        if (count($values) > $limit) {
            return ['values' => [], 'capped' => true];
        }
        return ['values' => $values, 'capped' => false];
    }

    /**
     * The master's records as options, in id order — all of them, or with $present (the ids
     * the rows within a scope hold) only those. A deleted record, or an id no record has,
     * is no choice. null when more than $limit records would be listed.
     *
     * @param string[]|null $present
     * @return array<int, array{id:string,name:string}>|null
     */
    private static function masterOptions(CustomTable $master, ?array $present, int $limit): ?array
    {
        if ($present !== null && empty($present)) {
            return [];
        }
        $query = $master->getValueQuery()->orderBy('id');
        if ($present !== null) {
            $query->whereIn('id', $present);
        }
        $records = $query->limit($limit + 1)->get();
        if ($records->count() > $limit) {
            return null;
        }
        $options = [];
        foreach ($records as $record) {
            $options[] = ['id' => (string) $record->id, 'name' => (string) $record->getLabel()];
        }
        return $options;
    }

    /**
     * The items defined on a select / select_valtext column, key => label in the defined
     * order, read the way the engine reads them (CustomColumn::createSelectOptions): one item
     * per line, "key,label" on a valtext column with the label ending at the next comma, a
     * key alone being its own label. null for any other column, or when nothing is defined.
     *
     * @param mixed $column  CustomColumn
     * @return array<string, string>|null
     */
    public static function definedChoices($column): ?array
    {
        $type = $column ? $column->column_type : null;
        if ($type !== ColumnType::SELECT && $type !== ColumnType::SELECT_VALTEXT) {
            return null;
        }
        $valtext = $type === ColumnType::SELECT_VALTEXT;
        $raw = $column->getOption($valtext ? 'select_item_valtext' : 'select_item');
        $lines = is_array($raw) ? $raw : (is_string($raw) ? preg_split('/\r\n|\r|\n/', $raw) : []);
        $defined = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            $splits = $valtext ? explode(',', $line) : [$line];
            $key = mbTrim($splits[0]);
            if ($key === '') {
                continue; // a blank line is no item
            }
            $defined[$key] = count($splits) > 1 ? mbTrim($splits[1]) : $key;
        }
        return empty($defined) ? null : $defined;
    }

    /**
     * The defined items as options, in the defined order; with $present (the values the rows
     * within a scope hold) only those — a value no longer defined is no choice.
     *
     * @param array<string, string> $defined  definedChoices()
     * @param string[]|null $present
     * @return array<int, array{id:string,name:string}>
     */
    public static function definedOptions(array $defined, ?array $present): array
    {
        $keep = $present === null ? null : array_fill_keys(array_map('strval', $present), true);
        $options = [];
        foreach ($defined as $key => $label) {
            if ($keep === null || isset($keep[(string) $key])) {
                $options[] = ['id' => (string) $key, 'name' => (string) $label];
            }
        }
        return $options;
    }

    /**
     * Display labels of stored values: a select_table column stores target ids → the
     * target record's label; a select / select_valtext column stores keys → the defined
     * label.
     *
     * @param mixed $column  CustomColumn
     * @param string[] $values
     * @return array<string, string> value => label (values without a label are absent)
     */
    public static function labels($column, array $values): array
    {
        if (empty($values)) {
            return [];
        }
        $labels = [];
        $target = $column->select_target_table;
        if ($target) {
            foreach ($target->getValueQuery()->whereIn('id', $values)->get() as $record) {
                $labels[(string) $record->id] = (string) $record->getLabel();
            }
            return $labels;
        }
        foreach (self::definedChoices($column) ?? [] as $key => $label) {
            if (in_array((string) $key, $values, true)) {
                $labels[(string) $key] = (string) $label;
            }
        }
        return $labels;
    }

    /**
     * Two options with the same label (two same-name students) get the raw value appended,
     * so every option stays tellable apart.
     */
    private static function uniqueNames(array $options): array
    {
        $counts = array_count_values(array_column($options, 'name'));
        return array_map(function ($opt) use ($counts) {
            if ($counts[$opt['name']] > 1 && $opt['name'] !== $opt['id']) {
                $opt['name'] .= ' #' . $opt['id'];
            }
            return $opt;
        }, $options);
    }
}
