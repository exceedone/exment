<?php

namespace Exceedone\Exment\Model;


class CustomColumn extends ModelBase
{
    use AutoSUuid;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $casts = ['options' => 'json'];

    protected $guarded = ['id', 'suuid'];

    public function custom_table(){
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_columns(){
        return $this->hasMany(CustomFormColumn::class, 'form_column_target_id')
            ->where('form_column_type', Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN);
    }

    /**
     * get custom column eloquent. (use table)
     */
    public static function getEloquent($column_obj, $table_obj = null){
        // get column eloquent model
        if ($column_obj instanceof CustomColumn) {
            return $column_obj;
        }
        elseif (is_array($column_obj)) {
            return CustomColumn::find(array_get($column_obj, 'id'));
        }
        elseif (is_numeric($column_obj)) {
            return CustomColumn::find($column_obj);
        }
        // else,call $table_obj
        else{
            // get table Eloquent
            $table_obj = CustomTable::getEloquent($table_obj);
            // if not exists $table_obj, return null.
            if(!isset($table_obj)){
                return null;
            }
            
            // get column name
            if (is_string($column_obj)) {
                $column_name = $column_obj;
            }
            elseif(is_array($column_obj)){
                $column_name = array_get($column_obj, 'column_name');
            }
            elseif ($column_obj instanceof stdClass) {
                $column_name = (array)$column_obj;
            }
            return $table_obj->custom_columns()->where('column_name', $column_name)->first() ?? null;
        }
        return null;
    }

    public function deletingChildren(){
        $this->custom_form_columns()->delete();
    }

    protected static function boot() {
        parent::boot();
        
        // delete event
        static::deleting(function($model) {
            // Delete items
            $model->deletingChildren();

            // execute alter column
            alterColumn($model->custom_table->table_name, $model->column_name, true);
        });
    }
}
