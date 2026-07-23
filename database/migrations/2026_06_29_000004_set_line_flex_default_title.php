<?php

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Illuminate\Database\Migrations\Migration;

/**
 * Set the default value of the line_flex_template "title" column to
 * "[status] record name" ([${workflow:status_name}] ${value}) so the title
 * clearly shows what the workflow is doing. New templates get this pre-filled
 * in the form; the variables are resolved at send time.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = $this->titleColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        if (!is_nullorempty(array_get($options, 'default'))) {
            return; // default already set, do not overwrite
        }
        $options['default'] = LineFlexBuilder::defaultTitle();
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    public function down(): void
    {
        $column = $this->titleColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        array_forget($options, 'default');
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    protected function titleColumn(): ?CustomColumn
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return null;
        }
        return CustomColumn::getEloquent('title', $table);
    }
};
