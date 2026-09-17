<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;

class LineInstaller
{
    public static function systemTemplateImported(): bool
    {
        return CustomTable::where('table_name', SystemTableName::USER)->exists();
    }

    public static function ensureAll(): void
    {
        if (!static::systemTemplateImported()) {
            return;
        }
        static::ensureFlexTemplateTable();
        static::ensureSendLogTable();
        static::ensureLinkMenu();
    }

    public const OWNED_MARKERS = [
        'line_flex_template' => ['flex_key', 'template_name'],
        'line_send_log'      => ['line_user_id', 'send_datetime'],
    ];

    public static function isOwnedTable(CustomTable $table, array $markerColumns): bool
    {
        if (boolval($table->system_flg)) {
            return true;
        }
        foreach ($markerColumns as $name) {
            if (CustomColumn::getEloquent($name, $table)) {
                return true;
            }
        }
        return false;
    }

    /** @throws \RuntimeException */
    public static function assertOwnedTable(CustomTable $table, array $markerColumns): void
    {
        if (!static::isOwnedTable($table, $markerColumns)) {
            throw new \RuntimeException(sprintf(
                'Custom table "%s" already exists but is not the LINE/safety-check feature table (none of: %s). Rename the existing table before installing.',
                $table->table_name,
                implode(', ', $markerColumns)
            ));
        }
    }

    public static function ensureLinkMenu(): void
    {
        if (Menu::where('menu_type', MenuType::CUSTOM)->where('menu_name', 'line_link')->exists()) {
            return;
        }
        $menu = new Menu();
        $menu->parent_id   = 0;
        $menu->order       = 99;
        $menu->menu_type   = MenuType::CUSTOM;
        $menu->menu_name   = 'line_link';
        $menu->menu_target = 'line/link';
        $menu->title       = exmtrans('line.link_menu_title');
        $menu->icon        = 'fa-comments';
        $menu->uri         = 'line/link';
        $menu->save();
    }

    public static function removeLinkMenu(): void
    {
        Menu::where('menu_type', MenuType::CUSTOM)
            ->where('menu_name', 'line_link')
            ->delete();
    }

    public static function ensureFlexTemplateTable(): void
    {
        $existing = CustomTable::getEloquent('line_flex_template');
        if ($existing) {
            static::assertOwnedTable($existing, static::OWNED_MARKERS['line_flex_template']);
            static::markSystem($existing);
            return;
        }

        $columns = [
            ['flex_key',      'Flexキー',       ColumnType::TEXT,     []],
            ['template_name', 'テンプレート名', ColumnType::TEXT,     []],
            ['title',         'タイトル',       ColumnType::TEXT,     ['default' => LineFlexBuilder::defaultTitle()]],
            ['body_items',    '本文項目',       ColumnType::TEXTAREA, ['default' => LineFlexBuilder::defaultBodyItems()]],
            ['description',   '説明',           ColumnType::TEXTAREA, []],
        ];

        $table = CustomTable::create([
            'table_name'      => 'line_flex_template',
            'table_view_name' => 'LINE Flexテンプレート',
            'options'         => ['search_enabled' => 1],
        ]);
        $table->createTable();

        foreach ($columns as $order => [$name, $view, $type, $extraOptions]) {
            CustomColumn::create([
                'custom_table_id'  => $table->id,
                'column_name'      => $name,
                'column_view_name' => $view,
                'column_type'      => $type,
                'options'          => array_merge(['index_enabled' => 1], $extraOptions),
                'order'            => $order + 1,
            ]);
        }

        $nameColumn = CustomColumn::getEloquent('template_name', $table);
        if ($nameColumn) {
            CustomColumnMulti::create([
                'custom_table_id'   => $table->id,
                'multisetting_type' => MultisettingType::TABLE_LABELS,
                'priority'          => 1,
                'options'           => ['table_label_id' => $nameColumn->id],
            ]);
        }

        static::markSystem($table);
    }

    public static function ensureSendLogTable(): void
    {
        $existing = CustomTable::getEloquent('line_send_log');
        if ($existing) {
            static::assertOwnedTable($existing, static::OWNED_MARKERS['line_send_log']);
            static::markSystem($existing);
            return;
        }

        $flexTemplate = CustomTable::getEloquent('line_flex_template');

        $columns = [
            ['line_user_id',  'LINEユーザーID',       ColumnType::TEXT,         ['index_enabled' => 1]],
            ['message_type',  'メッセージ種別',       ColumnType::SELECT,       ['index_enabled' => 1, 'select_item' => "text\nflex"]],
            ['flex_template', '送信Flexテンプレート', ColumnType::SELECT_TABLE, ['index_enabled' => 1, 'select_target_table' => $flexTemplate ? $flexTemplate->id : null]],
            ['subject',       '件名',                 ColumnType::TEXT,         []],
            ['body',          '本文',                 ColumnType::TEXTAREA,     []],
            ['user',          '送信対象ユーザー',     ColumnType::USER,         ['index_enabled' => 1]],
            ['send_datetime', '送信日時',             ColumnType::DATETIME,     ['index_enabled' => 1]],
            ['status',        '送信結果',             ColumnType::SELECT,       ['index_enabled' => 1, 'select_item' => "success\nfailed"]],
            ['error_message', 'エラー内容',           ColumnType::TEXTAREA,     []],
        ];

        $table = CustomTable::create([
            'table_name'      => 'line_send_log',
            'table_view_name' => 'LINE送信履歴',
            'options'         => ['search_enabled' => 1],
        ]);
        $table->createTable();

        foreach ($columns as $order => [$name, $view, $type, $options]) {
            CustomColumn::create([
                'custom_table_id'  => $table->id,
                'column_name'      => $name,
                'column_view_name' => $view,
                'column_type'      => $type,
                'options'          => $options,
                'order'            => $order + 1,
            ]);
        }

        static::markSystem($table);
    }

    protected static function markSystem(CustomTable $table): void
    {
        if (boolval($table->system_flg)) {
            return;
        }
        $table->system_flg = true;
        $table->save();
        System::clearCache();
    }
}
