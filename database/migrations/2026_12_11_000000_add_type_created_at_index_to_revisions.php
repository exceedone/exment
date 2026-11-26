<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The name Laravel gives an index on these two columns. */
    protected const INDEX = 'revisions_revisionable_type_created_at_index';

    /**
     * The newest changes of one kind of record, read by created_at: the history of a table's
     * rows (ExmentPM's 「最近の更新」, the latest changes of a whole table). The table only had
     * (revisionable_id, revisionable_type), so such a read went through every change of every
     * record of the kind before sorting. Skipped when an index of that name is already there
     * (made by hand, or by a plugin).
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('revisions') || $this->hasIndex()) {
            return;
        }
        Schema::table('revisions', function (Blueprint $table) {
            $table->index(['revisionable_type', 'created_at'], static::INDEX);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('revisions') || !$this->hasIndex()) {
            return;
        }
        Schema::table('revisions', function (Blueprint $table) {
            $table->dropIndex(static::INDEX);
        });
    }

    protected function hasIndex(): bool
    {
        foreach (Schema::getIndexes('revisions') as $index) {
            if (strtolower(strval($index['name'] ?? '')) === static::INDEX) {
                return true;
            }
        }
        return false;
    }
};
