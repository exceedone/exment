<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;

/**
 * Studio, phase 2: make an input screen from a blank page.
 *
 * Like kintone, the user places the items of the screen - a line of text, a
 * number, a date, a choice... - and the table and its columns are made from
 * them. The user does not open the table and column settings: the builder
 * makes the table, one column per item in the order they were placed, the
 * default form and the default view, and the menu item when asked.
 *
 * Only the common column types are offered, each with what it needs to work
 * (the choices of a choice). The other settings of a column keep their
 * defaults and are changed on the column setting page.
 */
class BlankFormBuilder
{
    /**
     * A calculated number: a decimal column with a formula (the option calc_formula).
     */
    public const CALC = 'calc';

    /**
     * The format of the number an auto number column gives: the date and the id ("20261121-0012").
     */
    public const AUTO_NUMBER_FORMAT = '${ymd}-${id:4}';

    /**
     * The item types of the palette: column type => [icon, needs choices, needs a table, needs a formula].
     */
    public const FIELD_TYPES = [
        ColumnType::TEXT => ['icon' => 'fa-font', 'choices' => false],
        ColumnType::TEXTAREA => ['icon' => 'fa-align-left', 'choices' => false],
        ColumnType::INTEGER => ['icon' => 'fa-sort-numeric-asc', 'choices' => false],
        ColumnType::DECIMAL => ['icon' => 'fa-percent', 'choices' => false],
        ColumnType::CURRENCY => ['icon' => 'fa-jpy', 'choices' => false],
        self::CALC => ['icon' => 'fa-calculator', 'choices' => false, 'formula' => true],
        ColumnType::DATE => ['icon' => 'fa-calendar', 'choices' => false],
        ColumnType::DATETIME => ['icon' => 'fa-clock-o', 'choices' => false],
        ColumnType::TIME => ['icon' => 'fa-hourglass-half', 'choices' => false],
        ColumnType::SELECT => ['icon' => 'fa-list-ul', 'choices' => true],
        ColumnType::YESNO => ['icon' => 'fa-toggle-on', 'choices' => false],
        ColumnType::EMAIL => ['icon' => 'fa-envelope-o', 'choices' => false],
        ColumnType::URL => ['icon' => 'fa-link', 'choices' => false],
        ColumnType::SELECT_TABLE => ['icon' => 'fa-table', 'choices' => false, 'target' => true],
        ColumnType::USER => ['icon' => 'fa-user', 'choices' => false],
        ColumnType::ORGANIZATION => ['icon' => 'fa-users', 'choices' => false],
        ColumnType::AUTO_NUMBER => ['icon' => 'fa-barcode', 'choices' => false],
        ColumnType::FILE => ['icon' => 'fa-paperclip', 'choices' => false],
        ColumnType::IMAGE => ['icon' => 'fa-picture-o', 'choices' => false],
    ];

    /**
     * The parts a formula can use: numbers.
     */
    public const NUMBER_TYPES = [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY, self::CALC];

    public const MAX_FIELDS = 100;
    public const MAX_NAME_LENGTH = 30;
    public const MAX_VIEW_NAME_LENGTH = 40;

    /**
     * Whether the login user may make a screen from a blank page: it makes a
     * table, so the system permission of the tables is needed.
     */
    public static function available(): bool
    {
        $user = \Exment::user();
        return isset($user) && $user->hasPermission(Permission::CUSTOM_TABLE);
    }

    /**
     * The palette for the page: type, label and icon of each item type.
     *
     * @return array<int, array{type: string, label: string, icon: string, choices: bool, target: bool, formula: bool, number: bool}>
     */
    public static function palette(): array
    {
        $result = [];
        foreach (static::FIELD_TYPES as $type => $define) {
            $result[] = [
                'type' => $type,
                'label' => $type === static::CALC ? exmtrans('studio.part_calc') : exmtrans('custom_column.column_type_options.' . $type),
                'icon' => $define['icon'],
                'choices' => $define['choices'],
                'target' => boolval($define['target'] ?? false),
                'formula' => boolval($define['formula'] ?? false),
                'number' => in_array($type, static::NUMBER_TYPES, true),
            ];
        }
        return $result;
    }

    /**
     * The tables a "select from a table" item can point at.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function referenceTables(): array
    {
        return CustomTable::where('showlist_flg', true)->orderBy('order')->orderBy('id')->get()->map(function ($table) {
            return ['id' => intval($table->id), 'name' => strval($table->table_view_name)];
        })->all();
    }

    /**
     * The formula of a calculated item, written with the names of the items in
     * brackets ("[quantity] * [price]"), as Exment keeps it ("${value:quantity} * ${value:price}").
     *
     * @param array<int, array{label: string, column_name: string, type: string}> $fields the items of the screen
     * @return array{0: ?string, 1: ?string} the formula, or the key of the mistake
     */
    public static function calcFormula(string $formula, int $self, array $fields): array
    {
        $formula = trim($formula);
        if ($formula === '') {
            return [null, 'formula_empty'];
        }
        $byLabel = [];
        foreach ($fields as $index => $field) {
            $byLabel[$field['label']][] = $index;
        }
        $mistake = null;
        $converted = preg_replace_callback('/\[([^\[\]]+)\]/u', function ($m) use ($byLabel, $self, $fields, &$mistake) {
            $indexes = $byLabel[trim($m[1])] ?? [];
            if (count($indexes) !== 1 || $indexes[0] === $self) {
                $mistake = $mistake ?? 'formula_item';
                return '0';
            }
            if (!in_array($fields[$indexes[0]]['type'], static::NUMBER_TYPES, true)) {
                $mistake = $mistake ?? 'formula_number';
                return '0';
            }
            return '${value:' . $fields[$indexes[0]]['column_name'] . '}';
        }, $formula) ?? '';
        if ($mistake !== null) {
            return [null, $mistake];
        }
        // what is left besides the items: numbers and + - * / ( )
        if (preg_match('/^[0-9\s\.\+\-\*\/\(\)]*$/', preg_replace('/\$\{value:[^}]+\}/', '', $converted) ?? 'x') !== 1) {
            return [null, 'formula_symbol'];
        }
        return [$converted, null];
    }

    /**
     * Read the posted screen into a clean shape. Names left empty are made here.
     *
     * @param array<string, mixed> $input table_view_name, table_name, menu_parent_id, fields (JSON string or array)
     * @return array{table_view_name: string, table_name: string, menu_parent_id: ?int, fields: array<int, array{label: string, column_name: string, type: string, required: bool, choices: string, source: ?int}>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>}
     */
    public static function normalize(array $input): array
    {
        $fields = array_get($input, 'fields');
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        $fields = is_array($fields) ? array_values($fields) : [];

        $tableName = trim(strval(array_get($input, 'table_name', '')));
        if ($tableName === '') {
            $tableName = static::nextTableName();
        }

        $result = [];
        $used = [];
        $kept = [];
        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                continue;
            }
            $kept[] = $field;
            $label = trim(strval(array_get($field, 'label', '')));
            $columnName = trim(strval(array_get($field, 'column_name', '')));
            if ($columnName === '') {
                $columnName = static::columnNameFrom($label, $index + 1, $used);
            }
            $used[$columnName] = true;
            $result[] = [
                'label' => $label,
                'column_name' => $columnName,
                'type' => strval(array_get($field, 'type', '')),
                'required' => boolval(array_get($field, 'required', false)),
                'choices' => trim(str_replace(["\r\n", "\r"], "\n", strval(array_get($field, 'choices', '')))),
                // the column of the read sheet the item came from (SheetReader), to take its data in
                'source' => is_numeric(array_get($field, 'source')) ? intval($field['source']) : null,
                'target_table' => is_numeric(array_get($field, 'target_table')) ? intval($field['target_table']) : null,
                'formula' => trim(strval(array_get($field, 'formula', ''))),
            ];
        }
        // a formula names the other items: read once all the items have their column names
        foreach ($result as $index => $field) {
            [$calc, $mistake] = $field['type'] === static::CALC ? static::calcFormula($field['formula'], $index, $result) : [null, null];
            $result[$index]['calc_formula'] = $calc;
            $result[$index]['formula_error'] = $mistake;
        }

        $parent = array_get($input, 'menu_parent_id');
        return [
            'table_view_name' => trim(strval(array_get($input, 'table_view_name', ''))),
            'table_name' => $tableName,
            'menu_parent_id' => is_numeric($parent) ? intval($parent) : null,
            'fields' => $result,
            'layout' => ScreenLayout::normalize(array_get($input, 'layout'), $kept),
            'conditions' => ScreenCondition::normalize($kept),
        ];
    }

    /**
     * The mistakes of a screen, by field key ("table_view_name", "fields.2.label"...).
     *
     * @param array{table_view_name: string, table_name: string, menu_parent_id: ?int, fields: array<int, array<string, mixed>>, layout?: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions?: array<int, array{target: int, operator: string, value: string}>} $screen
     * @return array<string, string>
     */
    public static function validate(array $screen): array
    {
        $errors = [];
        $regex = '/' . Define::RULES_REGEX_SYSTEM_NAME . '/';

        if ($screen['table_view_name'] === '') {
            $errors['table_view_name'] = exmtrans('studio.error.required');
        } elseif (mb_strlen($screen['table_view_name']) > static::MAX_VIEW_NAME_LENGTH) {
            $errors['table_view_name'] = sprintf(exmtrans('common.help.max_length'), static::MAX_VIEW_NAME_LENGTH);
        }

        if (!preg_match($regex, $screen['table_name']) || strlen($screen['table_name']) > static::MAX_NAME_LENGTH) {
            $errors['table_name'] = exmtrans('studio.error.name_format');
        } elseif (CustomTable::where('table_name', $screen['table_name'])->exists()) {
            $errors['table_name'] = exmtrans('studio.error.table_name_used');
        }

        if (isset($screen['menu_parent_id']) && !Menu::where('id', $screen['menu_parent_id'])->where('menu_type', MenuType::PARENT_NODE)->exists()) {
            $errors['menu_parent_id'] = exmtrans('studio.error.menu_parent');
        }

        if (count($screen['fields']) === 0) {
            $errors['fields'] = exmtrans('studio.error.no_field');
        } elseif (count($screen['fields']) > static::MAX_FIELDS) {
            $errors['fields'] = sprintf(exmtrans('studio.error.too_many_fields'), static::MAX_FIELDS);
        }

        $reserved = array_map('strtolower', SystemColumn::arrays());
        $seen = [];
        foreach ($screen['fields'] as $index => $field) {
            $key = "fields.{$index}";
            if (!array_key_exists($field['type'], static::FIELD_TYPES)) {
                $errors["{$key}.type"] = exmtrans('studio.error.field_type');
            }
            if ($field['label'] === '') {
                $errors["{$key}.label"] = exmtrans('studio.error.required');
            } elseif (mb_strlen($field['label']) > static::MAX_VIEW_NAME_LENGTH) {
                $errors["{$key}.label"] = sprintf(exmtrans('common.help.max_length'), static::MAX_VIEW_NAME_LENGTH);
            }

            $name = $field['column_name'];
            if (!preg_match($regex, $name) || strlen($name) > static::MAX_NAME_LENGTH) {
                $errors["{$key}.column_name"] = exmtrans('studio.error.name_format');
            } elseif (in_array(strtolower($name), $reserved, true)) {
                $errors["{$key}.column_name"] = exmtrans('studio.error.column_name_reserved');
            } elseif (isset($seen[strtolower($name)])) {
                $errors["{$key}.column_name"] = exmtrans('studio.error.column_name_used');
            }
            $seen[strtolower($name)] = true;

            if (array_get(static::FIELD_TYPES, $field['type'] . '.choices') && $field['choices'] === '') {
                $errors["{$key}.choices"] = exmtrans('studio.error.no_choice');
            }
            if (array_get(static::FIELD_TYPES, $field['type'] . '.target')
                && (!isset($field['target_table']) || !CustomTable::where('id', $field['target_table'])->where('showlist_flg', true)->exists())) {
                $errors["{$key}.target_table"] = exmtrans('studio.error.target_table');
            }
            if (isset($field['formula_error'])) {
                $errors["{$key}.formula"] = exmtrans('studio.error.' . $field['formula_error']);
            }
        }
        if (isset($screen['layout'])) {
            $errors += ScreenLayout::validate($screen['layout']);
        }
        if (isset($screen['conditions'])) {
            $errors += ScreenCondition::validate($screen['conditions'], array_map(function ($field) {
                return boolval($field['required'] ?? false);
            }, $screen['fields']));
        }
        return $errors;
    }

    /**
     * Make the table, its columns, the default form and view, and the menu item.
     *
     * @param array{table_view_name: string, table_name: string, menu_parent_id: ?int, fields: array<int, array<string, mixed>>, layout?: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions?: array<int, array{target: int, operator: string, value: string}>} $screen a screen validate() found no mistake in
     */
    public static function build(array $screen): CustomTable
    {
        // Making the table of the data is DDL, which MySQL commits at once, so this
        // is not one transaction: when a step fails, the table made so far is deleted
        // (deleting a custom table drops its data table and removes its columns).
        $table = new CustomTable();
        try {
            $table->table_name = $screen['table_name'];
            $table->table_view_name = $screen['table_view_name'];
            $table->setOption([
                'icon' => 'fa-file-text-o',
                'search_enabled' => '1',
                'attachment_flg' => '1',
                'comment_flg' => '1',
                'revision_flg' => '1',
                'revision_count' => config('exment.revision_count', 100),
                'one_record_flg' => '0',
                'use_label_id_flg' => '0',
                'all_user_editable_flg' => '0',
                'all_user_viewable_flg' => '0',
                'all_user_accessable_flg' => '0',
            ]);
            $table->save();

            // makes the table of the data (the columns alter it when they are saved)
            $table->createTable();

            foreach ($screen['fields'] as $index => $field) {
                $column = new CustomColumn();
                $column->custom_table_id = $table->id;
                $column->column_name = $field['column_name'];
                $column->column_view_name = $field['label'];
                // a calculated item is a decimal with a formula
                $column->column_type = $field['type'] === static::CALC ? ColumnType::DECIMAL : $field['type'];
                $column->order = $index + 1;
                $options = [
                    'required' => $field['required'] ? '1' : '0',
                    // the first item names the data in lists and links, like the title of a kintone record
                    'use_label_flg' => $index === 0 ? '1' : '0',
                ];
                if ($field['type'] === ColumnType::SELECT) {
                    $options['select_item'] = $field['choices'];
                }
                if ($field['type'] === ColumnType::FILE) {
                    $options['multiple_enabled'] = '0';
                }
                if ($field['type'] === ColumnType::SELECT_TABLE) {
                    $options['select_target_table'] = strval($field['target_table'] ?? '');
                }
                if ($field['type'] === ColumnType::CURRENCY) {
                    $options['currency_symbol'] = 'JPY1';
                    $options['decimal_digit'] = '0';
                }
                if ($field['type'] === static::CALC) {
                    $options['calc_formula'] = strval($field['calc_formula'] ?? '');
                    $options['decimal_digit'] = '2';
                }
                if ($field['type'] === ColumnType::AUTO_NUMBER) {
                    $options['auto_number_type'] = 'format';
                    $options['auto_number_format'] = static::AUTO_NUMBER_FORMAT;
                }
                $column->setOption($options);
                $column->save();
            }

            if (isset($screen['menu_parent_id'])) {
                $order = intval(Menu::where('parent_id', $screen['menu_parent_id'])->max('order')) + 1;
                Menu::insert([
                    'parent_id' => $screen['menu_parent_id'],
                    'order' => $order,
                    'title' => $table->table_view_name,
                    'icon' => $table->getOption('icon'),
                    'uri' => $table->table_name,
                    'menu_type' => MenuType::TABLE,
                    'menu_name' => $table->table_name,
                    'menu_target' => $table->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            if ($table->exists) {
                $table->delete();
            }
            throw $e;
        }

        // the default form lists the columns in the order they were placed, the view all of them
        System::clearRequestSession();
        $table = CustomTable::getEloquent($table->id);
        $form = CustomForm::getDefault($table);
        $block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->first();
        $columnIds = $table->custom_columns()->pluck('id')->map(function ($id) {
            return intval($id);
        })->all();
        if (isset($block) && isset($screen['layout'])) {
            ScreenLayout::apply($block, $columnIds, ScreenLayout::withoutEmptyRows($screen['layout']));
        }
        if (isset($block) && isset($screen['conditions'])) {
            ScreenCondition::apply($block, $columnIds, $screen['conditions']);
        }
        static::addColumnsToView(CustomView::getDefault($table, false), $table);

        return $table;
    }

    /**
     * Show the columns in the list (the view made for all data).
     *
     * A new view shows only the system columns (ID, dates): the column setting
     * page adds each column to it when asked ("add to the view"). The builder
     * does the same for all the columns, in the order they were placed, and
     * keeps the dates of the footer after them.
     */
    protected static function addColumnsToView(CustomView $view, CustomTable $table): void
    {
        if ($view->custom_view_columns()->where('view_column_type', ConditionType::COLUMN)->exists()) {
            return;
        }

        $order = 10;
        foreach ($table->custom_columns()->get() as $column) {
            $viewColumn = new CustomViewColumn();
            $viewColumn->custom_view_id = $view->id;
            $viewColumn->view_column_type = ConditionType::COLUMN;
            $viewColumn->view_column_table_id = $table->id;
            $viewColumn->view_column_target_id = $column->id;
            $viewColumn->order = $order++;
            $viewColumn->save();
        }

        $footers = $view->custom_view_columns()
            ->where('view_column_type', ConditionType::SYSTEM)
            ->where('order', '>', 50)
            ->orderBy('order')
            ->get();
        foreach ($footers as $footer) {
            $footer->order = $order++;
            $footer->save();
        }
    }

    /**
     * The next free name "screen_N" for a table whose name was left empty.
     */
    protected static function nextTableName(): string
    {
        $no = CustomTable::count() + 1;
        while (CustomTable::where('table_name', "screen_{$no}")->exists()) {
            $no++;
        }
        return "screen_{$no}";
    }

    /**
     * A column name from the label when it is written in letters and numbers,
     * or "field_N". Names already given on the screen are skipped.
     *
     * @param array<string, bool> $used
     */
    protected static function columnNameFrom(string $label, int $no, array $used): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label) ?? '', '_'));
        $name = preg_match('/^[a-z][a-z0-9_]{0,29}$/', $slug) ? $slug : "field_{$no}";
        $base = $name;
        $suffix = 2;
        while (isset($used[$name])) {
            $name = substr($base, 0, 26) . "_{$suffix}";
            $suffix++;
        }
        return $name;
    }
}
