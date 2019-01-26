<?php

namespace Exceedone\Exment\Model;
use Exceedone\Exment\Enums\RelationType;

class CustomRelation extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\UseRequestSessionTrait;

    protected $with = ['parent_custom_table', 'child_custom_table'];
    
    public function parent_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'parent_custom_table_id');
    }

    public function child_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'child_custom_table_id');
    }

    /**
     * get relations by parent table
     */
    public static function getRelationsByParent($parent_table, $relation_type = null){
        $parent_table = CustomTable::getEloquent($parent_table);

        return static::allRecords(function($record) use($parent_table, $relation_type){
            if($record->parent_custom_table_id != array_get($parent_table, 'id')){
                return false;
            }
            if(isset($relation_type) && $record->relation_type != $relation_type){
                return false;
            }
            return true;
        });
    }

    /**
     * get relation by child table. (Only one record)
     */
    public static function getRelationByChild($child_table, $relation_type = null){
        $items = static::getRelationsByChild($child_table, $relation_type);
        if(isset($items)){
            return $items->first();
        }
        return null;
    }

    /**
     * get relation by child table.
     */
    public static function getRelationsByChild($child_table, $relation_type = null){
        $child_table = CustomTable::getEloquent($child_table);

        return static::allRecords(function($record) use($child_table, $relation_type){
            if($record->child_custom_table_id != array_get($child_table, 'id')){
                return false;
            }
            if(isset($relation_type) && $record->relation_type != $relation_type){
                return false;
            }
            return true;
        });
    }

    /**
     * Get relation name.
     * @param CustomRelation $relation_obj
     * @return string
     */
    public function getRelationName()
    {
        return static::getRelationNameByTables($this->parent_custom_table, $this->child_custom_table);
    }

    /**
     * Get relation name using parent and child table.
     * @param $parent
     * @param $child
     * @return string
     */
    public static function getRelationNamebyTables($parent, $child)
    {
        $parent_suuid = CustomTable::getEloquent($parent)->suuid ?? null;
        $child_suuid = CustomTable::getEloquent($child)->suuid ?? null;
        if (is_null($parent_suuid) || is_null($child_suuid)) {
            return null;
        }
        return "pivot__{$parent_suuid}_{$child_suuid}";
    }

    /**
     * get sheet name for excel, csv
     */
    public function getSheetName(){
        if($this->relation_type == RelationType::MANY_TO_MANY){
            return $this->parent_custom_table->table_name . '_' . $this->child_custom_table->table_name;
        }
        return $this->child_custom_table->table_name;
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = []){
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * import template
     */
    public static function importTemplate($json, $options = []){
        $parent_id = CustomTable::getEloquent(array_get($json, 'parent_custom_table_name'))->id ?? null;
        $child_id = CustomTable::getEloquent(array_get($json, 'child_custom_table_name'))->id ?? null;
        if (!isset($parent_id) || !isset($child_id)) {
            return;
        }
        
        // Create relations. --------------------------------------------------
        $custom_relation = CustomRelation::firstOrNew([
            'parent_custom_table_id' => $parent_id,
            'child_custom_table_id' => $child_id
            ]);
        $custom_relation->parent_custom_table_id = $parent_id;
        $custom_relation->child_custom_table_id = $child_id;
        $custom_relation->relation_type = RelationType::getEnumValue(array_get($json, 'relation_type'));
        $custom_relation->save();

        return $custom_relation;
    }
}
