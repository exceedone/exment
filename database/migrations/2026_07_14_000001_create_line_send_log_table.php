<?php

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public const TABLE_NAME = 'line_send_log';

    public const MESSAGE_TYPES = "text\nflex";
    public const STATUSES      = "success\nfailed";

    public function up(): void
    {
        $existing = CustomTable::getEloquent(static::TABLE_NAME);
        if ($existing) {
            $this->markSystem($existing);
            return;
        }

        $table = CustomTable::create([
            'table_name'      => static::TABLE_NAME,
            'table_view_name' => 'LINE送信履歴',
            'options'         => ['search_enabled' => 1],
        ]);
        $table->createTable();

        foreach ($this->columns() as $order => [$name, $view, $type, $options]) {
            CustomColumn::create([
                'custom_table_id'  => $table->id,
                'column_name'      => $name,
                'column_view_name' => $view,
                'column_type'      => $type,
                'options'          => $options,
                'order'            => $order + 1,
            ]);
        }

        $this->markSystem($table);
    }

    /**
     * @return array<int, array{0:string,1:string,2:string,3:array}>
     */
    protected function columns(): array
    {
        $flexTemplate = CustomTable::getEloquent('line_flex_template');

        return [
            ['line_user_id',  'LINEユーザーID',    ColumnType::TEXT,         ['index_enabled' => 1]],
            ['message_type',  'メッセージ種別',     ColumnType::SELECT,       ['index_enabled' => 1, 'select_item' => static::MESSAGE_TYPES]],
            ['flex_template', '送信Flexテンプレート', ColumnType::SELECT_TABLE, ['index_enabled' => 1, 'select_target_table' => $flexTemplate ? $flexTemplate->id : null]],
            ['subject',       '件名',              ColumnType::TEXT,         []],
            ['body',          '本文',              ColumnType::TEXTAREA,     []],
            ['user',          '送信対象ユーザー',    ColumnType::USER,         ['index_enabled' => 1]],
            ['send_datetime', '送信日時',           ColumnType::DATETIME,     ['index_enabled' => 1]],
            ['status',        '送信結果',           ColumnType::SELECT,       ['index_enabled' => 1, 'select_item' => static::STATUSES]],
            ['error_message', 'エラー内容',         ColumnType::TEXTAREA,     []],
        ];
    }

    protected function markSystem(CustomTable $table): void
    {
        if (boolval($table->system_flg)) {
            return;
        }
        $table->system_flg = true;
        $table->save();
        System::clearCache();
    }

    public function down(): void
    {
        $table = CustomTable::getEloquent(static::TABLE_NAME);
        if (!$table) {
            return;
        }
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
