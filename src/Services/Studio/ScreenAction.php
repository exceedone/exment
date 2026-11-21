<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\OperationUpdateType;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomOperationColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * Studio, phase 4: a button of a screen that does something when it is pressed.
 *
 * "Done": set the status of the data to "done". A button is kept as a data
 * operation of the table that is run by a button (CustomOperation, type
 * "button"), the same as one made on the operation setting page: the page of a
 * data shows it, and it is changed or deleted on that setting page. A button
 * the builder makes sets one item to one value, and is not shown on a data
 * whose item has that value already.
 *
 * Posted with the items of the screen, as
 *
 *     "actions": [{"label": "完了にする", "target": <index of the item>, "value": "完了"}]
 */
class ScreenAction
{
    public const MAX_ACTIONS = 5;
    public const MAX_VALUE_LENGTH = 100;

    /**
     * The item types a button can set: the value is written as it is.
     */
    public const TARGET_TYPES = [
        ColumnType::SELECT,
        ColumnType::SELECT_VALTEXT,
        ColumnType::YESNO,
        ColumnType::TEXT,
        ColumnType::INTEGER,
    ];

    /**
     * @param mixed $actions the posted buttons (JSON string or array)
     * @return array<int, array{label: string, target: int, value: string}>
     */
    public static function normalize($actions): array
    {
        if (is_string($actions)) {
            $actions = json_decode($actions, true);
        }
        $result = [];
        foreach (is_array($actions) ? array_values($actions) : [] as $action) {
            if (!is_array($action)) {
                continue;
            }
            $result[] = [
                'label' => trim(strval(array_get($action, 'label', ''))),
                'target' => is_numeric(array_get($action, 'target')) ? intval($action['target']) : -1,
                'value' => trim(strval(array_get($action, 'value', ''))),
            ];
        }
        return $result;
    }

    /**
     * The mistakes of the buttons, by "actions.N.label" / "actions.N.target" / "actions.N.value".
     *
     * @param array<int, array{label: string, target: int, value: string}> $actions
     * @param array<int, array{type: string, choices: array<int, string>|null}> $targets each posted item: its column type, and its choices (null: not a choice)
     * @return array<string, string>
     */
    public static function validate(array $actions, array $targets): array
    {
        $errors = [];
        if (count($actions) > static::MAX_ACTIONS) {
            $errors['actions'] = sprintf(exmtrans('studio.error.too_many_actions'), static::MAX_ACTIONS);
            return $errors;
        }
        foreach ($actions as $index => $action) {
            $key = "actions.{$index}";
            if ($action['label'] === '') {
                $errors["{$key}.label"] = exmtrans('studio.error.required');
            } elseif (mb_strlen($action['label']) > BlankFormBuilder::MAX_VIEW_NAME_LENGTH) {
                $errors["{$key}.label"] = sprintf(exmtrans('common.help.max_length'), BlankFormBuilder::MAX_VIEW_NAME_LENGTH);
            }

            $target = $targets[$action['target']] ?? null;
            if ($target === null || !in_array($target['type'], static::TARGET_TYPES, true)) {
                $errors["{$key}.target"] = exmtrans('studio.error.action_target');
                continue;
            }
            $value = $action['value'];
            if ($value === '') {
                $errors["{$key}.value"] = exmtrans('studio.error.action_value');
            } elseif ($target['type'] === ColumnType::YESNO && !in_array($value, ['0', '1'], true)) {
                $errors["{$key}.value"] = exmtrans('studio.error.action_value');
            } elseif ($target['type'] === ColumnType::INTEGER && !preg_match('/\A-?[0-9]{1,15}\z/', $value)) {
                $errors["{$key}.value"] = exmtrans('studio.error.action_integer');
            } elseif (is_array($target['choices']) && !in_array($value, $target['choices'], true)) {
                $errors["{$key}.value"] = exmtrans('studio.error.action_choice');
            } elseif (mb_strlen($value) > static::MAX_VALUE_LENGTH) {
                $errors["{$key}.value"] = sprintf(exmtrans('common.help.max_length'), static::MAX_VALUE_LENGTH);
            }
        }
        return $errors;
    }

    /**
     * The choices of a column, as the button keeps them (the values, not the labels).
     *
     * @return array<int, string>|null null when the column is not a choice
     */
    public static function choicesOf(CustomColumn $column): ?array
    {
        if (!in_array($column->column_type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT], true)) {
            return null;
        }
        return array_map('strval', array_keys($column->createSelectOptions()));
    }

    /**
     * Make the buttons: one data operation of the table each.
     *
     * @param array<int, int> $columnIds the custom column of each posted item
     * @param array<int, array{label: string, target: int, value: string}> $actions buttons validate() found no mistake in
     * @return array<int, CustomOperation>
     */
    public static function build(CustomTable $table, array $columnIds, array $actions): array
    {
        $result = [];
        foreach ($actions as $action) {
            $column = CustomColumn::getEloquent($columnIds[$action['target']] ?? null);
            if (!$column instanceof CustomColumn) {
                continue;
            }
            $operation = CustomOperation::create([
                'custom_table_id' => $table->id,
                'operation_type' => [CustomOperationType::BUTTON],
                'operation_name' => $action['label'],
                'options' => [
                    'button_label' => $action['label'],
                    'condition_join' => 'and',
                    'active_flg' => 1,
                ],
            ]);
            CustomOperationColumn::create([
                'custom_operation_id' => $operation->id,
                'operation_column_type' => CopyColumnType::DEFAULT,
                'view_column_type' => ConditionType::COLUMN,
                'view_column_target_id' => $column->id,
                'update_value_text' => $action['value'],
                'options' => ['operation_update_type' => OperationUpdateType::DEFAULT],
            ]);
            // not on a data that has the value already: "done" is not pressed twice
            [$key, $value] = static::notSetCondition(strval($column->column_type), $action['value']);
            Condition::create([
                'morph_type' => 'custom_operation',
                'morph_id' => $operation->id,
                'condition_type' => ConditionType::COLUMN,
                'target_column_id' => $column->id,
                'condition_key' => $key,
                'condition_value' => $value,
            ]);
            $result[] = $operation;
        }
        return $result;
    }

    /**
     * The condition "the item does not have the value", as the conditions of Exment write it.
     *
     * @return array{0: int, 1: string}
     */
    protected static function notSetCondition(string $type, string $value): array
    {
        switch ($type) {
            case ColumnType::SELECT:
            case ColumnType::SELECT_VALTEXT:
                return [FilterOption::SELECT_NOT_EXISTS, $value];
            case ColumnType::YESNO:
                // a yes/no is only compared with "is"
                return [FilterOption::EQ, $value === '1' ? '0' : '1'];
        }
        return [FilterOption::NE, $value];
    }

    /**
     * The buttons of a table, for the screen JSON: the data operations run by a
     * button that are active, each with the items it sets.
     *
     * @return array<int, array{id: string, label: string, kind: string, set: array<int, array<string, string|null>>}>
     */
    public static function actionsOf(CustomTable $table): array
    {
        $result = [];
        foreach ($table->operations as $operation) {
            // a button that asks for values first (a dialog) is not given out yet
            if (!$operation->active_flg || !$operation->matchOperationType([CustomOperationType::BUTTON])
                || count($operation->custom_operation_input_columns) > 0) {
                continue;
            }
            $set = [];
            foreach ($operation->custom_operation_columns as $operationColumn) {
                $column = $operationColumn->custom_column;
                if (!$column instanceof CustomColumn) {
                    continue;
                }
                $value = $operationColumn->update_value_text;
                $item = ['column' => strval($column->column_name), 'value' => is_nullorempty($value) ? null : strval($value)];
                // a value of the moment: "login_user", "execute_datetime"... (OperationValueType)
                if (isMatchString($operationColumn->getOption('operation_update_type'), OperationUpdateType::SYSTEM)) {
                    $item = ['column' => $item['column'], 'value' => null, 'system' => $item['value']];
                }
                $set[] = $item;
            }
            $result[] = [
                'id' => strval($operation->suuid),
                'label' => strval($operation->getOption('button_label') ?? $operation->operation_name),
                'kind' => 'update',
                'set' => $set,
            ];
        }
        return $result;
    }
}
