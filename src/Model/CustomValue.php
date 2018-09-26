<?php

namespace Exceedone\Exment\Model;
use Encore\Admin\Facades\Admin;

class CustomValue extends ModelBase
{        
    use AutoSUuid;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $casts = ['value' => 'json'];

    // user value_authoritable. it's all authority data. only filter  morph_type
    public function value_authoritable_users(){
        return $this->morphToMany(getModelName(Define::SYSTEM_TABLE_NAME_USER), 'morph', 'value_authoritable', 'morph_id', 'related_id')
            ->withPivot('related_id', 'related_type')
            ->wherePivot('related_type', Define::SYSTEM_TABLE_NAME_USER)
            ;
    }

    // user value_authoritable. it's all authority data. only filter  morph_type
    public function value_authoritable_organizations(){
        return $this->morphToMany(getModelName(Define::SYSTEM_TABLE_NAME_ORGANIZATION), 'morph', 'value_authoritable', 'morph_id', 'related_id')
            ->withPivot('related_id', 'related_type')
            ->wherePivot('related_type', Define::SYSTEM_TABLE_NAME_ORGANIZATION)
            ;
    }

    public function getCustomTable(){
        return CustomTable::findByDBTableName($this->getTable());
    }

    /**
     * get Authoritable values.
     * this function selects value_authoritable, and get all values.
     */
    public function getAuthoritable($related_type){
        if($related_type == Define::SYSTEM_TABLE_NAME_USER){
            $query = $this
            ->value_authoritable_users()
            ->where('related_id', Admin::user()->base_user_id);
        }else if($related_type == Define::SYSTEM_TABLE_NAME_ORGANIZATION){
            $query = $this
            ->value_authoritable_organizations()
            ->whereIn('related_id', Admin::user()->getOrganizationIds());
        }

        return $query->get();
    }

    public function getValue($key = null, $label = false){
        return getValue($this, $key, $label);
    }

    public function setValue($key, $val){
        if(!isset($key)){return;}
        $value = $this->value;
        if(is_null($value)){$value = [];}
        $value[$key] = $val;
        $this->value = $value;
    }
    
    protected static function boot() {
        parent::boot();
        
        static::deleting(function($model) {
            $parent_table = $model->getCustomTable();
            // delete custom relation is 1:n value
            $relations = CustomRelation
                ::where('parent_custom_table_id', $parent_table->id)
                ->where('relation_type', Define::RELATION_TYPE_ONE_TO_MANY)
                ->get();
            // loop relations
            foreach($relations as $relation){
                $child_table = CustomTable::find($relation->child_custom_table_id);
                // find keys
                getModelName($child_table)
                    ::where('parent_id', $model->id)
                    ->where('parent_type', $parent_table->table_name)
                    ->delete();
            }
        });
    }
}
