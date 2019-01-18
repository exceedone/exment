<?php

namespace Exceedone\Exment\Model;
use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Services\DynamicDBHelper;
use Exceedone\Exment\Enums\FormColumnType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CustomColumn extends ModelBase
{
    use Traits\UseRequestSessionTrait;
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
            ->where('form_column_type', FormColumnType::COLUMN);
    }

    public function custom_view_columns()
    {
        return $this->hasMany(CustomViewColumn::class, 'view_column_target_id');
    }

    public function scopeIndexEnabled($query)
    {
        return $query->whereIn('options->index_enabled', [1, "1", true]);
    }

    public function scopeUseLabelFlg($query)
    {
        return $query
            ->whereNotIn('options->use_label_flg', [0, "0"])
            ->orderBy('options->use_label_flg');
    }

    public function getColumnItemAttribute(){
        return ColumnItems\CustomItem::getItem($this, null);
    }

    /**
     * get custom column eloquent. (use table)
     */
    public static function getEloquent($column_obj, $table_obj = null)
    {
        if (!isset($column_obj)) {
            return null;
        }

        if ($column_obj instanceof \stdClass) {
            $column_obj = array_get((array)$column_obj, 'id');
        }

        // get column eloquent model
        if ($column_obj instanceof CustomColumn) {
            return $column_obj;
        } 
        
        if (is_array($column_obj)) {
            $column_obj = array_get($column_obj, 'id');
        }
        
        if (is_numeric($column_obj)) {
            return System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_COLUMN_ELOQUENT, $column_obj), function() use($column_obj){
                return static::find($column_obj);
            });
        }
        // else,call $table_obj
        else {
            // get table Eloquent
            if ($table_obj instanceof CustomTable) {
                $table_obj = CustomTable::getEloquent($table_obj);
            }elseif($table_obj instanceof CustomValue){
                $table_obj = $table_obj->custom_table;
            }
            // if not exists $table_obj, return null.
            if (!isset($table_obj)) {
                return null;
            }
            
            // get column name
            return System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_COLUMN_ELOQUENT, $table_obj->table_name . '_'.$column_obj), function() use($table_obj, $column_obj){
                return $table_obj->custom_columns()->where('column_name', $column_obj)->first() ?? null;
            });
        }
        return null;
    }
    
    /**
     * Alter table column
     * For add table virtual column
     * @param bool $forceDropIndex drop index. calling when remove column.
     */
    public function alterColumn($forceDropIndex = false)
    {
        // Create index --------------------------------------------------
        $table = $this->custom_table;
        $column_name = $this->column_name;

        //DB table name
        $db_table_name = getDBTableName($table);
        $db_column_name = $this->getIndexColumnName(false);

        // Create table
        $table->createTable();

        // get whether index_enabled column
        $index_enabled = $this->indexEnabled();
        
        // check table column field exists.
        $exists = hasColumn($db_table_name, $db_column_name);

        $index_name = "index_$db_column_name";
        //  if index_enabled = false, and exists, then drop index
        // if column exists and (index_enabled = false or forceDropIndex)
        if ($exists && ($forceDropIndex || (!boolval($index_enabled)))) {
            DynamicDBHelper::dropIndexColumn($db_table_name, $db_column_name, $index_name);
        }
        // if index_enabled = true, not exists, then create index
        elseif ($index_enabled && !$exists) {
            DynamicDBHelper::alterIndexColumn($db_table_name, $db_column_name, $index_name, $column_name);
        }
    }
    
    /**
     * Get index column column name. This function uses only search-enabled column.
     * @param CustomColumn|array $obj
     * @param boolean $alterColumn if not exists column on db, execute alter column. if false, only get name
     * @return string
     */
    public function getIndexColumnName($alterColumn = true)
    {
        $name = 'column_'.array_get($this, 'suuid');
        $db_table_name = getDBTableName($this->custom_table);

        // if not exists, execute alter column
        if($alterColumn && !hasColumn($db_table_name, $name)){
            $this->alterColumn();
        }
        return $name;
    }

    /**
     * Whether this column has index
     * @return boolean
     */
    public function indexEnabled()
    {
        return boolval(array_get($this, 'options.index_enabled'));
    }

    /**
     * Create laravel-admin select box options. for column_type "select", "select_valtext"
     */
    public function createSelectOptions()
    {
        // get value
        $column_type = array_get($this, 'column_type');
        $column_options = array_get($this, 'options');

        // get select item string
        $array_get_key = $column_type == 'select' ? 'select_item' : 'select_item_valtext';
        $select_item = array_get($column_options, $array_get_key);
        $isValueText = ($column_type == 'select_valtext');
        
        $options = [];
        if (is_null($select_item)) {
            return $options;
        }

        if (is_string($select_item)) {
            $str = str_replace(array("\r\n","\r","\n"), "\n", $select_item);
            if (isset($str) && mb_strlen($str) > 0) {
                // loop for split new line
                $array = explode("\n", $str);
                foreach ($array as $a) {
                    $this->setSelectOptionItem($a, $options, $isValueText);
                }
            }
        } elseif (is_array($select_item)) {
            foreach ($select_item as $key => $value) {
                $this->setSelectOptionItem($value, $options, $isValueText);
            }
        }

        return $options;
    }
    
    /**
     * Create laravel-admin select box option item.
     */
    protected function setSelectOptionItem($item, &$options, $isValueText)
    {
        if (is_string($item)) {
            // $isValueText is true(split comma)
            if ($isValueText) {
                $splits = explode(',', $item);
                if (count($splits) > 1) {
                    $options[mbTrim($splits[0])] = mbTrim($splits[1]);
                } else {
                    $options[mbTrim($splits[0])] = mbTrim($splits[0]);
                }
            } else {
                $options[mbTrim($item)] = mbTrim($item);
            }
        }
    }


    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
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
