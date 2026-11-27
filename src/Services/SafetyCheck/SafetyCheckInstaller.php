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
        static::ensureLabels();
    }

    /**
     * The choice columns with the stored values (what the code compares) and
     * their names on screen: column => [table, values, prefix of the lang key].
     */
    public const LABELED_COLUMNS = [
        'trigger_type' => [SafetyCheckDefine::TABLE_EVENT, [SafetyCheckDefine::TRIGGER_MANUAL, SafetyCheckDefine::TRIGGER_DRILL, SafetyCheckDefine::TRIGGER_JMA_AUTO], 'safety.trigger_type_'],
        'event_status' => [SafetyCheckDefine::TABLE_EVENT, [SafetyCheckDefine::EVENT_OPEN, SafetyCheckDefine::EVENT_CLOSED], 'safety.event_status_'],
        'answer_status' => [SafetyCheckDefine::TABLE_ANSWER, [SafetyCheckDefine::ANSWER_NOT_ANSWERED, 'safe', 'minor_injury', 'need_help'], 'safety.status_'],
        'channel' => [SafetyCheckDefine::TABLE_ANSWER, ['line', 'mail'], 'safety.channel_'],
    ];

    /**
     * "value,name" lines of a "select (value and name)" column, the names in
     * the language of the site (as the names of the columns, #2179). A value
     * not known here (one an administrator added) is its own name.
     *
     * @param string $column a key of LABELED_COLUMNS
     * @param string[]|null $values the values; the known ones when null
     */
    public static function valtext(string $column, ?array $values = null): string
    {
        [, $known, $prefix] = static::LABELED_COLUMNS[$column];
        $locale = Menu::siteLocale();
        $lines = [];
        foreach ($values ?? $known as $value) {
            $value = strval($value);
            $label = in_array($value, $known, true) ? trans('exment::exment.' . $prefix . $value, [], $locale) : $value;
            if (!is_string($label) || $label === '' || str_starts_with($label, 'exment::')) {
                $label = $value;
            }
            $lines[] = $value . ',' . $label;
        }
        return implode("\n", $lines);
    }

    /**
     * The choice columns a site made before #2179 ("select", which shows the
     * stored value such as safe or mail) become "select (value and name)"
     * with the same values: what is stored does not change.
     */
    public static function ensureLabels(): void
    {
        $changed = false;
        foreach (static::LABELED_COLUMNS as $name => [$tableName]) {
            $table = CustomTable::getEloquent($tableName);
            if (!$table) {
                continue;
            }
            $column = CustomColumn::getEloquent($name, $table);
            if (!$column || $column->column_type !== ColumnType::SELECT) {
                continue;
            }
            $values = preg_split('/\r?\n/', (string) array_get($column->options, 'select_item'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $values = array_values(array_unique(array_filter(array_map('trim', $values), function ($value) {
                return $value !== '';
            })));
            $column->column_type = ColumnType::SELECT_VALTEXT;
            $column->setOption('select_item_valtext', static::valtext($name, $values));
            $column->save();
            $changed = true;
        }
        if ($changed) {
            System::clearCache();
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> [column_name, view_name, column_type, options]
     */
    protected static function eventColumns(): array
    {
        return [
            ['title',        exmtrans('safety.col_title'),        ColumnType::TEXT,     ['index_enabled' => 1]],
            ['trigger_type', exmtrans('safety.col_trigger_type'), ColumnType::SELECT_VALTEXT, ['index_enabled' => 1, 'select_item_valtext' => static::valtext('trigger_type')]],
            ['event_status', exmtrans('safety.col_event_status'), ColumnType::SELECT_VALTEXT, ['index_enabled' => 1, 'select_item_valtext' => static::valtext('event_status')]],
            ['triggered_at', exmtrans('safety.col_triggered_at'), ColumnType::DATETIME, ['index_enabled' => 1]],
            ['jma_event_id', exmtrans('safety.col_jma_event_id'), ColumnType::TEXT,     ['index_enabled' => 1]],
            ['quake_time',   exmtrans('safety.col_quake_time'),   ColumnType::DATETIME, []],
            ['quake_info',   exmtrans('safety.col_quake_info'),   ColumnType::TEXTAREA, []],
            ['target_count', exmtrans('safety.col_target_count'), ColumnType::INTEGER,  []],
            ['sent_count',   exmtrans('safety.col_sent_count'),   ColumnType::INTEGER,  []],
            ['resent_at',    exmtrans('safety.col_resent_at'),    ColumnType::DATETIME, []],
        ];
    }

    public const OWNED_MARKERS = [
        SafetyCheckDefine::TABLE_EVENT  => ['title', 'trigger_type', 'event_status', 'triggered_at', 'jma_event_id', 'quake_time', 'quake_info', 'target_count', 'sent_count', 'resent_at'],
        SafetyCheckDefine::TABLE_ANSWER => ['event', 'user', 'answer_status', 'comment', 'answered_at', 'channel', 'unlinked_flg'],
    ];

    public static function ensureEventTable(): void
    {
        $existing = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        if ($existing) {
            LineInstaller::assertOwnedTable($existing, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_EVENT]);
            static::ensureColumns($existing, static::eventColumns());
            static::markSystem($existing);
            LineInstaller::markSystemColumns($existing, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_EVENT]);
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
        LineInstaller::markSystemColumns($table, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_EVENT]);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> [column_name, view_name, column_type, options]
     */
    protected static function answerColumns(): array
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        return [
            ['event',         exmtrans('safety.col_event'),         ColumnType::SELECT_TABLE, ['index_enabled' => 1, 'select_target_table' => $eventTable ? $eventTable->id : null]],
            ['user',          exmtrans('safety.col_user'),          ColumnType::USER,         ['index_enabled' => 1]],
            ['answer_status', exmtrans('safety.col_answer_status'), ColumnType::SELECT_VALTEXT, ['index_enabled' => 1, 'select_item_valtext' => static::valtext('answer_status'), 'default' => SafetyCheckDefine::ANSWER_NOT_ANSWERED]],
            ['comment',       exmtrans('safety.col_comment'),       ColumnType::TEXTAREA,     []],
            ['answered_at',   exmtrans('safety.col_answered_at'),   ColumnType::DATETIME,     []],
            ['channel',       exmtrans('safety.col_channel'),       ColumnType::SELECT_VALTEXT, ['select_item_valtext' => static::valtext('channel')]],
            ['unlinked_flg',  exmtrans('safety.col_unlinked_flg'),  ColumnType::YESNO,        []],
        ];
    }

    public static function ensureAnswerTable(): void
    {
        $existing = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if ($existing) {
            LineInstaller::assertOwnedTable($existing, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_ANSWER]);
            static::ensureColumns($existing, static::answerColumns());
            static::markSystem($existing);
            LineInstaller::markSystemColumns($existing, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_ANSWER]);
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
        LineInstaller::markSystemColumns($table, static::OWNED_MARKERS[SafetyCheckDefine::TABLE_ANSWER]);
    }

    public static function ensureMenu(): void
    {
        if (Menu::where('menu_type', MenuType::CUSTOM)->where('menu_name', 'safety_check')->exists()) {
            return;
        }
        // only system administrators use it, so put it under "Admin Setting" when the site has that menu
        $admin = Menu::where('menu_type', MenuType::PARENT_NODE)->where('menu_name', 'admin')->first();

        $menu = new Menu();
        $menu->parent_id   = $admin ? $admin->id : 0;
        $menu->order       = $admin ? intval(Menu::where('parent_id', $admin->id)->max('order')) + 1 : 99;
        $menu->menu_type   = MenuType::CUSTOM;
        $menu->menu_name   = 'safety_check';
        $menu->menu_target = 'safety_check';
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

    public static function removeAll(): void
    {
        $tables = [];
        foreach ([SafetyCheckDefine::TABLE_ANSWER, SafetyCheckDefine::TABLE_EVENT] as $tableName) {
            $table = CustomTable::getEloquent($tableName);
            if ($table && LineInstaller::isOwnedTable($table, static::OWNED_MARKERS[$tableName])) {
                $tables[] = $table;
            }
        }
        // before removing anything, so a refused rollback leaves the feature whole
        LineInstaller::assertDroppable($tables);

        static::removeMenu();

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

        foreach ($tables as $table) {
            $table->system_flg = false;
            $table->save();
            $table->dropTable();
            $table->delete();
        }

        System::clearCache();
    }

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
        // "select (value and name)" from #2179: "mail,Mail"
        $valtext = $channel->column_type === ColumnType::SELECT_VALTEXT;
        $key = $valtext ? 'select_item_valtext' : 'select_item';
        $items = preg_split('/\r?\n/', (string) array_get($channel->options, $key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $values = array_map(function ($item) use ($valtext) {
            return trim($valtext ? explode(',', $item, 2)[0] : $item);
        }, $items);
        if (in_array('mail', $values, true)) {
            return;
        }
        $items[] = $valtext ? static::valtext('channel', ['mail']) : 'mail';
        $channel->setOption($key, implode("\n", $items));
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

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> $columns [column_name, view_name, column_type, options]
     */
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
