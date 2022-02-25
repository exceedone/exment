<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums;
use Illuminate\Support\Facades\DB;

class CreateTableDefine extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        // remove defalut login_users and create
        Schema::dropIfExists('login_users');
        $schema->create('login_users', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('base_user_id')->unsigned()->index();
            $table->string('login_provider', 32)->nullable();
            $table->string('password', 1000);
            $table->string('avatar', 512)->nullable();
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('revisions', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->string('revisionable_type');
            $table->integer('revisionable_id');
            $table->integer('revision_no')->unsigned()->default(0);
            $table->string('key')->index();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at', 0)->nullable();
            $table->integer('create_user_id')->nullable();
            $table->integer('delete_user_id')->nullable();

            $table->index(array('revisionable_id', 'revisionable_type'));
        });

        $schema->create('files', function (ExtendedBlueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('local_dirname')->index();
            $table->string('local_filename')->index();
            $table->string('filename')->index();
            $table->nullableMorphs('parent');
            $table->integer('custom_column_id')->nullable();
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('systems', function (ExtendedBlueprint $table) {
            $table->string('system_name');
            $table->text('system_value')->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->primary('system_name');
        });

        $schema->create('plugins', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('plugin_name', 256)->unique();
            $table->string('plugin_view_name', 256);
            $table->string('author', 256)->nullable();
            $table->integer('plugin_type');
            $table->string('version', 128)->nullable();
            $table->string('description', 1000)->nullable();
            $table->boolean('active_flg')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('user_settings', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('base_user_id')->unsigned()->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('roles', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('role_type');
            $table->string('role_name', 256)->index()->unique();
            $table->string('role_view_name', 256);
            $table->string('description', 1000)->nullable();
            $table->boolean('default_flg')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('dashboards', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('dashboard_type')->default(0);
            $table->string('dashboard_name', 256)->unique();
            $table->string('dashboard_view_name', 40);
            $table->boolean('default_flg')->default(false);
            $table->json('options')->nullable();
            
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('dashboard_boxes', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('dashboard_id')->unsigned();
            $table->integer('row_no')->index();
            $table->integer('column_no')->index();
            $table->string('dashboard_box_view_name', 40);
            $table->string('dashboard_box_type');
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('dashboard_id')->references('id')->on('dashboards');
        });

        $schema->create('notifies', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->string('notify_view_name', 256);
            $table->integer('custom_table_id')->unsigned();
            $table->integer('notify_trigger');
            $table->json('trigger_settings')->nullable();
            $table->integer('notify_action');
            $table->json('action_settings')->nullable();
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('custom_tables', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->string('table_name', 256)->unique();
            $table->string('table_view_name', 256);
            $table->string('description', 1000)->nullable();
            $table->boolean('system_flg')->default(false);
            $table->boolean('showlist_flg')->default(true);
            $table->json('options')->nullable();

            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('custom_columns', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('custom_table_id')->unsigned();
            $table->string('column_name')->index();
            $table->string('column_view_name');
            $table->string('column_type')->index();
            $table->string('description', 1000)->nullable();
            $table->boolean('system_flg')->default(false);
            $table->json('options')->nullable();

            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('custom_relations', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('parent_custom_table_id')->unsigned();
            $table->integer('child_custom_table_id')->unsigned();
            $table->integer('relation_type')->default(0);
            $table->timestamps();
            $table->timeusers();

            $table->foreign('parent_custom_table_id')->references('id')->on('custom_tables');
            $table->foreign('child_custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('custom_forms', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('custom_table_id')->unsigned();
            $table->string('form_view_name', 256);
            $table->boolean('default_flg')->default(false);
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('custom_form_blocks', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_form_id')->unsigned();
            $table->string('form_block_view_name')->nullable();
            $table->integer('form_block_type');
            $table->integer('form_block_target_table_id')->unsigned()->nullable();
            $table->boolean('available')->default(false);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_form_id')->references('id')->on('custom_forms');
            $table->foreign('form_block_target_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('custom_form_columns', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_form_block_id')->unsigned();
            $table->integer('form_column_type');
            $table->integer('form_column_target_id')->nullable();
            $table->integer('column_no')->default(1);
            $table->json('options')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_form_block_id')->references('id')->on('custom_form_blocks');
        });
        
        $schema->create('custom_views', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('custom_table_id')->unsigned();
            $table->integer('view_type')->default(0);
            $table->integer('view_kind_type')->default(0);
            $table->string('view_view_name', 40);
            $table->boolean('default_flg')->default(false);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('custom_view_columns', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_view_id')->unsigned();
            $table->integer('view_column_type')->default(0);
            $table->integer('view_column_target_id')->nullable();
            $table->integer('order')->unsigned()->default(0);
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_view_id')->references('id')->on('custom_views');
        });

        $schema->create('custom_view_filters', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_view_id')->unsigned();
            $table->integer('view_column_type')->default(0);
            $table->integer('view_column_target_id')->nullable();
            $table->integer('view_filter_condition');
            $table->string('view_filter_condition_value_text', 1024)->nullable();
            // what's????
            $table->integer('view_filter_condition_value_table_id')->unsigned()->nullable();
            $table->integer('view_filter_condition_value_id')->unsigned()->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_view_id')->references('id')->on('custom_views');
        });

        $schema->create('custom_view_sorts', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_view_id')->unsigned();
            $table->integer('view_column_type')->default(0);
            $table->integer('view_column_target_id')->nullable();
            $table->integer('sort')->default(1);
            $table->integer('priority')->unsigned()->default(0);
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_view_id')->references('id')->on('custom_views');
        });

        $schema->create('custom_copies', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('from_custom_table_id')->unsigned();
            $table->integer('to_custom_table_id')->unsigned();
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->foreign('from_custom_table_id')->references('id')->on('custom_tables');
            $table->foreign('to_custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('custom_copy_columns', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_copy_id')->unsigned();
            $table->integer('from_column_type')->nullable();
            $table->integer('from_column_target_id')->nullable();
            $table->integer('to_column_type')->default(0);
            $table->integer('to_column_target_id');
            $table->integer('copy_column_type')->default(0);
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_copy_id')->references('id')->on('custom_copies');
        });

        $schema->create('custom_values', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->nullableMorphs('parent');
            $table->json('value')->nullable();
            $table->string('laravel_admin_escape')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedInteger('deleted_user_id')->nullable();
            $table->timeusers();
        });

        $schema->create('custom_relation_values', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->unsigned()->index();
            $table->integer('child_id')->unsigned()->index();
        });

        $schema->create('system_authoritable', function (ExtendedBlueprint $table) {
            $table->integer('related_id')->index();
            $table->string('related_type')->index();
            $table->nullableMorphs('morph');
            $table->integer('role_id')->index();
        });

        $schema->create('value_authoritable', function (ExtendedBlueprint $table) {
            $table->integer('related_id')->index();
            $table->string('related_type')->index();
            $table->nullableMorphs('morph');
            $table->integer('role_id')->index();
        });
        
        // Update --------------------------------------------------
        $schema->table(config('admin.database.menu_table'), function (ExtendedBlueprint $table) {
            $table->string('menu_type');
            $table->string('menu_name')->nullable();
            $table->string('menu_target')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('value_authoritable');
        Schema::dropIfExists('system_authoritable');
        Schema::dropIfExists('custom_relation_values');

        // delete all pivot's table.
        if (Schema::hasTable('custom_relations')) {
            $relations = CustomRelation::where('relation_type', Enums\RelationType::MANY_TO_MANY)->get();
            foreach ($relations as $relation) {
                Schema::dropIfExists($relation->getRelationName());
            }
        }

        // delete all custom_value's table.
        if (Schema::hasTable('custom_tables')) {
            foreach (DB::table('custom_tables')->get() as $value) {
                // drop deleted table, so don't call getDBTableName function
                Schema::dropIfExists('exm__' . $value->suuid);
            }
        }

        // delete tables.
        Schema::dropIfExists('custom_relation_values');
        Schema::dropIfExists('custom_values');
        Schema::dropIfExists('custom_relations');
        Schema::dropIfExists('custom_copy_columns');
        Schema::dropIfExists('custom_copies');
        Schema::dropIfExists('custom_view_sorts');
        Schema::dropIfExists('custom_view_filters');
        Schema::dropIfExists('custom_view_columns');
        Schema::dropIfExists('custom_views');
        Schema::dropIfExists('custom_form_columns');
        Schema::dropIfExists('custom_form_blocks');
        Schema::dropIfExists('custom_forms');
        Schema::dropIfExists('custom_columns');
        Schema::dropIfExists('custom_tables');
        Schema::dropIfExists('dashboard_boxes');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('login_users');
        Schema::dropIfExists('plugins');
        Schema::dropIfExists('notifies');
        Schema::dropIfExists('systems');
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('files');
    }
}
