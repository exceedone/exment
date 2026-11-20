<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * Studio, phase 3: an input screen as JSON, for the phone app (phase 6) and other clients.
 *
 * The app draws a screen made in the Studio (or on the form setting page)
 * from this, and reads and writes the data through the data API. The shape is
 * a promise to the apps that are installed: it is versioned, and version 1
 * only grows (new keys, new kinds of items); nothing in it changes meaning or
 * goes away. An app skips an item kind it does not know.
 *
 * Format "exment.screen", version 1:
 *
 *     {
 *       "format": "exment.screen",
 *       "version": 1,
 *       "screen": {"id": "<form suuid>", "name": "...", "default": true},
 *       "table": {"name": "customers", "label": "顧客"},
 *       "permissions": {"create": true, "edit": true},
 *       "blocks": [
 *         {
 *           "type": "default" | "one_to_many" | "many_to_many",
 *           "table": "<table name>",       // the table the block enters data to
 *           "label": "...",                 // the name of the block; null for the default block
 *           "rows": [                       // top to bottom
 *             {"columns": [                 // left to right; one under another on a narrow screen
 *               {"width": 1-4, "items": [  // the width of the column: the columns of a row add up to 4 or less
 *                 item, ...                 // top to bottom
 *               ]}
 *             ]}
 *           ]
 *         }
 *       ]
 *     }
 *
 * Items:
 *
 *     {"kind": "field", "name": "<column name>", "label": "...", "type": "<column type>",
 *      "required": bool, "multiple": bool, "help": string|null, "default": mixed|null,
 *      "choices": [{"value": "...", "label": "..."}],   // select, select_valtext and yesno only
 *      "reference": {"table": "<table name>"},          // select_table, user and organization only
 *      "formula": "${value:quantity} * ${value:price}",  // a calculated item only: ${value:<column>}, ${sum:<child table>.<column>}
 *      "visible_if": {"all": [{"column": "<column name>", "operator": "eq" | "ne" | "empty" | "not_empty",
 *                              "value": "..." | null}]}}  // shown only while all hold (ScreenCondition); absent: always shown
 *     {"kind": "system", "name": "id" | "created_at" | ...}   // shown when a data is opened, not entered
 *     {"kind": "header", "text": "..."}
 *     {"kind": "text", "text": "..."}                         // an explanation
 *     {"kind": "html", "html": "..."}                         // as written by the administrator
 *     {"kind": "image", "url": "..."}
 *     {"kind": "line"}
 */
class ScreenDefinition
{
    public const FORMAT = 'exment.screen';
    public const VERSION = 1;

    /**
     * The screens (forms) of the tables whose data the login user may open.
     *
     * @return array<int, array{id: string, name: string, default: bool, table: string, table_label: string}>
     */
    public static function screens(?string $tableName = null): array
    {
        $query = CustomForm::with('custom_table')->orderBy('custom_table_id')->orderBy('id');
        if ($tableName !== null) {
            $table = CustomTable::getEloquent($tableName);
            if (!isset($table)) {
                return [];
            }
            $query->where('custom_table_id', $table->id);
        }

        $result = [];
        foreach ($query->get() as $form) {
            $table = $form->custom_table;
            if (!isset($table) || !$table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                continue;
            }
            $result[] = [
                'id' => strval($form->suuid),
                'name' => strval($form->form_view_name),
                'default' => boolval($form->default_flg),
                'table' => strval($table->table_name),
                'table_label' => strval($table->table_view_name),
            ];
        }
        return $result;
    }

    /**
     * The screen as JSON (an array to encode).
     *
     * @return array<string, mixed>
     */
    public static function of(CustomForm $form): array
    {
        $table = $form->custom_table;
        $blocks = [];
        foreach ($form->custom_form_blocks()->orderBy('id')->get() as $block) {
            /** @var CustomFormBlock $block */
            if (!boolval($block->available)) {
                continue;
            }
            $blocks[] = static::block($block);
        }

        return [
            'format' => static::FORMAT,
            'version' => static::VERSION,
            'screen' => [
                'id' => strval($form->suuid),
                'name' => strval($form->form_view_name),
                'default' => boolval($form->default_flg),
            ],
            'table' => [
                'name' => strval($table->table_name),
                'label' => strval($table->table_view_name),
            ],
            'permissions' => [
                'create' => $table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE),
                'edit' => $table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE),
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function block(CustomFormBlock $block): array
    {
        $types = [
            FormBlockType::DEFAULT => 'default',
            FormBlockType::ONE_TO_MANY => 'one_to_many',
            FormBlockType::MANY_TO_MANY => 'many_to_many',
        ];
        $target = CustomTable::getEloquent($block->form_block_target_table_id);

        // rows by row_no, columns by column_no, items by order
        $grid = [];
        $columns = $block->custom_form_columns()->orderBy('row_no')->orderBy('column_no')->orderBy('order')->orderBy('id')->get();
        foreach ($columns as $formColumn) {
            /** @var CustomFormColumn $formColumn */
            $item = static::item($formColumn);
            if ($item === null) {
                continue;
            }
            $row = intval($formColumn->row_no ?? 1);
            $column = intval($formColumn->column_no ?? 1);
            if (!isset($grid[$row][$column])) {
                $grid[$row][$column] = ['width' => max(1, min(4, intval($formColumn->width ?? 4))), 'items' => []];
            }
            $grid[$row][$column]['items'][] = $item;
        }
        ksort($grid);
        $rows = [];
        foreach ($grid as $cells) {
            ksort($cells);
            $rows[] = ['columns' => array_values($cells)];
        }

        return [
            'type' => $types[intval($block->form_block_type)] ?? 'default',
            'table' => isset($target) ? strval($target->table_name) : null,
            'label' => intval($block->form_block_type) === FormBlockType::DEFAULT || is_nullorempty($block->form_block_view_name)
                ? null : strval($block->form_block_view_name),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>|null null for an item the screen does not show
     */
    protected static function item(CustomFormColumn $formColumn): ?array
    {
        $type = intval($formColumn->form_column_type);
        $options = $formColumn->options ?? [];

        if ($type === FormColumnType::COLUMN) {
            $column = CustomColumn::getEloquent($formColumn->form_column_target_id);
            return isset($column) ? static::field($column, is_array($options) ? $options : []) : null;
        }
        if ($type === FormColumnType::SYSTEM) {
            $name = array_get(\Exceedone\Exment\Enums\SystemColumn::getOption(['id' => $formColumn->form_column_target_id]), 'name');
            return isset($name) ? ['kind' => 'system', 'name' => strval($name)] : null;
        }

        $other = FormColumnType::getOption(['id' => $formColumn->form_column_target_id]);
        switch (array_get($other, 'column_name')) {
            case 'header':
                return ['kind' => 'header', 'text' => strval(array_get($options, 'text'))];
            case 'explain':
                return ['kind' => 'text', 'text' => strval(array_get($options, 'text'))];
            case 'html':
            case 'exhtml':
                return ['kind' => 'html', 'html' => strval(array_get($options, 'html'))];
            case 'image':
                return ['kind' => 'image', 'url' => strval(array_get($options, 'image_url'))];
            case 'hr':
                return ['kind' => 'line'];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $formOptions the options of the item on this form
     * @return array<string, mixed>
     */
    protected static function field(CustomColumn $column, array $formOptions): array
    {
        $type = strval($column->column_type);
        $field = [
            'kind' => 'field',
            'name' => strval($column->column_name),
            'label' => strval($column->column_view_name),
            'type' => $type,
            'required' => boolval($column->getOption('required')) || boolval(array_get($formOptions, 'required')),
            'multiple' => boolval($column->getOption('multiple_enabled')),
            'help' => is_nullorempty($column->getOption('help')) ? null : strval($column->getOption('help')),
            'default' => $column->getOption('default'),
        ];

        if (in_array($type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT], true)) {
            $field['choices'] = [];
            foreach ($column->createSelectOptions() as $value => $label) {
                $field['choices'][] = ['value' => strval($value), 'label' => strval($label)];
            }
        } elseif (in_array($type, [ColumnType::YESNO, ColumnType::BOOLEAN], true)) {
            $field['choices'] = $type === ColumnType::YESNO
                ? [['value' => '1', 'label' => exmtrans('common.yes')], ['value' => '0', 'label' => exmtrans('common.no')]]
                : [
                    ['value' => strval($column->getOption('true_value')), 'label' => strval($column->getOption('true_label'))],
                    ['value' => strval($column->getOption('false_value')), 'label' => strval($column->getOption('false_label'))],
                ];
        }
        if (ColumnType::isSelectTable($type)) {
            $target = $column->select_target_table;
            $field['reference'] = ['table' => isset($target) ? strval($target->table_name) : null];
        }
        // a calculated item: the formula as Exment keeps it (${value:column}, ${sum:child table.column}), for the app to calculate
        $formula = $column->getOption('calc_formula');
        if (is_string($formula) && $formula !== '' && $formula !== 'null') {
            $field['formula'] = $formula;
        }
        $conditions = ScreenCondition::conditionsOf(array_get($formOptions, 'visible_if'));
        if ($conditions !== null) {
            $field['visible_if'] = ['all' => $conditions];
        }
        return $field;
    }
}
