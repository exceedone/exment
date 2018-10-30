<?php

namespace Exceedone\Exment\Model;


getCustomTableExt();

class CustomTable extends ModelBase
{
    use CustomTableExt; // CustomTableExt:Dynamic Creation trait it defines relationship.
    use AutoSUuid;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['authority' => 'json'];

    protected $guarded = ['id', 'suuid', 'system_flg'];

    public function custom_columns(){
        return $this->hasMany(CustomColumn::class, 'custom_table_id');
    }
    public function custom_views(){
        return $this->hasMany(CustomView::class, 'custom_table_id')
            ->orderBy('view_type')
            ->orderBy('id');
    }
    public function custom_forms(){
        return $this->hasMany(CustomForm::class, 'custom_table_id');
    }
    public function custom_relations(){
        return $this->hasMany(CustomRelation::class, 'parent_custom_table_id');
    }
    
    public function child_custom_relations(){
        return $this->hasMany(CustomRelation::class, 'child_custom_table_id');
    }
    
    public function from_custom_copies(){
        return $this->hasMany(CustomCopy::class, 'from_custom_table_id');
    }
    
    public function custom_form_block_target_tables(){
        return $this->hasMany(CustomFormBlock::class, 'form_block_target_table_id');
    }
    
    /**
     * Find record using table name
     * @param mixed $model_name
     * @return mixed
     */
    public static function findByName($model_name, $with_custom_columns = false){
        $query = static::where('table_name', $model_name);
        if($with_custom_columns){
            $query = $query->with('custom_columns');
        }
        return $query->first();
    }

    /**
     * Find record using database table name
     * @param mixed $table_name
     * @return mixed
     */
    public static function findByDBTableName($db_table_name, $with_custom_columns = false){
        $query = static::where('suuid', preg_replace('/^exm__/', '', $db_table_name));
        if($with_custom_columns){
            $query = $query->with('custom_columns');
        }
        return $query->first();
    }

    /**
     * get custom table eloquent.
     * @param mixed $obj id, table_name, CustomTable object, CustomValue object. 
     */
    public static function getEloquent($obj){
        // get id or array value
        if ($obj instanceof stdClass || is_array($obj)) {
            // get id or table_name
            if(array_key_value_exists('id', $obj)){
                $obj = array_get($obj, 'id');
            }elseif(array_key_value_exists('table_name', $obj)){
                $obj = array_get($obj, 'table_name');
            }
            else{
                return null;
            }
        }

        // get eloquent model
        if (is_numeric($obj)) {
            $obj = static::find($obj);
        }elseif (is_string($obj)) {
            $obj = static::findByName($obj);
        }
        elseif (is_array($obj)) {
            $obj = static::findByName(array_get($obj, 'table_name'));
        }
        elseif ($obj instanceof CustomTable) {
            // nothing
        }else if($obj instanceof CustomValue) {
            $obj = $obj->getCustomTable();
        }
        return $obj;
    }

    /**
     * Delete children items
     */
    public function deletingChildren(){
        foreach($this->custom_columns as $item){
            $item->deletingChildren();
        }
        foreach($this->custom_forms as $item){
            $item->deletingChildren();
        }
        foreach($this->custom_form_block_target_tables as $item){
            $item->deletingChildren();
        }
    }

    protected static function boot() {
        parent::boot();
        
        // delete event
        static::deleting(function($model) {
            // Delete items
            $model->deletingChildren();            
            
            $model->custom_form_block_target_tables()->delete();
            $model->child_custom_relations()->delete();
            $model->custom_forms()->delete();
            $model->custom_columns()->delete();
            $model->custom_relations()->delete();

            // delete menu
            Menu::where('menu_type', Define::MENU_TYPE_TABLE)->where('menu_target', $model->id)->delete(); 
        });
    }
}
