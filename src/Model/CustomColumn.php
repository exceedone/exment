<?php

namespace Exceedone\Exment\Model;
use Exceedone\Exment\Enums\CustomFormColumnType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CustomColumn extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $casts = ['options' => 'json'];

    protected $guarded = ['id', 'suuid'];

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_columns()
    {
        return $this->hasMany(CustomFormColumn::class, 'form_column_target_id')
            ->where('form_column_type', CustomFormColumnType::COLUMN);
    }

    public function custom_view_columns()
    {
        return $this->hasMany(CustomViewColumn::class, 'view_column_target');
    }

    /**
     * get custom column eloquent. (use table)
     */
    public static function getEloquent($column_obj, $table_obj = null)
    {
        if (!isset($column_obj)) {
            return null;
        }
        // get column eloquent model
        if ($column_obj instanceof CustomColumn) {
            return $column_obj;
        } elseif (is_array($column_obj)) {
            return CustomColumn::find(array_get($column_obj, 'id'));
        } elseif (is_numeric($column_obj)) {
            return CustomColumn::find($column_obj);
        }
        // else,call $table_obj
        else {
            // get table Eloquent
            $table_obj = CustomTable::getEloquent($table_obj);
            // if not exists $table_obj, return null.
            if (!isset($table_obj)) {
                return null;
            }
            
            // get column name
            if (is_string($column_obj)) {
                $column_name = $column_obj;
            } elseif (is_array($column_obj)) {
                $column_name = array_get($column_obj, 'column_name');
            } elseif ($column_obj instanceof \stdClass) {
                $column_name = array_get((array)$column_obj, 'column_name');
            }
            return $table_obj->custom_columns()->where('column_name', $column_name)->first() ?? null;
        }
        return null;
    }
    
    /**
     * Alter table column
     * For add table virtual column
     * @param bool $forceDropIndex drop index. calling when remove column.
     */
    function alterColumn($forceDropIndex = false)
    {
        // Create index --------------------------------------------------
        $table = $this->custom_table;
        $column_name = $this->column_name;

        //DB table name
        $db_table_name = getDBTableName($table);
        $db_column_name = $this->getIndexColumnName(false, false);

        // Create table
        $table->createTable();

        // get whether search_enabled column
        $search_enabled = boolval(array_get($this, 'options.search_enabled'));
        
        // check table column field exists.
        $exists = Schema::hasColumn($db_table_name, $db_column_name);

        $index_name = "index_$db_column_name";
        //  if search_enabled = false, and exists, then drop index
        // if column exists and (search_enabled = false or forceDropIndex)
        if ($exists && ($forceDropIndex || (!boolval($search_enabled)))) {
            DB::beginTransaction();
            try {
                // ALTER TABLE
                DB::statement("ALTER TABLE $db_table_name DROP INDEX $index_name;");
                DB::statement("ALTER TABLE $db_table_name DROP COLUMN $db_column_name;");
                DB::commit();
            } catch (Exception $exception) {
                DB::rollback();
                throw $exception;
            }
        }
        // if search_enabled = true, not exists, then create index
        elseif ($search_enabled && !$exists) {
            DB::beginTransaction();
            try {
                // ALTER TABLE
                DB::statement("ALTER TABLE $db_table_name ADD $db_column_name nvarchar(768) GENERATED ALWAYS AS (json_unquote(json_extract(`value`,'$.$column_name'))) VIRTUAL;");
                DB::statement("ALTER TABLE $db_table_name ADD index $index_name($db_column_name)");
    
                DB::commit();
            } catch (Exception $exception) {
                DB::rollback();
                throw $exception;
            }
        }
    }
    
    /**
     * Get index column column name. This function uses only search-enabled column.
     * @param CustomColumn|array $obj
     * @param boolean $label if get the columnname only get column label.
     * @param boolean $alterColumn if not exists column on db, execute alter column. if false, only get name
     * @return string
     */
    public function getIndexColumnName($label = false, $alterColumn = true)
    {
        $name = 'column_'.array_get($this, 'suuid').($label ? '_label' : '');
        $db_table_name = getDBTableName($this->custom_table);

        // if not exists, execute alter column
        if($alterColumn && !Schema::hasColumn($db_table_name, $name)){
            $this->alterColumn();
        }
        return $name;
    }


    public function getOption($key)
    {
        return $this->getJson('options', $key);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    public function deletingChildren()
    {
        $this->custom_form_columns()->delete();
        $this->custom_view_columns()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();

            // execute alter column
            $model->alterColumn(true);
        });
    }
}
