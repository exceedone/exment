<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomFormBlock;

/**
 * Studio, phase 3: the condition an item of a screen is shown on ("visible_if").
 *
 * "Show the memo when the status is 'paused'": an item is shown only while
 * another item of the screen has (or has not) a value. It is kept in the
 * options of the item of the form, as
 *
 *     "visible_if": {"all": [{"column": "<column name>", "operator": "eq", "value": "paused"}]}
 *
 * - the same in the screen JSON (ScreenDefinition) and in the script of the
 * data page. The builder makes one condition; "all" is a list so that more
 * conditions (and "any") can come later without changing the shape.
 *
 * Operators: eq (is), ne (is not), empty (is empty), not_empty (is not empty).
 *
 * An item with a condition cannot be required: a hidden required item could
 * never be filled in, and the data could not be saved.
 */
class ScreenCondition
{
    public const OPERATORS = ['eq', 'ne', 'empty', 'not_empty'];

    /**
     * The conditions of the posted items, by the index of the item. The
     * target is the index of another posted item ("target").
     *
     * @param array<int, array<string, mixed>> $fields the posted items, in the posted order
     * @return array<int, array{target: int, operator: string, value: string}>
     */
    public static function normalize(array $fields): array
    {
        $result = [];
        foreach (array_values($fields) as $index => $field) {
            $condition = array_get($field, 'visible_if');
            if (!is_array($condition) || !is_numeric(array_get($condition, 'target'))) {
                continue;
            }
            $result[$index] = [
                'target' => intval($condition['target']),
                'operator' => strval(array_get($condition, 'operator', 'eq')),
                'value' => trim(strval(array_get($condition, 'value', ''))),
            ];
        }
        return $result;
    }

    /**
     * The mistakes of the conditions, by "fields.N.visible_if".
     *
     * @param array<int, array{target: int, operator: string, value: string}> $conditions
     * @param array<int, bool> $required whether each posted item is required
     * @return array<string, string>
     */
    public static function validate(array $conditions, array $required): array
    {
        $errors = [];
        foreach ($conditions as $index => $condition) {
            $key = "fields.{$index}.visible_if";
            if ($condition['target'] === $index || !array_key_exists($condition['target'], $required)) {
                $errors[$key] = exmtrans('studio.error.condition_target');
            } elseif (!in_array($condition['operator'], static::OPERATORS, true)) {
                $errors[$key] = exmtrans('studio.error.condition_operator');
            } elseif (in_array($condition['operator'], ['eq', 'ne'], true) && $condition['value'] === '') {
                $errors[$key] = exmtrans('studio.error.condition_value');
            } elseif ($required[$index] ?? false) {
                $errors[$key] = exmtrans('studio.error.condition_required');
            }
        }
        return $errors;
    }

    /**
     * Keep the conditions in the options of the items of a form block.
     *
     * @param array<int, int> $columnIds the custom column of each posted item
     * @param array<int, array{target: int, operator: string, value: string}> $conditions
     */
    public static function apply(CustomFormBlock $block, array $columnIds, array $conditions): void
    {
        if (count($conditions) === 0) {
            return;
        }
        $formColumns = $block->custom_form_columns()->get()->keyBy(function ($formColumn) {
            return intval($formColumn->form_column_target_id);
        });
        foreach ($conditions as $index => $condition) {
            $formColumn = $formColumns->get(intval($columnIds[$index] ?? 0));
            $target = CustomColumn::getEloquent($columnIds[$condition['target']] ?? null);
            if (!isset($formColumn) || !isset($target)) {
                continue;
            }
            $options = $formColumn->options ?? [];
            $options = is_array($options) ? $options : [];
            $options['visible_if'] = static::shape(strval($target->column_name), $condition['operator'], $condition['value']);
            $formColumn->options = $options;
            $formColumn->save();
        }
    }

    /**
     * @return array{all: array<int, array{column: string, operator: string, value: string|null}>}
     */
    public static function shape(string $column, string $operator, string $value): array
    {
        return ['all' => [[
            'column' => $column,
            'operator' => $operator,
            'value' => in_array($operator, ['eq', 'ne'], true) ? $value : null,
        ]]];
    }

    /**
     * The conditions as given out (screen JSON, script of the data page): each with
     * column, operator and value in this order. The JSON column of the database
     * keeps the keys in its own order.
     *
     * @param mixed $visibleIf the "visible_if" option of an item
     * @return array<int, array{column: string, operator: string, value: string|null}>|null null when the item has none
     */
    public static function conditionsOf($visibleIf): ?array
    {
        if (!is_array($visibleIf) || !is_array(array_get($visibleIf, 'all'))) {
            return null;
        }
        $result = [];
        foreach ($visibleIf['all'] as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $value = array_get($condition, 'value');
            $result[] = [
                'column' => strval(array_get($condition, 'column')),
                'operator' => strval(array_get($condition, 'operator')),
                'value' => is_null($value) ? null : strval($value),
            ];
        }
        return $result;
    }

    /**
     * The conditions of the items of a form, for the script of the data page:
     * [{name: <column name of the item>, all: [...]}].
     *
     * @return array<int, array{name: string, all: array<int, mixed>}>
     */
    public static function rulesOf(CustomFormBlock $block): array
    {
        $rules = [];
        foreach ($block->custom_form_columns as $formColumn) {
            $options = $formColumn->options;
            $conditions = static::conditionsOf(is_array($options) ? array_get($options, 'visible_if') : null);
            $column = $formColumn->custom_column;
            if ($conditions === null || !isset($column)) {
                continue;
            }
            $rules[] = ['name' => strval($column->column_name), 'all' => $conditions];
        }
        return $rules;
    }
}
