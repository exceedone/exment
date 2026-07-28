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

/**
 * Install-time setup for the LINE integration.
 *
 * Each ensure*() is idempotent and called from BOTH:
 *  - the LINE migrations (existing, already-installed environments)
 *  - InstallSeeder, after importSystemTemplate() (fresh "exment:install")
 *
 * Why both are needed on a fresh install:
 *  - the seeder wipes admin_menu AFTER migrate has run, so the menu insert
 *    from the migration alone does not survive;
 *  - the table-creating migrations must SKIP while the system template is not
 *    imported yet (see systemTemplateImported()): InstallService::getStatus()
 *    treats "CustomTable::count() > 0" as "template imported", so creating a
 *    custom table during plain "migrate" makes the web installer jump straight
 *    to the initialize form and never seed the system template.
 */
class LineInstaller
{
    /**
     * Whether the Exment system template (user table etc.) has been imported.
     * Direct query on purpose — no System request cache.
     */
    public static function systemTemplateImported(): bool
    {
        return CustomTable::where('table_name', SystemTableName::USER)->exists();
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
        // stored at install time in the APP_LOCALE language (same convention as
        // Exment's system menus — see MenuController::menuType title handling)
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

    /**
     * Custom table that manages Flex templates for LINE (Phase 3).
     * Created directly in its final state: JA view names, label column,
     * system_flg, and column defaults (body_items / title).
     */
    public static function ensureFlexTemplateTable(): void
    {
        $existing = CustomTable::getEloquent('line_flex_template');
        if ($existing) {
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

        // Use template_name as the label column (shown in list/relation/getLabel)
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

    /**
     * Send-history table (line_send_log). References line_flex_template, so
     * ensureFlexTemplateTable() must run first.
     */
    public static function ensureSendLogTable(): void
    {
        $existing = CustomTable::getEloquent('line_send_log');
        if ($existing) {
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

    /**
     * Mark as a system table (cannot be deleted, like mail_template).
     * system_flg is in CustomTable's $guarded, so it must be assigned directly.
     */
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
