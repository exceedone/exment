<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Database\ExtendedBlueprint;

class NotiftTodomerge extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        //
        if(!\Schema::hasTable(SystemTableName::NOTIFY_NAVBAR)){
            $schema->create(SystemTableName::NOTIFY_NAVBAR, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->nullableMorphs('parent');
                $table->integer('target_user_id')->unsigned()->index();
                $table->integer('trigger_user_id')->unsigned()->nullable();
                $table->string('notify_subject', 200)->nullable();
                $table->string('notify_body', 2000)->nullable();
                $table->boolean('read_flg')->default(false)->index();
                $table->timestamps();
                $table->timeusers();
            });
        }

        if (!Schema::hasColumn('notifies', 'notify_actions') && Schema::hasColumn('notifies', 'notify_action')) {
            Schema::table('notifies', function (Blueprint $table) {
                $table->string('notify_actions', 50)->after('notify_action')->nullable();
            });
                
            // update notify notify_action to notify_actions
            foreach(Notify::all() as $notify){
                $notify->notify_actions = $notify_action;
                $notify->save();
            }
            
            Schema::table('notifies', function (Blueprint $table) {
                $table->dropColumn('notify_action');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::dropIfExists(SystemTableName::NOTIFY_NAVBAR);
    }
}
