<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;

/**
 * Dashboard ADVANCED filter conditions — the "Detailed Filter" panel of the filter bar.
 *
 * Where the ordinary filter items (df_{column}) are equality pickers driven by a value
 * list, these are free conditions in the shape Power BI's advanced filtering uses:
 *
 *     Field [product]   Condition [contains]   Value [ABC]
 *
 * Request shape (array params, so deep links and back/forward keep working):
 *
 *     dfa[0][c]=product & dfa[0][o]=contains & dfa[0][v]=ABC
 *     dfa[1][c]=customer_name & dfa[1][o]=is_not_blank
 *
 * Every row is ANDed, and ANDed with the ordinary df_ / bf_ filters. A row is used only
 * when its column is a plain identifier that really exists on the table being queried —
 * the same whitelist the equality filters use — so a stale or hand-edited URL can never
 * reach SQL with an unknown column.
 *
 * No config is needed to enable this: the panel lists the source table's own columns.
 * With no dfa params in the request every method here is a no-op, which is what keeps
 * existing dashboards byte-identical.
 */
class AdvancedFilter
{
    /** Operators, in the order the UI lists them. */
    public const OPERATORS = [
        'contains',
        'not_contains',
        'starts_with',
        'not_starts_with',
        'is',
        'is_not',
        'is_blank',
        'is_not_blank',
        'is_empty',
        'is_not_empty',
    ];

    /** Operators that ignore the value box (they test presence, not content). */
    public const VALUELESS = ['is_blank', 'is_not_blank', 'is_empty', 'is_not_empty'];

    /**
     * The advanced conditions carried by the current request, sanitized.
     * Rows missing a column/operator — or carrying an unknown operator, or an empty value
     * for an operator that needs one — are dropped silently (a half-filled UI row is not
     * a filter yet).
     *
     * @return array<int, array{column: string, operator: string, value: string}>
     */
    public static function conditions()
    {
        $raw = request()->input('dfa');
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $column = (string) (array_get($row, 'c') ?? '');
            $operator = (string) (array_get($row, 'o') ?? '');
            $value = array_get($row, 'v');
            $value = is_scalar($value) ? (string) $value : '';

            if (!FilterState::isIdentifier($column)) {
                continue;
            }
            if (!in_array($operator, static::OPERATORS, true)) {
                continue;
            }
            if (!in_array($operator, static::VALUELESS, true) && $value === '') {
                continue;
            }
            $out[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        }
        return $out;
    }

    /** @return bool whether the request carries any usable advanced condition */
    public static function active()
    {
        return count(static::conditions()) > 0;
    }

    /**
     * Column names used by the active conditions (for the "not affected" badge and for
     * deciding whether a box reacts at all).
     *
     * @return string[]
     */
    public static function columns()
    {
        return array_values(array_unique(array_column(static::conditions(), 'column')));
    }

    /**
     * The active conditions that $table can actually honor (its own columns only).
     *
     * @param CustomTable|null $table
     * @return array<int, array{column: string, operator: string, value: string}>
     */
    public static function conditionsOn($table)
    {
        if (is_nullorempty($table)) {
            return [];
        }
        $columns = $table->custom_columns->keyBy('column_name');
        return array_values(array_filter(static::conditions(), function ($condition) use ($columns) {
            return !is_nullorempty($columns->get($condition['column']));
        }));
    }

    /**
     * AND every advanced condition $table can honor onto $model.
     *
     * SQL shape matches the equality filters: the generated index column when the column
     * has one (index-backed on big fact tables), JSON extraction otherwise. Values are
     * always bound; LIKE patterns escape %, _ and the escape character itself, so a literal
     * "50%" searches for "50%" instead of matching everything.
     *
     * @param mixed $model
     * @param CustomTable|null $table
     * @param string[] $except column names to skip (parent-scope queries)
     * @return void
     */
    public static function applyTo($model, $table, array $except = [])
    {
        if (is_nullorempty($table) || is_nullorempty($model)) {
            return;
        }
        $columns = $table->custom_columns->keyBy('column_name');

        foreach (static::conditions() as $condition) {
            $column = $condition['column'];
            if (in_array($column, $except, true)) {
                continue;
            }
            $customColumn = $columns->get($column);
            if (is_nullorempty($customColumn)) {
                continue; // not a column of this table — this box simply does not react
            }
            static::applyCondition(
                $model,
                FilterState::columnExpr($customColumn, $column),
                $condition['operator'],
                $condition['value']
            );
        }
    }

    /**
     * One condition as a where clause.
     *
     * "blank" and "empty" are deliberately different, as in Power BI: blank = no value at
     * all (SQL NULL, or the JSON key absent — which JSON_EXTRACT renders as the string
     * 'null'), empty = present but an empty string. The negative text operators also keep
     * NULL rows ("does not contain X" is true of a row that has no value at all), which a
     * plain NOT LIKE would drop.
     *
     * @param mixed $model
     * @param string $expr
     * @param string $operator
     * @param string $value
     * @return void
     */
    protected static function applyCondition($model, $expr, $operator, $value)
    {
        $like = static::escapeLike($value);

        switch ($operator) {
            case 'contains':
                $model->whereRaw($expr . " LIKE ? ESCAPE '!'", ['%' . $like . '%']);
                break;
            case 'not_contains':
                $model->whereRaw('(' . $expr . ' IS NULL OR ' . $expr . " NOT LIKE ? ESCAPE '!')", ['%' . $like . '%']);
                break;
            case 'starts_with':
                $model->whereRaw($expr . " LIKE ? ESCAPE '!'", [$like . '%']);
                break;
            case 'not_starts_with':
                $model->whereRaw('(' . $expr . ' IS NULL OR ' . $expr . " NOT LIKE ? ESCAPE '!')", [$like . '%']);
                break;
            case 'is':
                $model->whereRaw($expr . ' = ?', [$value]);
                break;
            case 'is_not':
                $model->whereRaw('(' . $expr . ' IS NULL OR ' . $expr . ' <> ?)', [$value]);
                break;
            case 'is_blank':
                $model->whereRaw('(' . $expr . " IS NULL OR " . $expr . " = 'null')");
                break;
            case 'is_not_blank':
                $model->whereRaw('(' . $expr . " IS NOT NULL AND " . $expr . " <> 'null')");
                break;
            case 'is_empty':
                $model->whereRaw($expr . " = ''");
                break;
            case 'is_not_empty':
                $model->whereRaw('(' . $expr . " IS NULL OR " . $expr . " <> '')");
                break;
        }
    }

    /**
     * Escape LIKE metacharacters so a value is matched literally (paired with ESCAPE '!').
     *
     * @param string $value
     * @return string
     */
    protected static function escapeLike($value)
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Stable token of the active conditions, for cache keys / render signatures.
     *
     * @return string '' when nothing is active
     */
    public static function fingerprint()
    {
        $conditions = static::conditions();
        if (empty($conditions)) {
            return '';
        }
        return md5((string) json_encode($conditions));
    }
}
