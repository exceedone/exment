<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveSoftDeletes extends Migration
{
    const SOFT_DELETED_ARRAY = [
        'custom_relations',
        'custom_copy_columns',
        'custom_copies',
        'custom_view_sorts',
        'custom_view_summaries',
        'custom_view_filters',
        'custom_view_columns',
        'custom_views',
        'custom_form_columns',
        'custom_form_blocks',
        'custom_forms',
        'custom_columns',
        'custom_tables',
        'dashboard_boxes',
        'dashboards',
        'roles',
        'user_settings',
        'login_users',
        'plugins',
        'notifies',
        'systems',
    ];
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // add index
        Schema::table('plugins', function (Blueprint $table) {
            $table->index(['plugin_name']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->index(['role_name']);
        });
        Schema::table('dashboards', function (Blueprint $table) {
            $table->index(['dashboard_name']);
        });

        $this->dropExmTables();
        
        // hard delete if already deleted record
        foreach(static::SOFT_DELETED_ARRAY as $table_name){
            $this->deleteRecord($table_name);
        }

        // get all deleted_at, deleted_user_id's column
        $tables = \DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            foreach ($table as $key => $name) {
                if (stripos($name, 'exm__') === 0 || $name == 'custom_values') {
                    continue;
                }

                $columns = \DB::select("SHOW COLUMNS FROM $name WHERE field IN ('deleted_at', 'deleted_user_id')");

                if(count($columns) == 0){
                    continue;
                }

                foreach($columns as $column){
                    $field = $column->field;
                    
                    Schema::table($name, function (Blueprint $t) use($field) {
                        $t->dropColumn($field);
                    });
                }
            }
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
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropIndex(['dashboard_name']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['role_name']);
        });
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropIndex(['plugin_name']);
        });
    }

    /**
     * drop custom table's table
     */
    protected function dropExmTables(){
        if(!Schema::hasColumn('custom_tables', 'deleted_at')){
            return;
        }

        foreach (DB::table('custom_tables')->whereNull('deleted_at')->get() as $value) {
            // drop deleted table, so don't call getDBTableName function
            Schema::dropIfExists('exm__' . $value->suuid);
        }
    }

    /**
     * hard delete 
     */
    protected function deleteRecord($table_name){
        if(!Schema::hasColumn($table_name, 'deleted_at')){
            return;
        }

        $deleted = \DB::delete("delete from $table_name WHERE deleted_at IS NOT NULL");
    }
}
