<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

/**
 * Studio: change a screen (a form) in the builder it was made in.
 *
 * Like the form of a kintone app, a screen is changed on the page it was made
 * on: the builder opens with the items of the form where they are, and saving
 * writes them back to the same form. The items are of three kinds:
 *
 * - a column of the table ({column_id}): moved, given a condition, taken off
 *   the screen (the column and its data stay in the table);
 * - a new part ({type, label...}): saving makes its column in the table, so it
 *   needs the permission to set up the table, as making a table does;
 * - an item the builder does not make - a heading, an explanation, HTML, an
 *   image, a line, a system item ({form_column_id}): it is kept as it is, and
 *   can be moved or taken off. It is changed on the form setting page.
 *
 * Only the main block of the form is changed: the details (the blocks of the
 * child tables) stay as they are.
 */
class ScreenEditor
{
    /**
     * The new parts a screen that exists can take. A calculation names the
     * other items by their names on the screen, which the columns of the table
     * do not have here: it is added on the column setting page.
     *
     * @return array<int, string>
     */
    public static function newTypes(): array
    {
        return array_values(array_diff(array_keys(BlankFormBuilder::FIELD_TYPES), [BlankFormBuilder::CALC]));
    }

    /**
     * Whether the login user may change the screen in the builder.
     */
    public static function available(CustomForm $form): bool
    {
        $table = $form->custom_table;
        return isset($table) && $table->hasPermission(Permission::EDIT_CUSTOM_FORM) && static::mainBlock($form) !== null;
    }

    /**
     * Whether the login user may add new parts (columns) to the table of the screen.
     */
    public static function canAddColumns(CustomForm $form): bool
    {
        $table = $form->custom_table;
        return isset($table) && $table->hasPermission(Permission::CUSTOM_TABLE);
    }

    public static function mainBlock(CustomForm $form): ?CustomFormBlock
    {
        /** @var CustomFormBlock|null $block */
        $block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->first();
        return $block;
    }

    /**
     * The screen as the builder opens it: the items in reading order, the
     * layout, the conditions (by the index of the item), and whether a row was
     * split in a way the builder does not offer (it is shown as the nearest way).
     *
     * @return array{table_view_name: string, fields: array<int, array<string, mixed>>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>, adjusted: bool}
     */
    public static function load(CustomForm $form): array
    {
        $block = static::mainBlock($form);
        $formColumns = isset($block)
            ? $block->custom_form_columns()->orderBy('row_no')->orderBy('column_no')->orderBy('order')->orderBy('id')->get()
            : collect();

        // the widths of the columns of each row, as the form keeps them
        $grid = [];
        $items = [];
        foreach ($formColumns as $formColumn) {
            /** @var CustomFormColumn $formColumn */
            $item = static::itemOf($formColumn);
            if ($item === null) {
                continue;
            }
            $row = intval($formColumn->row_no ?? 1);
            $column = intval($formColumn->column_no ?? 1);
            if (!isset($grid[$row][$column])) {
                $grid[$row][$column] = max(1, min(4, intval($formColumn->width ?? 4)));
            }
            $items[] = ['item' => $item, 'row' => $row, 'column' => $column, 'options' => $formColumn->options];
        }
        ksort($grid);

        $rows = [];
        $rowIndex = [];
        $columnIndex = [];
        $adjusted = false;
        foreach ($grid as $row => $cells) {
            ksort($cells);
            $widths = array_values($cells);
            if (!in_array($widths, ScreenLayout::PRESETS, true)) {
                // the nearest way with as many columns (4 at most)
                $widths = [[4], [2, 2], [2, 1, 1], [1, 1, 1, 1]][min(count($widths), 4) - 1];
                $adjusted = true;
            }
            $rowIndex[$row] = count($rows);
            $rows[] = $widths;
            foreach (array_keys($cells) as $i => $column) {
                $columnIndex[$row][$column] = min($i, count($widths) - 1);
            }
        }
        if (count($rows) === 0) {
            $rows = [[4]];
        }

        $fields = [];
        $places = [];
        $indexOfColumn = [];
        foreach ($items as $index => $entry) {
            $fields[] = $entry['item'];
            $places[] = [
                'row' => $rowIndex[$entry['row']] ?? 0,
                'column' => $columnIndex[$entry['row']][$entry['column']] ?? 0,
            ];
            if (isset($entry['item']['column_id'])) {
                $column = CustomColumn::getEloquent($entry['item']['column_id']);
                if (isset($column)) {
                    $indexOfColumn[strval($column->column_name)] = $index;
                }
            }
        }

        // a condition on one other item is shown in the builder (the builder makes one)
        $conditions = [];
        foreach ($items as $index => $entry) {
            $options = is_array($entry['options']) ? $entry['options'] : [];
            $list = ScreenCondition::conditionsOf(array_get($options, 'visible_if'));
            if ($list === null || count($list) !== 1 || !isset($indexOfColumn[$list[0]['column']])) {
                continue;
            }
            $conditions[$index] = [
                'target' => $indexOfColumn[$list[0]['column']],
                'operator' => $list[0]['operator'],
                'value' => strval($list[0]['value']),
            ];
        }

        return [
            'table_view_name' => strval($form->form_view_name),
            'fields' => $fields,
            'layout' => ['rows' => $rows, 'places' => $places],
            'conditions' => $conditions,
            'adjusted' => $adjusted,
        ];
    }

    /**
     * An item of the form as the builder shows it; null for a column that is gone.
     *
     * @return array<string, mixed>|null
     */
    protected static function itemOf(CustomFormColumn $formColumn): ?array
    {
        $type = intval($formColumn->form_column_type);
        if ($type === FormColumnType::COLUMN) {
            $column = CustomColumn::getEloquent($formColumn->form_column_target_id);
            return isset($column) ? ['column_id' => intval($column->id)] : null;
        }
        return ['form_column_id' => intval($formColumn->id)] + static::keptLabel($formColumn);
    }

    /**
     * What a kept item is, for the builder: "Heading: ...".
     *
     * @return array{text: string, icon: string}
     */
    protected static function keptLabel(CustomFormColumn $formColumn): array
    {
        $options = is_array($formColumn->options) ? $formColumn->options : [];
        if (intval($formColumn->form_column_type) === FormColumnType::SYSTEM) {
            $name = strval(array_get(SystemColumn::getOption(['id' => $formColumn->form_column_target_id]), 'name'));
            return ['text' => sprintf(exmtrans('studio.kept_system'), exmtrans('common.' . $name)), 'icon' => 'fa-cog'];
        }
        $kind = strval(array_get(FormColumnType::getOption(['id' => $formColumn->form_column_target_id]), 'column_name'));
        $label = exmtrans('custom_form.form_column_type_other_options.' . $kind);
        $text = in_array($kind, ['header', 'explain'], true) ? trim(strval(array_get($options, 'text'))) : '';
        $icons = ['header' => 'fa-header', 'explain' => 'fa-info-circle', 'html' => 'fa-code', 'exhtml' => 'fa-code', 'image' => 'fa-picture-o', 'hr' => 'fa-minus'];
        return [
            'text' => $text === '' ? $label : $label . ': ' . mb_strimwidth($text, 0, 40, '…'),
            'icon' => $icons[$kind] ?? 'fa-square-o',
        ];
    }

    /**
     * Read the posted screen. The kept items get their labels from the form again.
     *
     * @param array<string, mixed> $input table_view_name, fields, layout
     * @return array{table_view_name: string, fields: array<int, array<string, mixed>>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>, adjusted: bool}
     */
    public static function normalize(CustomForm $form, array $input): array
    {
        $posted = array_get($input, 'fields');
        if (is_string($posted)) {
            $posted = json_decode($posted, true);
        }
        $posted = is_array($posted) ? array_values(array_filter($posted, 'is_array')) : [];

        $block = static::mainBlock($form);
        $keptColumns = isset($block) ? $block->custom_form_columns()->get()->keyBy('id') : collect();
        $table = $form->custom_table;
        // the names of the columns of the table: a new part does not take one
        $used = [];
        foreach (isset($table) ? $table->custom_columns()->pluck('column_name') : [] as $name) {
            $used[strval($name)] = true;
        }

        $fields = [];
        foreach ($posted as $index => $field) {
            if (is_numeric(array_get($field, 'column_id'))) {
                $fields[] = ['column_id' => intval($field['column_id'])];
                continue;
            }
            if (is_numeric(array_get($field, 'form_column_id'))) {
                $id = intval($field['form_column_id']);
                $formColumn = $keptColumns->get($id);
                $fields[] = ['form_column_id' => $id]
                    + ($formColumn instanceof CustomFormColumn && intval($formColumn->form_column_type) !== FormColumnType::COLUMN
                        ? static::keptLabel($formColumn) : ['text' => '', 'icon' => 'fa-question']);
                continue;
            }
            $label = trim(strval(array_get($field, 'label', '')));
            $columnName = trim(strval(array_get($field, 'column_name', '')));
            if ($columnName === '') {
                $columnName = BlankFormBuilder::columnNameFrom($label, $index + 1, $used);
            }
            $used[$columnName] = true;
            $fields[] = [
                'label' => $label,
                'column_name' => $columnName,
                'type' => strval(array_get($field, 'type', '')),
                'required' => boolval(array_get($field, 'required', false)),
                'choices' => trim(str_replace(["\r\n", "\r"], "\n", strval(array_get($field, 'choices', '')))),
                'source' => null,
                'target_table' => is_numeric(array_get($field, 'target_table')) ? intval($field['target_table']) : null,
                'formula' => '',
                'calc_formula' => null,
                'formula_error' => null,
            ];
        }

        // a condition is on a column of the screen, by a column of the screen
        $conditions = [];
        foreach (ScreenCondition::normalize($posted) as $index => $condition) {
            if (isset($fields[$index]['form_column_id']) || isset($fields[$condition['target']]['form_column_id'])) {
                continue;
            }
            $conditions[$index] = $condition;
        }

        return [
            'table_view_name' => trim(strval(array_get($input, 'table_view_name', ''))),
            'fields' => $fields,
            'layout' => ScreenLayout::normalize(array_get($input, 'layout'), $posted),
            'conditions' => $conditions,
            'adjusted' => false,
        ];
    }

    /**
     * @param array{table_view_name: string, fields: array<int, array<string, mixed>>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>, adjusted: bool} $screen
     * @return array<string, string>
     */
    public static function validate(CustomForm $form, array $screen): array
    {
        $errors = [];
        if ($screen['table_view_name'] === '') {
            $errors['table_view_name'] = exmtrans('studio.error.required');
        } elseif (mb_strlen($screen['table_view_name']) > BlankFormBuilder::MAX_VIEW_NAME_LENGTH) {
            $errors['table_view_name'] = sprintf(exmtrans('common.help.max_length'), BlankFormBuilder::MAX_VIEW_NAME_LENGTH);
        }
        if (count($screen['fields']) === 0) {
            $errors['fields'] = exmtrans('studio.error.no_field');
        } elseif (count($screen['fields']) > BlankFormBuilder::MAX_FIELDS) {
            $errors['fields'] = sprintf(exmtrans('studio.error.too_many_fields'), BlankFormBuilder::MAX_FIELDS);
        }

        $table = $form->custom_table;
        $block = static::mainBlock($form);
        $columns = $table->custom_columns()->get()->keyBy('id');
        $keptIds = isset($block) ? $block->custom_form_columns()->where('form_column_type', '<>', FormColumnType::COLUMN)->pluck('id')->map(function ($id) {
            return intval($id);
        })->all() : [];
        $canAdd = static::canAddColumns($form);
        $seen = [];
        foreach ($columns as $column) {
            $seen[strtolower(strval($column->column_name))] = true;
        }
        $placed = [];
        $kept = [];
        foreach ($screen['fields'] as $index => $field) {
            $key = "fields.{$index}";
            if (isset($field['column_id'])) {
                if (!$columns->has($field['column_id'])) {
                    $errors["{$key}.column_id"] = exmtrans('studio.error.column');
                } elseif (isset($placed[$field['column_id']])) {
                    $errors["{$key}.column_id"] = exmtrans('studio.error.column_placed');
                }
                $placed[$field['column_id']] = true;
            } elseif (isset($field['form_column_id'])) {
                if (!in_array($field['form_column_id'], $keptIds, true) || isset($kept[$field['form_column_id']])) {
                    $errors["{$key}.form_column_id"] = exmtrans('studio.error.column');
                }
                $kept[$field['form_column_id']] = true;
            } elseif (!$canAdd) {
                $errors["{$key}.type"] = exmtrans('studio.error.new_column_permission');
            } elseif (!in_array($field['type'], static::newTypes(), true)) {
                $errors["{$key}.type"] = exmtrans('studio.error.field_type');
            } else {
                $errors += BlankFormBuilder::validateField($field, $key, $seen);
            }
        }

        $errors += ScreenLayout::validate($screen['layout']);
        $errors += ScreenCondition::validate($screen['conditions'], array_map(function ($field) use ($columns) {
            if (isset($field['column_id'])) {
                $column = $columns->get($field['column_id']);
                return isset($column) && boolval($column->getOption('required'));
            }
            return boolval($field['required'] ?? false);
        }, $screen['fields']));
        return $errors;
    }

    /**
     * The type and choices of each posted item, for the buttons (ScreenAction).
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array{type: string, choices: array<int, string>|null}>
     */
    public static function targetsOf(array $fields): array
    {
        return array_map(function ($field) {
            if (isset($field['column_id'])) {
                $column = CustomColumn::getEloquent($field['column_id']);
                return $column instanceof CustomColumn
                    ? ['type' => strval($column->column_type), 'choices' => ScreenAction::choicesOf($column)]
                    : ['type' => '', 'choices' => null];
            }
            if (isset($field['type'])) {
                return [
                    'type' => strval($field['type']),
                    'choices' => in_array($field['type'], [\Exceedone\Exment\Enums\ColumnType::SELECT, \Exceedone\Exment\Enums\ColumnType::SELECT_VALTEXT], true)
                        ? array_values(array_filter(array_map(function ($line) use ($field) {
                            // a "value,label" item is compared by its value
                            return trim($field['type'] === \Exceedone\Exment\Enums\ColumnType::SELECT_VALTEXT ? explode(',', $line, 2)[0] : $line);
                        }, explode("\n", strval($field['choices']))), function ($choice) {
                            return $choice !== '';
                        }))
                        : null,
                ];
            }
            return ['type' => '', 'choices' => null];
        }, $fields);
    }

    /**
     * Write the screen back to the form: make the columns of the new parts,
     * then put the items of the main block where the screen says, take off the
     * ones not on it, keep the conditions, and the name.
     *
     * @param array{table_view_name: string, fields: array<int, array<string, mixed>>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>, adjusted: bool} $screen a screen validate() found no mistake in
     * @return array<int, int> the column of each item (0 for a kept item), for the buttons
     */
    public static function save(CustomForm $form, array $screen): array
    {
        $table = $form->custom_table;
        $layout = ScreenLayout::withoutEmptyRows($screen['layout']);

        // Making a column alters the table of the data (DDL), which MySQL commits at
        // once: the columns are made first, and deleted again when a later step fails.
        $made = [];
        $columnIds = [];
        try {
            $order = intval($table->custom_columns()->max('order'));
            foreach ($screen['fields'] as $index => $field) {
                if (isset($field['column_id'])) {
                    $columnIds[$index] = intval($field['column_id']);
                } elseif (isset($field['form_column_id'])) {
                    $columnIds[$index] = 0;
                } else {
                    $column = BlankFormBuilder::makeColumn($table, $field, ++$order, false);
                    $made[] = $column;
                    $columnIds[$index] = intval($column->id);
                }
            }

            DB::transaction(function () use ($form, $screen, $layout, $columnIds, $made, $table) {
                $block = static::mainBlock($form);
                if (!isset($block)) {
                    return;
                }
                $existing = $block->custom_form_columns()->get();
                $byColumn = $existing->filter(function ($formColumn) {
                    return intval($formColumn->form_column_type) === FormColumnType::COLUMN;
                })->keyBy(function ($formColumn) {
                    return intval($formColumn->form_column_target_id);
                });
                $byId = $existing->keyBy('id');
                $names = [];
                foreach ($columnIds as $index => $columnId) {
                    if ($columnId > 0) {
                        $column = CustomColumn::getEloquent($columnId);
                        $names[$index] = isset($column) ? strval($column->column_name) : '';
                    }
                }

                $keep = [];
                $order = 1;
                foreach ($screen['fields'] as $index => $field) {
                    if (isset($field['form_column_id'])) {
                        $formColumn = $byId->get($field['form_column_id']);
                    } else {
                        $formColumn = $byColumn->get($columnIds[$index]);
                        if (!isset($formColumn)) {
                            $formColumn = new CustomFormColumn();
                            $formColumn->custom_form_block_id = $block->id;
                            $formColumn->form_column_type = FormColumnType::COLUMN;
                            $formColumn->form_column_target_id = $columnIds[$index];
                        }
                    }
                    if (!$formColumn instanceof CustomFormColumn) {
                        continue;
                    }
                    $place = $layout['places'][$index] ?? ['row' => 0, 'column' => 0];
                    $formColumn->row_no = $place['row'] + 1;
                    $formColumn->column_no = $place['column'] + 1;
                    $formColumn->width = $layout['rows'][$place['row']][$place['column']] ?? 4;
                    $formColumn->order = $order++;

                    if (!isset($field['form_column_id'])) {
                        $options = is_array($formColumn->options) ? $formColumn->options : [];
                        $condition = $screen['conditions'][$index] ?? null;
                        if ($condition !== null && ($names[$condition['target']] ?? '') !== '') {
                            $options['visible_if'] = ScreenCondition::shape($names[$condition['target']], $condition['operator'], $condition['value']);
                        } else {
                            unset($options['visible_if']);
                        }
                        $formColumn->options = $options;
                    }
                    $formColumn->save();
                    $keep[] = intval($formColumn->id);
                }
                // the items taken off the screen (a column stays in the table with its data)
                foreach ($existing as $formColumn) {
                    if (!in_array(intval($formColumn->id), $keep, true)) {
                        $formColumn->delete();
                    }
                }

                $form->form_view_name = $screen['table_view_name'];
                $form->save();

                if (count($made) > 0) {
                    static::addToView(CustomView::getDefault($table, false), $table, $made);
                }
            });
        } catch (\Throwable $e) {
            foreach ($made as $column) {
                $column->delete();
            }
            throw $e;
        }
        System::clearRequestSession();
        return $columnIds;
    }

    /**
     * Show the new columns in the list too, before the dates at its end.
     *
     * @param array<int, CustomColumn> $columns
     */
    protected static function addToView(CustomView $view, CustomTable $table, array $columns): void
    {
        $footers = $view->custom_view_columns()
            ->where('view_column_type', ConditionType::SYSTEM)
            ->where('order', '>', 50)
            ->orderBy('order')
            ->get();
        $last = $view->custom_view_columns()->where(function ($query) {
            $query->where('view_column_type', '<>', ConditionType::SYSTEM)->orWhere('order', '<=', 50);
        })->max('order');
        $order = max(10, intval($last) + 1);
        foreach ($columns as $column) {
            $viewColumn = new CustomViewColumn();
            $viewColumn->custom_view_id = $view->id;
            $viewColumn->view_column_type = ConditionType::COLUMN;
            $viewColumn->view_column_table_id = $table->id;
            $viewColumn->view_column_target_id = $column->id;
            $viewColumn->order = $order++;
            $viewColumn->save();
        }
        foreach ($footers as $footer) {
            if (intval($footer->order) < $order) {
                $footer->order = $order++;
                $footer->save();
            }
        }
    }
}
