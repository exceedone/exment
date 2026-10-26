<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
     * A list column (FilterValue::isMultiple) holds combinations of values — ["1", "2"],
     * ["2", "3"] — more of them than values: up to this many per option are read to list
     * the values they hold.
     */
    private const LISTS_PER_OPTION = 10;

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
        $kind = FilterValue::kind($column);
        // the whole table of an indexed column: the ends of its DISTINCT values, read off the
        // index (values()), while they fit the option cap — 1 ms against a MIN / MAX of a CAST
        // over every row (1.3 s on 384k rows); past the cap the rows are scanned as below
        if ($scope === null && self::indexScannable($column)) {
            $rows = self::indexValues($table, $column, FilterBarConfig::DEFAULT_MAX_OPTIONS);
            if ($rows !== null && !$rows['capped']) {
                [$lo, $hi] = self::ends($rows['values'], $kind);
                return ['min' => FilterValue::formatBound($lo, $kind), 'max' => FilterValue::formatBound($hi, $kind)];
            }
        }
        $expr = FilterValue::columnExpr($column);
        $compare = FilterValue::compareExpr($column);
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
     * @param mixed $column  CustomColumn
     * @return array{values: string[], capped: bool}
     */
    private static function values(CustomTable $table, $column, ?callable $scope, int $limit): array
    {
        if ($scope === null && self::indexScannable($column)) {
            $rows = self::indexValues($table, $column, $limit);
            // past the cap, values only soft-deleted rows hold may be what tips it: then the
            // live rows are counted the plain way below
            if ($rows !== null && (!$rows['capped'] || !self::hasTrashed($table))) {
                return $rows;
            }
        }
        $query = $table->getValueQuery(); // the value model's query: soft-deleted rows excluded
        if ($scope !== null) {
            $scope($query);
        }
        return self::distinctValues($query, $column, $limit);
    }

    /**
     * values() over the whole table of an indexed column, read straight off the column's
     * index (MySQL / MariaDB loose index scan: 1 ms on 384k rows). The value model's
     * soft-delete predicate would make the optimizer walk the deleted_at index over half the
     * table instead (1.2 s), so the rows are read with it off and the values only
     * soft-deleted rows hold are dropped afterwards (liveValues). A capped result may count
     * such values too. Null when the value model has no soft deletes to turn off, or when its
     * query narrows the rows further for this user (CustomValueModelScope): liveValues
     * probes the table itself, which answers for rows such a user may not see.
     *
     * @param mixed $column  CustomColumn
     * @return array{values: string[], capped: bool}|null
     */
    private static function indexValues(CustomTable $table, $column, int $limit): ?array
    {
        $query = $table->getValueQuery();
        if (!$query->hasMacro('withTrashed')) {
            return null;
        }
        $query->withoutGlobalScope(SoftDeletingScope::class);
        $base = $query->toBase();
        if (!empty($base->wheres) || !empty($base->joins)) {
            return null;
        }
        $rows = self::distinctValues($query, $column, $limit);
        if (!$rows['capped']) {
            $rows['values'] = self::liveValues($table, $column, $rows['values']);
        }
        return $rows;
    }

    /**
     * The DISTINCT non-empty values of $column the rows of $query hold, as strings; capped
     * when more than $limit.
     *
     * @param mixed $query  query on the table's rows
     * @param mixed $column  CustomColumn
     * @return array{values: string[], capped: bool}
     */
    private static function distinctValues($query, $column, int $limit): array
    {
        $expr = FilterValue::columnExpr($column);
        $multiple = FilterValue::isMultiple($column);
        $read = $multiple ? $limit * self::LISTS_PER_OPTION : $limit;
        $stored = $query
            ->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''")
            ->selectRaw("{$expr} as v")
            ->distinct()
            ->limit($read + 1)
            ->pluck('v');
        if ($stored->count() > $read) {
            return ['values' => [], 'capped' => true];
        }
        // a list column's values are the items of the lists it holds
        $values = $stored
            ->flatMap(function ($v) use ($multiple) {
                return $multiple ? FilterValue::listItems($v) : [(string) $v];
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
     * Whether the whole-table DISTINCT of $column can be read off its index: the column keeps
     * an indexed generated column, on MySQL / MariaDB (loose index scan; SQL Server, the other
     * database Exment runs on, has neither it nor the USE INDEX syntax of liveValues).
     *
     * @param mixed $column  CustomColumn
     */
    private static function indexScannable($column): bool
    {
        // a list column's index holds whole lists, no single value to probe (liveValues)
        return (bool) $column->index_enabled && !\Exment::isSqlServer() && !FilterValue::isMultiple($column);
    }

    /**
     * Whether $table holds a soft-deleted row (one indexed lookup).
     */
    private static function hasTrashed(CustomTable $table): bool
    {
        return \DB::table(getDBTableName($table))->whereNotNull('deleted_at')->exists();
    }

    /**
     * The values among $values that at least one live row holds. Nothing to check while the
     * table has no soft-deleted row (hasTrashed); otherwise one index-hinted existence
     * probe per value (0.2 ms each — without the hint the optimizer prefers the deleted_at
     * index again), the plain probe when the hint is refused (index renamed or gone).
     *
     * @param mixed $column  CustomColumn
     * @param string[] $values
     * @return string[]
     */
    private static function liveValues(CustomTable $table, $column, array $values): array
    {
        if (empty($values) || !self::hasTrashed($table)) {
            return $values;
        }
        $name = $column->getIndexColumnName(false);
        $grammar = \DB::getQueryGrammar();
        $hinted = \DB::raw($grammar->wrap(getDBTableName($table)) . ' USE INDEX (' . $grammar->wrap('index_' . $name) . ')');
        $live = function ($from) use ($name, $values) {
            return array_values(array_filter($values, function ($v) use ($from, $name) {
                return \DB::table($from)->where($name, $v)->whereNull('deleted_at')->exists();
            }));
        };
        try {
            return $live($hinted);
        } catch (\Illuminate\Database\QueryException $e) {
            return $live(getDBTableName($table));
        }
    }

    /**
     * The lowest and highest of $values the way a range compares them (FilterValue::compareExpr):
     * numbers as numbers (text that is no number is skipped), anything else as text.
     *
     * @param string[] $values
     * @return array{0: ?string, 1: ?string}  [min, max], [null, null] when nothing compares
     */
    public static function ends(array $values, string $kind): array
    {
        $values = array_values(array_filter($values, function ($v) use ($kind) {
            return is_scalar($v) && ($kind !== 'number' || is_numeric($v)) && (string) $v !== '';
        }));
        if (empty($values)) {
            return [null, null];
        }
        usort($values, function ($a, $b) use ($kind) {
            return $kind === 'number' ? ((float) $a <=> (float) $b) : strcmp((string) $a, (string) $b);
        });
        return [(string) $values[0], (string) $values[count($values) - 1]];
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
        if ($present !== null) {
            $present = self::ids($present);
            if (empty($present)) {
                return [];
            }
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
            if (!$record instanceof CustomValue) {
                continue; // the value query yields CustomValue rows alone
            }
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
     * @return array<array-key, string>|null
     */
    public static function definedChoices($column): ?array
    {
        $type = $column ? $column->column_type : null;
        if ($type !== ColumnType::SELECT && $type !== ColumnType::SELECT_VALTEXT) {
            return null;
        }
        $valtext = $type === ColumnType::SELECT_VALTEXT;
        $raw = $column->getOption($valtext ? 'select_item_valtext' : 'select_item');
        $lines = is_array($raw) ? $raw : (is_string($raw) ? (preg_split('/\r\n|\r|\n/', $raw) ?: []) : []);
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
     * @param array<array-key, string> $defined  definedChoices()
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
     * label; a yes / no or boolean column its stored value → the label the engine shows
     * for it (Yesno / Boolean column items).
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
            $ids = self::ids($values);
            if (!empty($ids)) {
                foreach ($target->getValueQuery()->whereIn('id', $ids)->get() as $record) {
                    $labels[(string) $record->id] = (string) $record->getLabel();
                }
            }
            return $labels;
        }
        foreach (self::definedChoices($column) ?? self::switchLabels($column) as $key => $label) {
            if (in_array((string) $key, $values, true)) {
                $labels[(string) $key] = (string) $label;
            }
        }
        return $labels;
    }

    /**
     * The stored values of a yes / no or boolean column with their labels: 1 / 0 as YES / NO
     * (getYesNo), a boolean's true / false value as its true / false label; empty for any
     * other column.
     *
     * @param mixed $column  CustomColumn
     * @return array<array-key, string>
     */
    private static function switchLabels($column): array
    {
        $type = $column ? $column->column_type : null;
        if ($type === ColumnType::YESNO) {
            return ['1' => getYesNo(1), '0' => getYesNo(0)];
        }
        if ($type !== ColumnType::BOOLEAN) {
            return [];
        }
        $labels = [];
        foreach (['true', 'false'] as $side) {
            $value = $column->getOption("{$side}_value");
            if (is_scalar($value) && (string) $value !== '') {
                $labels[(string) $value] = (string) $column->getOption("{$side}_label");
            }
        }
        return $labels;
    }

    /**
     * The values among $values that can be a record id. A master is read by id, and SQL
     * Server fails the whole query on a text it cannot compare with the integer id — an id
     * typed into the URL, a label typed as an item's default.
     *
     * @param string[] $values
     * @return string[]
     */
    private static function ids(array $values): array
    {
        return array_values(array_filter($values, function ($v) {
            return preg_match('/\A[0-9]+\z/', (string) $v) === 1;
        }));
    }

    /**
     * Two options with the same label (two same-name students) get the raw value appended,
     * so every option stays tellable apart.
     *
     * @param array<int, array{id:string,name:string}> $options
     * @return array<int, array{id:string,name:string}>
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
