<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Line\LineInstaller;

/**
 * Install-time setup for the safety-check (安否確認) feature.
 * Same contract as LineInstaller: every ensure*() is idempotent and is called
 * from both the migration and InstallSeeder (see LineInstaller docblock).
 */
class SafetyCheckInstaller
{
    public const FLEX_KEY = 'safety_check';

    public static function ensureAll(): void
    {
        if (!LineInstaller::systemTemplateImported()) {
            return;
        }
        static::ensureEventTable();
        static::ensureAnswerTable();
        static::ensureMenu();
        static::ensureFlexTemplate();
        static::ensureMailTemplate();
        static::ensureChannelMailOption();
    }

    /** Event-table column definitions: [name, view name, type, options]. */
    protected static function eventColumns(): array
    {
        return [
            ['title',        exmtrans('safety.col_title'),        ColumnType::TEXT,     ['index_enabled' => 1]],
            ['trigger_type', exmtrans('safety.col_trigger_type'), ColumnType::SELECT,   ['index_enabled' => 1, 'select_item' => implode("\n", [SafetyCheckDefine::TRIGGER_MANUAL, SafetyCheckDefine::TRIGGER_DRILL, SafetyCheckDefine::TRIGGER_JMA_AUTO])]],
            ['event_status', exmtrans('safety.col_event_status'), ColumnType::SELECT,   ['index_enabled' => 1, 'select_item' => implode("\n", [SafetyCheckDefine::EVENT_OPEN, SafetyCheckDefine::EVENT_CLOSED])]],
            ['triggered_at', exmtrans('safety.col_triggered_at'), ColumnType::DATETIME, ['index_enabled' => 1]],
            ['jma_event_id', exmtrans('safety.col_jma_event_id'), ColumnType::TEXT,     ['index_enabled' => 1]],
            // The quake's occurred time. Every P2PQuake bulletin has its own id, but
            // bulletins for the same earthquake share this — it is what the watcher's
            // cooldown uses to tell "correction of the same quake" from "new quake".
            ['quake_time',   exmtrans('safety.col_quake_time'),   ColumnType::DATETIME, []],
            ['quake_info',   exmtrans('safety.col_quake_info'),   ColumnType::TEXTAREA, []],
            ['target_count', exmtrans('safety.col_target_count'), ColumnType::INTEGER,  []],
            ['sent_count',   exmtrans('safety.col_sent_count'),   ColumnType::INTEGER,  []],
            ['resent_at',    exmtrans('safety.col_resent_at'),    ColumnType::DATETIME, []],
        ];
    }

    /** Columns that identify each feature table as ours (see LineInstaller::isOwnedTable). */
    public const OWNED_MARKERS = [
        SafetyCheckDefine::TABLE_EVENT  => ['trigger_type', 'event_status', 'jma_event_id'],
        SafetyCheckDefine::TABLE_ANSWER => ['answer_status', 'unlinked_flg'],
    ];

    public static function ensureEventTable(): void
    {
        $existing = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        if ($existing) {
            // A customer's same-name table must not be adopted (nor dropped later).
            LineInstaller::assertOwnedTable($existing, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_EVENT]);
            // Upgrade path: a table created by an earlier version may miss
            // columns added since (e.g. quake_time).
            static::ensureColumns($existing, static::eventColumns());
            static::markSystem($existing);
            return;
        }
        $table = CustomTable::create([
            'table_name'      => SafetyCheckDefine::TABLE_EVENT,
            'table_view_name' => exmtrans('safety.event_table_view_name'),
            'options'         => ['search_enabled' => 1],
        ]);
        $table->createTable();
        static::ensureColumns($table, static::eventColumns());
        $titleColumn = CustomColumn::getEloquent('title', $table);
        if ($titleColumn) {
            CustomColumnMulti::create([
                'custom_table_id'   => $table->id,
                'multisetting_type' => MultisettingType::TABLE_LABELS,
                'priority'          => 1,
                'options'           => ['table_label_id' => $titleColumn->id],
            ]);
        }
        static::markSystem($table);
    }

    /** Answer-table column definitions: [name, view name, type, options]. */
    protected static function answerColumns(): array
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerStatuses = array_merge([SafetyCheckDefine::ANSWER_NOT_ANSWERED], SafetyCheckDefine::ANSWER_STATUSES);
        return [
            ['event',         exmtrans('safety.col_event'),         ColumnType::SELECT_TABLE, ['index_enabled' => 1, 'select_target_table' => $eventTable ? $eventTable->id : null]],
            ['user',          exmtrans('safety.col_user'),          ColumnType::USER,         ['index_enabled' => 1]],
            ['answer_status', exmtrans('safety.col_answer_status'), ColumnType::SELECT,       ['index_enabled' => 1, 'select_item' => implode("\n", $answerStatuses), 'default' => SafetyCheckDefine::ANSWER_NOT_ANSWERED]],
            ['comment',       exmtrans('safety.col_comment'),       ColumnType::TEXTAREA,     []],
            ['answered_at',   exmtrans('safety.col_answered_at'),   ColumnType::DATETIME,     []],
            ['channel',       exmtrans('safety.col_channel'),       ColumnType::SELECT,       ['select_item' => "line\nmail"]],
            ['unlinked_flg',  exmtrans('safety.col_unlinked_flg'),  ColumnType::YESNO,        []],
        ];
    }

    /**
     * Answer table (safety_check_answer). References safety_check_event, so
     * ensureEventTable() must run first.
     */
    public static function ensureAnswerTable(): void
    {
        $existing = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if ($existing) {
            LineInstaller::assertOwnedTable($existing, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_ANSWER]);
            static::ensureColumns($existing, static::answerColumns());
            static::markSystem($existing);
            return;
        }

        $table = CustomTable::create([
            'table_name'      => SafetyCheckDefine::TABLE_ANSWER,
            'table_view_name' => exmtrans('safety.answer_table_view_name'),
            'options'         => ['search_enabled' => 1],
        ]);
        $table->createTable();
        static::ensureColumns($table, static::answerColumns());
        static::markSystem($table);
    }

    public static function ensureMenu(): void
    {
        if (Menu::where('menu_type', MenuType::CUSTOM)->where('menu_name', 'safety_check')->exists()) {
            return;
        }
        $menu = new Menu();
        $menu->parent_id   = 0;
        $menu->order       = 99;
        $menu->menu_type   = MenuType::CUSTOM;
        $menu->menu_name   = 'safety_check';
        $menu->menu_target = 'safety_check';
        // stored at install time in the APP_LOCALE language (same convention as
        // Exment's system menus — see MenuController::menuType title handling)
        $menu->title       = exmtrans('safety.menu_title');
        $menu->icon        = 'fa-heartbeat';
        $menu->uri         = 'safety_check';
        $menu->save();
    }

    public static function removeMenu(): void
    {
        Menu::where('menu_type', MenuType::CUSTOM)
            ->where('menu_name', 'safety_check')
            ->delete();
    }

    /**
     * Full feature teardown, mirroring ensureAll(): menu, flex template row, then
     * the tables in dependency order (answer references event). Used by the
     * install migration's down() — keep the two lists in sync HERE, not there.
     */
    public static function removeAll(): void
    {
        static::removeMenu();

        // Flex template row (line_flex_template may not be installed).
        if (CustomTable::getEloquent('line_flex_template')) {
            getModelName('line_flex_template')::withoutGlobalScopes()
                ->where('value->flex_key', static::FLEX_KEY)
                ->forceDelete();
        }

        if (CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)) {
            getModelName(SystemTableName::MAIL_TEMPLATE)::withoutGlobalScopes()
                ->where('value->mail_key_name', MailKeyName::SAFETY_CHECK_MAIL)
                ->forceDelete();
        }

        foreach ([SafetyCheckDefine::TABLE_ANSWER, SafetyCheckDefine::TABLE_EVENT] as $tableName) {
            $table = CustomTable::getEloquent($tableName);
            if (!$table) {
                continue;
            }
            if (!LineInstaller::isOwnedTable($table, static::OWNED_MARKERS[$tableName])) {
                continue; // a customer's same-name table: never drop it
            }
            // system_flg guards against deletion from the admin UI; lift it for teardown
            $table->system_flg = false;
            $table->save();
            $table->dropTable();
            $table->delete();
        }

        System::clearCache();
    }

    /**
     * Flex template used by the safety-check sender. The body rows are built
     * at runtime by the Sender, so body_items is intentionally left blank.
     */
    public static function ensureFlexTemplate(): void
    {
        $existing = getModelName('line_flex_template')::withoutGlobalScopes()
            ->where('value->flex_key', static::FLEX_KEY)->first();
        if ($existing) {
            return;
        }

        $tmpl = CustomTable::getEloquent('line_flex_template')->getValueModel();
        $tmpl->setValue([
            'flex_key'      => static::FLEX_KEY,
            'template_name' => exmtrans('safety.flex_template_name'),
            'title'         => '${title}',
            'body_items'    => '',
            'description'   => exmtrans('safety.flex_template_desc'),
        ])->save();
    }

    public static function ensureMailTemplate(): void
    {
        $existing = getModelName(SystemTableName::MAIL_TEMPLATE)::withoutGlobalScopes()
            ->where('value->mail_key_name', MailKeyName::SAFETY_CHECK_MAIL)->first();
        if ($existing) {
            return;
        }

        CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)->getValueModel()->setValue([
            'mail_key_name'      => MailKeyName::SAFETY_CHECK_MAIL,
            'mail_view_name'     => exmtrans('safety.mail_template_view_name'),
            'mail_template_type' => 'body',
            'mail_subject'       => exmtrans('safety.mail_subject'),
            'mail_body'          => exmtrans('safety.mail_body'),
        ])->save();
    }

    public static function ensureChannelMailOption(): void
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$answerTable) {
            return;
        }
        $channel = CustomColumn::getEloquent('channel', $answerTable);
        if (!$channel) {
            return;
        }
        $items = preg_split('/\r?\n/', (string) array_get($channel->options, 'select_item'), -1, PREG_SPLIT_NO_EMPTY);
        if (in_array('mail', $items, true)) {
            return;
        }
        $items[] = 'mail';
        $channel->setOption('select_item', implode("\n", $items));
        $channel->save();
    }

    public static function ensureSentCountLabel(): void
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        if (!$eventTable) {
            return;
        }
        $sentCount = CustomColumn::getEloquent('sent_count', $eventTable);
        if (!$sentCount || $sentCount->column_view_name === exmtrans('safety.col_sent_count')) {
            return;
        }
        $sentCount->column_view_name = exmtrans('safety.col_sent_count');
        $sentCount->save();
    }

    protected static function ensureColumns(CustomTable $table, array $columns): void
    {
        foreach ($columns as $order => [$name, $view, $type, $options]) {
            if (CustomColumn::getEloquent($name, $table)) {
                continue;
            }
            CustomColumn::create([
                'custom_table_id'  => $table->id,
                'column_name'      => $name,
                'column_view_name' => $view,
                'column_type'      => $type,
                'options'          => $options,
                'order'            => $order + 1,
            ]);
        }
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
