<?php

namespace Exceedone\Exment\Model;

class CustomOperationColumn extends ModelBase
{
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'update_value'];
    
    public function custom_operation()
    {
        return $this->belongsTo(CustomOperation::class, 'custom_operation_id');
    }
    public function getViewColumnTableIdAttribute()
    {
        $parent = $this->custom_operation;
        return isset($parent)? $parent->custom_table_id: null;
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setViewColumnTargetAttribute($view_column_target)
    {
        list($column_type, $column_table_id, $column_type_target) = $this->getViewColumnTargetItems($view_column_target, 'custom_operation');

        $this->view_column_type = $column_type;
        $this->view_column_target_id = $column_type_target;
    }

    /**
     * get edited view_filter_condition_value_text.
     */
    public function getUpdateValueAttribute()
    {
        if (is_string($this->update_value_text)) {
            $array = json_decode($this->update_value_text);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $this->update_value_text;
    }
    
    /**
     * set view_filter_condition_value_text.
     * * we have to convert int if view_filter_condition_value is array*
     */
    public function setUpdateValueAttribute($update_value)
    {
        if (is_array($update_value)) {
            $array = array_filter($update_value, function ($val) {
                return !is_null($val);
            });
            $this->update_value_text = json_encode($array);
        } else {
            $this->update_value_text = $update_value;
        }
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
}
