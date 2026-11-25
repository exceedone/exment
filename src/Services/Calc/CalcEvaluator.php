<?php

namespace Exceedone\Exment\Services\Calc;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

/**
 * The calc formulas of a record, worked out on the server (ISS02870).
 *
 * The data form works out a calc column in the browser (calc.ts) when a value it reads
 * changes. A record made without that form - the input dialog of a data copy, the
 * "copy from a record" screen - kept the copied numbers. This does what calc.ts does:
 *
 * - ${value:column}, ${select_table:column.target}, ${parent:column},
 *   ${sum:child_table.column}, ${count:child_table} are replaced by their numbers;
 * - a value it reads that is empty (or not a number) leaves the column as it is
 *   (calc.ts does not calculate then either);
 * - the rest is + - * / and brackets on numbers, read here (no eval); a division by
 *   zero gives nothing;
 * - an integer column takes the rounded result, a decimal or currency column the
 *   result rounded to its decimal digits (as CommonEvent.setValue).
 *
 * Formulas that read one another are worked out again until nothing changes.
 */
class CalcEvaluator
{
    /** The column types that may have a calc formula. */
    public const NUMBER_TYPES = [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY];

    /**
     * Work out the calc columns of a record (not saved).
     *
     * @return array<string, mixed> the columns that changed, with their new values
     */
    public static function recalculate(CustomValue $custom_value): array
    {
        $custom_table = $custom_value->custom_table;
        if (!isset($custom_table)) {
            return [];
        }
        $columns = static::calcColumns($custom_table);
        if (count($columns) === 0) {
            return [];
        }

        $changed = [];
        // a formula may read the result of another one: again until nothing changes
        for ($pass = 0; $pass <= count($columns); $pass++) {
            $any = false;
            foreach ($columns as $custom_column) {
                $result = static::evaluate(strval($custom_column->getOption('calc_formula')), $custom_value, $custom_table);
                if (is_null($result)) {
                    continue;
                }
                $result = static::round($result, $custom_column);
                $now = array_get($custom_value->value, $custom_column->column_name);
                if (!is_null($now) && is_numeric($now) && (string)(0 + $now) === (string)(0 + $result)) {
                    continue;
                }
                $custom_value->setValue($custom_column->column_name, $result);
                $changed[$custom_column->column_name] = $result;
                $any = true;
            }
            if (!$any) {
                break;
            }
        }
        return $changed;
    }

    /**
     * The number columns of a table that have a calc formula.
     *
     * @return array<int, CustomColumn>
     */
    public static function calcColumns(CustomTable $custom_table): array
    {
        $columns = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (!in_array($custom_column->column_type, static::NUMBER_TYPES, true)) {
                continue;
            }
            $formula = $custom_column->getOption('calc_formula');
            if (!is_string($formula) || trim($formula) === '' || $formula === 'null') {
                continue;
            }
            $columns[] = $custom_column;
        }
        return $columns;
    }

    /**
     * The result of a formula for a record, or null when it is not worked out (a value it
     * reads is empty, the formula is not one of numbers and + - * / ( ), a division by zero).
     */
    public static function evaluate(string $formula, CustomValue $custom_value, ?CustomTable $custom_table = null): ?float
    {
        $custom_table = $custom_table ?? $custom_value->custom_table;
        if (!isset($custom_table) || trim($formula) === '') {
            return null;
        }
        $expression = $formula;
        foreach (CalcService::getCalcParamsFromString($formula, $custom_table) as $param) {
            $number = static::paramValue($param, $custom_value, $custom_table);
            if (is_null($number)) {
                return null;
            }
            $expression = str_replace(strval($param['key']), '(' . static::numberString($number) . ')', $expression);
        }
        return static::calculate($expression);
    }

    /**
     * The number a part of a formula stands for, or null.
     *
     * @param array<string, mixed> $param one of CalcService::getCalcParamsFromString
     */
    protected static function paramValue(array $param, CustomValue $custom_value, CustomTable $custom_table): ?float
    {
        $splits = explode('.', strval(array_get($param, 'inner_key')));
        $column_name = strval(array_get($param, 'formula_column'));
        switch (array_get($param, 'type')) {
            case 'dynamic':
                return static::number(array_get($custom_value->value, $column_name));

            case 'select_table':
                $pivot = CustomColumn::getEloquent(strval(array_get($param, 'select_pivot_column')), $custom_table);
                $target_table = isset($pivot) ? CustomTable::getEloquent($pivot->getOption('select_target_table')) : null;
                $id = array_get($custom_value->value, strval(array_get($param, 'select_pivot_column')));
                if (!isset($target_table) || !is_numeric($id)) {
                    return null;
                }
                $target = getModelName($target_table)::find($id);
                return isset($target) ? static::number(array_get($target->value, $column_name)) : null;

            case 'parent':
                if (is_nullorempty($custom_value->parent_type) || is_nullorempty($custom_value->parent_id)) {
                    return null;
                }
                $parent_table = CustomTable::getEloquent($custom_value->parent_type);
                $parent = isset($parent_table) ? getModelName($parent_table)::find($custom_value->parent_id) : null;
                return isset($parent) ? static::number(array_get($parent->value, $column_name)) : null;

            case 'sum':
            case 'summary':
            case 'count':
                $child_table = CustomTable::getEloquent($splits[0]);
                if (!isset($child_table)) {
                    return null;
                }
                // a record not saved yet has no children (as an empty list of rows on the form)
                $children = [];
                if ($custom_value->exists) {
                    $found = $custom_value->getChildrenValues($child_table);
                    foreach (is_iterable($found) ? $found : [] as $child) {
                        $children[] = $child;
                    }
                }
                if (array_get($param, 'type') === 'count') {
                    return floatval(count($children));
                }
                $sum = 0.0;
                foreach ($children as $child) {
                    $number = static::number(array_get($child->value, $column_name));
                    $sum += $number ?? 0.0;
                }
                return $sum;
        }
        return null;
    }

    /**
     * A stored value as a number (commas taken out), null when empty or not a number.
     *
     * @param mixed $value
     */
    protected static function number($value): ?float
    {
        if (is_null($value) || is_array($value) || is_object($value)) {
            return null;
        }
        $value = str_replace(',', '', trim(strval($value)));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return floatval($value);
    }

    protected static function numberString(float $number): string
    {
        $text = rtrim(rtrim(sprintf('%.12F', $number), '0'), '.');
        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * The result as the column keeps it: an integer rounded, a decimal or currency to its digits.
     *
     * @return int|float
     */
    protected static function round(float $result, CustomColumn $custom_column)
    {
        if ($custom_column->column_type === ColumnType::INTEGER) {
            return intval(round($result));
        }
        $digits = $custom_column->getOption('decimal_digit');
        if (is_numeric($digits)) {
            return round($result, intval($digits));
        }
        return $result;
    }

    // ---------------------------------------------------------------- the arithmetic

    /**
     * + - * / and brackets on numbers, with the usual precedence; null when it is anything else.
     */
    public static function calculate(string $expression): ?float
    {
        if (preg_match('/^[0-9.+\-*\/()\s]*$/', $expression) !== 1) {
            return null;
        }
        $tokens = [];
        preg_match_all('/\d+(?:\.\d+)?|\.\d+|[+\-*\/()]/', $expression, $matched);
        $tokens = $matched[0];
        if (count($tokens) === 0) {
            return null;
        }
        $position = 0;
        try {
            $result = static::parseExpression($tokens, $position);
        } catch (\RuntimeException $e) {
            return null;
        }
        if ($position !== count($tokens) || is_nan($result) || is_infinite($result)) {
            return null;
        }
        return $result;
    }

    /**
     * @param array<int, string> $tokens
     */
    protected static function parseExpression(array $tokens, int &$position): float
    {
        $value = static::parseTerm($tokens, $position);
        while (isset($tokens[$position]) && in_array($tokens[$position], ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $right = static::parseTerm($tokens, $position);
            $value = $operator === '+' ? $value + $right : $value - $right;
        }
        return $value;
    }

    /**
     * @param array<int, string> $tokens
     */
    protected static function parseTerm(array $tokens, int &$position): float
    {
        $value = static::parseFactor($tokens, $position);
        while (isset($tokens[$position]) && in_array($tokens[$position], ['*', '/'], true)) {
            $operator = $tokens[$position++];
            $right = static::parseFactor($tokens, $position);
            if ($operator === '/') {
                if ($right == 0.0) {
                    throw new \RuntimeException('division by zero');
                }
                $value = $value / $right;
            } else {
                $value = $value * $right;
            }
        }
        return $value;
    }

    /**
     * @param array<int, string> $tokens
     */
    protected static function parseFactor(array $tokens, int &$position): float
    {
        $token = $tokens[$position] ?? null;
        if ($token === '+' || $token === '-') {
            $position++;
            $value = static::parseFactor($tokens, $position);
            return $token === '-' ? -$value : $value;
        }
        if ($token === '(') {
            $position++;
            $value = static::parseExpression($tokens, $position);
            if (($tokens[$position] ?? null) !== ')') {
                throw new \RuntimeException('bracket');
            }
            $position++;
            return $value;
        }
        if (is_string($token) && is_numeric($token)) {
            $position++;
            return floatval($token);
        }
        throw new \RuntimeException('number expected');
    }
}
