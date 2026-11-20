<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

/**
 * Studio, phase 2: add an input screen to a table that already exists.
 *
 * The other way of the builder (BlankFormBuilder) makes a new table. This one
 * makes no table and no column: the user picks a table, places the columns it
 * already has in the order they want, and a new form of the table is made.
 * So it needs the permission of the forms of that table only.
 */
class ExistingTableFormBuilder
{
    /**
     * The tables the login user may make a form of, with their columns.
     *
     * @return array<int, array{id: int, table_view_name: string, columns: array<int, array{id: int, column_view_name: string, type: string, type_label: string, icon: string, required: bool}>}>
     */
    public static function tables(): array
    {
        // kept for the request (System::clearRequestSession() forgets it), per user
        $user = \Exment::user();
        $key = 'studio_existing_tables_' . (isset($user) ? $user->base_user_id : '');
        return System::requestSession($key, function () {
            return static::readTables();
        });
    }

    /**
     * @return array<int, array{id: int, table_view_name: string, columns: array<int, array{id: int, column_view_name: string, type: string, type_label: string, icon: string, required: bool}>}>
     */
    protected static function readTables(): array
    {
        /** @var \Illuminate\Support\Collection<int, CustomTable> $tables */
        $tables = CustomTable::filterList(null, [
            'permissions' => Permission::EDIT_CUSTOM_FORM,
            'with' => 'custom_columns',
            'filter' => function ($model) {
                return $model->orderBy('order')->orderBy('id');
            },
        ]);

        $result = [];
        foreach ($tables as $table) {
            if (!$table->hasPermission(Permission::EDIT_CUSTOM_FORM)) {
                continue;
            }
            $columns = [];
            foreach ($table->custom_columns as $column) {
                $type = strval($column->column_type);
                $columns[] = [
                    'id' => intval($column->id),
                    'column_view_name' => strval($column->column_view_name),
                    'type' => $type,
                    'type_label' => exmtrans('custom_column.column_type_options.' . $type),
                    'icon' => array_get(BlankFormBuilder::FIELD_TYPES, $type . '.icon', 'fa-circle-o'),
                    // a screen without a required column saves the data with it empty: the
                    // required rule is checked only on the columns of the form
                    'required' => boolval($column->getOption('required')),
                ];
            }
            $result[] = [
                'id' => intval($table->id),
                'table_view_name' => strval($table->table_view_name),
                'columns' => $columns,
            ];
        }
        return $result;
    }

    public static function available(): bool
    {
        return count(static::tables()) > 0;
    }

    /**
     * @param array<string, mixed> $input table_view_name (the name of the screen), table_id, fields (JSON string or array of {column_id})
     * @return array{table_view_name: string, table_id: ?int, fields: array<int, array{column_id: int}>}
     */
    public static function normalize(array $input): array
    {
        $fields = array_get($input, 'fields');
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        $result = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && is_numeric(array_get($field, 'column_id'))) {
                $result[] = ['column_id' => intval($field['column_id'])];
            }
        }
        $tableId = array_get($input, 'table_id');
        return [
            'table_view_name' => trim(strval(array_get($input, 'table_view_name', ''))),
            'table_id' => is_numeric($tableId) ? intval($tableId) : null,
            'fields' => $result,
        ];
    }

    /**
     * @param array{table_view_name: string, table_id: ?int, fields: array<int, array{column_id: int}>} $screen
     * @return array<string, string>
     */
    public static function validate(array $screen): array
    {
        $errors = [];
        if ($screen['table_view_name'] === '') {
            $errors['table_view_name'] = exmtrans('studio.error.required');
        } elseif (mb_strlen($screen['table_view_name']) > BlankFormBuilder::MAX_VIEW_NAME_LENGTH) {
            $errors['table_view_name'] = sprintf(exmtrans('common.help.max_length'), BlankFormBuilder::MAX_VIEW_NAME_LENGTH);
        }

        $table = collect(static::tables())->firstWhere('id', $screen['table_id']);
        if (!isset($table)) {
            $errors['table_id'] = exmtrans('studio.error.table');
            return $errors;
        }

        if (count($screen['fields']) === 0) {
            $errors['fields'] = exmtrans('studio.error.no_field');
        }
        $ids = array_column($table['columns'], 'id');
        $seen = [];
        foreach ($screen['fields'] as $index => $field) {
            if (!in_array($field['column_id'], $ids, true)) {
                $errors["fields.{$index}.column_id"] = exmtrans('studio.error.column');
            } elseif (isset($seen[$field['column_id']])) {
                $errors["fields.{$index}.column_id"] = exmtrans('studio.error.column_placed');
            }
            $seen[$field['column_id']] = true;
        }
        return $errors;
    }

    /**
     * Make the form: one block of the table, the columns in the order they were placed.
     *
     * @param array{table_view_name: string, table_id: ?int, fields: array<int, array{column_id: int}>} $screen a screen validate() found no mistake in
     */
    public static function build(array $screen): CustomForm
    {
        return DB::transaction(function () use ($screen) {
            $form = new CustomForm();
            $form->custom_table_id = $screen['table_id'];
            $form->form_view_name = $screen['table_view_name'];
            $form->default_flg = false;
            $form->saveOrFail();

            $block = new CustomFormBlock();
            $block->custom_form_id = $form->id;
            $block->form_block_type = FormBlockType::DEFAULT;
            $block->form_block_target_table_id = $screen['table_id'];
            $block->available = true;
            $block->save();

            foreach ($screen['fields'] as $index => $field) {
                $column = new CustomFormColumn();
                $column->custom_form_block_id = $block->id;
                $column->form_column_type = FormColumnType::COLUMN;
                $column->form_column_target_id = $field['column_id'];
                $column->order = $index + 1;
                $column->row_no = 1;
                $column->column_no = 1;
                $column->width = 2;
                $column->save();
            }
            return $form;
        });
    }
}
