<?php

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Illuminate\Database\Migrations\Migration;

/**
 * "The table is the source of truth": pre-populate the default value of the
 * body_items column on line_flex_template with the 5 standard workflow fields
 * (status/created_user/action/executed_user/comment). When a new template is
 * created, the form pre-fills these so the table matches the card.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = $this->bodyItemsColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        if (!is_nullorempty(array_get($options, 'default'))) {
            return; // default already set, do not overwrite
        }
        $options['default'] = LineFlexBuilder::defaultBodyItems();
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    public function down(): void
    {
        $column = $this->bodyItemsColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        array_forget($options, 'default');
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    protected function bodyItemsColumn(): ?CustomColumn
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return null;
        }
        return CustomColumn::getEloquent('body_items', $table);
    }
};
