<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Model;

class SupportForV12 extends Migration
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
        foreach (static::SOFT_DELETED_ARRAY as $table_name => $classname) {
            $this->deleteRecord($table_name, $classname);
        }

        // get all deleted_at, deleted_user_id's column
        $tables = \Schema::getTableListing();
        foreach ($tables as $table) {
            $this->dropDeletedRecord($table);

            $this->dropSuuidUnique($table);
        }

        //
        if (!Schema::hasColumn('custom_view_columns', 'options')) {
            Schema::table('custom_view_columns', function (Blueprint $table) {
                $table->json('options')->after('order')->nullable();
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
        if (Schema::hasTable('custom_view_columns') && Schema::hasColumn('custom_view_columns', 'options')) {
            Schema::table('custom_view_columns', function (Blueprint $table) {
                $table->dropColumn('options');
            });
        }
        
        foreach (static::ADD_INDEX_TABLES as $table_name => $column_name) {
            if (Schema::hasTable($table_name)) {
                Schema::table($table_name, function (Blueprint $table) use ($column_name) {
                    $table->dropIndex([$column_name]);
                });
            }
        }
    }

    /**
     * drop custom table's table
     */
    protected function dropExmTables()
    {
        if (!Schema::hasColumn('custom_tables', 'deleted_at')) {
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
    protected function deleteRecord($table_name, $classname)
    {
        if (!Schema::hasColumn($table_name, 'deleted_at')) {
            return;
        }

        $classname::whereNotNull('deleted_at')->get()->each(function ($row) {
            $row->delete();
        });
    }

    /**
     * add key's index
     */
    protected function addIndex()
    {
        foreach (static::ADD_INDEX_TABLES as $table_name => $column_name) {
            $columns = \Schema::getIndexDefinitions($table_name, $column_name);
 
            if (count($columns) > 0) {
                continue;
            }

            Schema::table($table_name, function (Blueprint $t) use ($column_name) {
                $t->index([$column_name]);
            });
        }
    }

    /**
     * add deleted_at key's index
     */
    protected function addDeletedIndex()
    {
        // add deleted_at index in custom values table
        if (count(Schema::getIndexDefinitions('custom_values', 'deleted_at')) == 0) {
            Schema::table('custom_values', function (Blueprint $t) {
                $t->index(['deleted_at']);
            });
        }
        
        $tables = \Schema::getTableListing();
        foreach ($tables as $table) {
            if (stripos($table, 'exm__') === false) {
                continue;
            }

            $columns = collect(\Schema::getColumnListing($table))->filter(function ($row) {
                return $row == 'deleted_at';
            });

            if (count($columns) == 0) {
                continue;
            }

            // check index
            if (count(Schema::getIndexDefinitions($table, 'deleted_at')) > 0) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->index(['deleted_at'], 'custom_values_deleted_at_index');
            });
        }
    }

    /**
     * drop deleted record
     */
    protected function dropDeletedRecord($table)
    {
        if (stripos($table, 'exm__') === 0 || $table == 'custom_values') {
            return;
        }

        $columns = collect(\Schema::getColumnListing($table))->filter(function ($row) {
            return in_array($row, ['deleted_at', 'deleted_user_id']);
        });

        if (count($columns) == 0) {
            return;
        }

        foreach ($columns as $column) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropColumn($column);
            });
        }
    }
    
    /**
     * drop deleted record
     */
    protected function dropSuuidUnique($table)
    {
        $columns = \Schema::getUniqueDefinitions($table, 'suuid');
        if (count($columns) == 0) {
            return;
        }

        foreach ($columns as $column) {
            $keyName = array_get($column, 'key_name');
            
            Schema::table($table, function (Blueprint $t) use ($keyName, $table) {
                $t->dropUnique($keyName);

                if (stripos($table, 'exm__') === 0 || $table == 'custom_values') {
                    $t->index(['suuid'], 'custom_values_suuid_index');
                } else {
                    $t->index(['suuid']);
                }
            });
        }
    }
}
