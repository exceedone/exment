<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Model;

class RemoveSoftDeletes extends Migration
{
    const SOFT_DELETED_ARRAY = [
        'custom_relations' => Model\CustomRelation::class,
        'custom_copy_columns' => Model\CustomCopyColumn::class,
        'custom_copies' => Model\CustomCopy::class,
        'custom_view_sorts' => Model\CustomViewSort::class,
        'custom_view_summaries' => Model\CustomViewSummary::class,
        'custom_view_filters' => Model\CustomViewFilter::class,
        'custom_view_columns' => Model\CustomViewColumn::class,
        'custom_views' => Model\CustomView::class,
        'custom_form_columns' => Model\CustomFormColumn::class,
        'custom_form_blocks' => Model\CustomFormBlock::class,
        'custom_forms' => Model\CustomForm::class,
        'custom_columns' => Model\CustomColumn::class,
        'custom_tables' => Model\CustomTable::class,
        'dashboard_boxes' => Model\DashboardBox::class,
        'dashboards' => Model\Dashboard::class,
        'roles' => Model\Role::class,
        'user_settings' => Model\UserSetting::class,
        'login_users' => Model\LoginUser::class,
        'plugins' => Model\Plugin::class,
        'notifies' => Model\Notify::class,
        'systems' => Model\System::class,
    ];

    const ADD_INDEX_TABLES = [
        'plugins' => 'plugin_name',
        'roles' => 'role_name',
        'dashboards' => 'dashboard_name',
    ];


    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // add index
        $this->addIndex();
        $this->addDeletedIndex();

        $this->dropExmTables();
        
        // hard delete if already deleted record
        foreach(static::SOFT_DELETED_ARRAY as $table_name => $classname){
            $this->deleteRecord($table_name, $classname);
        }

        // get all deleted_at, deleted_user_id's column
        $tables = \DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            $this->dropDeletedRecord($table);

            $this->dropSuuidUnique($table);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach (static::ADD_INDEX_TABLES as $table_name => $column_name) {
            Schema::table($table_name, function (Blueprint $table) use($column_name) {
                $table->dropIndex([ $column_name]);
            });
        }
    }

    /**
     * drop custom table's table
     */
    protected function dropExmTables(){
        if(!Schema::hasColumn('custom_tables', 'deleted_at')){
            return;
        }

        foreach (DB::table('custom_tables')->whereNotNull('deleted_at')->get() as $value) {
            // drop deleted table, so don't call getDBTableName function
            Schema::dropIfExists('exm__' . $value->suuid);
        }
    }

    /**
     * hard delete 
     */
    protected function deleteRecord($table_name, $classname){
        if(!Schema::hasColumn($table_name, 'deleted_at')){
            return;
        }

        $classname::whereNotNull('deleted_at')->get()->each(function($row){
            $row->delete();
        });
        //$deleted = \DB::delete("delete from $table_name WHERE deleted_at IS NOT NULL");
    }

    /**
     * add key's index
     */
    protected function addIndex(){
        foreach (static::ADD_INDEX_TABLES as $table_name => $column_name) {
            $columns = \Schema::getIndex($table_name, $column_name);
 
            if(count($columns) > 0){
                continue;
            }

            Schema::table($table_name, function (Blueprint $t) use($column_name) {
                $t->index([$column_name]);
            });
        }
    }

    /**
     * add deleted_at key's index
     */
    protected function addDeletedIndex(){
        // add deleted_at index in custom values table
        if(count(Schema::getIndex('custom_values', 'deleted_at')) == 0){
            Schema::table('custom_values', function (Blueprint $t) {
                $t->index(['deleted_at']);
            });   
        }
        
        $tables = \DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            foreach ($table as $key => $name) {
                if (stripos($name, 'exm__') === false) {
                    continue;
                }
    
                $columns = \DB::select("SHOW COLUMNS FROM $name WHERE field IN ('deleted_at')");
    
                if(count($columns) == 0){
                    continue;
                }

                // check index
                if(count(Schema::getIndex($name, 'deleted_at')) > 0){
                    continue;
                }

                Schema::table($name, function (Blueprint $t) {
                    $t->index(['deleted_at'], 'custom_values_deleted_at_index');
                });  
            }
        }
    }

    /**
     * drop deleted record
     */
    protected function dropDeletedRecord($table){
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
    
    /**
     * drop deleted record
     */
    protected function dropSuuidUnique($table){
        foreach ($table as $key => $name) {
            $columns = \Schema::getUnique($name, 'suuid');
            if(count($columns) == 0){
                continue;
            }

            foreach($columns as $column){
                $keyName = $column->key_name;
                
                Schema::table($name, function (Blueprint $t) use($keyName, $name) {
                    $t->dropUnique($keyName);

                    if (stripos($name, 'exm__') === 0 || $name == 'custom_values') {
                        $t->index(['suuid'], 'custom_values_suuid_index');
                    }else{
                        $t->index(['suuid']);
                    }
                });
            }
        }
    }
}
