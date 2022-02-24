<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class UpdateNotifyLogic extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('notifies')) {
            Schema::table('notifies', function (Blueprint $table) {
                if (!Schema::hasColumn('notifies', 'mail_template_id')) {
                    $table->integer('mail_template_id')->after('action_settings')->nullable();
                    $table->boolean('active_flg')->after('notify_view_name')->default(true);
                }
            });
        }

        \Artisan::call('exment:patchdata', ['action' => 'update_notify_difinition']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (Schema::hasTable('notifies')) {
            $schema->table('notifies', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('notifies', 'mail_template_id')) {
                    $table->dropColumn('mail_template_id');
                }
                if (Schema::hasColumn('notifies', 'active_flg')) {
                    $table->dropColumn('active_flg');
                }
            });
        }
    }
}
