<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;

/**
 * @phpstan-consistent-constructor
 * @property mixed $view_column_target_id
 * @property mixed $suuid
 * @property mixed $custom_view_id
 * @property mixed $custom_operation
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder orderBy($column, $direction = 'asc')
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomOperationColumn extends ModelBase
{
    use Traits\CustomViewColumnTrait;
    use Traits\ClearCacheTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'operation_update_type'];
    protected $casts = ['options' => 'json'];

    public function custom_operation()
    {
        return $this->belongsTo(CustomOperation::class, 'custom_operation_id');
    }
    public function getViewColumnTableIdAttribute()
    {
        $parent = $this->custom_operation;
        return isset($parent) ? $parent->custom_table_id : null;
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
    public function getUpdateValueTextAttribute()
    {
        $update_value_text = array_get($this->attributes, 'update_value_text');
        if (is_null($update_value_text)) {
            return null;
        }

        if (is_string($update_value_text)) {
            $array = json_decode_ex($update_value_text);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $update_value_text;
    }

    /**
     * set view_filter_condition_value_text.
     * * we have to convert int if view_filter_condition_value is array*
     */
    public function setUpdateValueTextAttribute($update_value)
    {
        if (is_array($update_value)) {
            $array = array_filter($update_value, function ($val) {
                return !is_null($val);
            });
            $this->attributes['update_value_text'] = json_encode($array);
        } else {
            $this->attributes['update_value_text'] = $update_value;
        }
    }

    public function getOperationUpdateTypeAttribute()
    {
        return $this->getOption('operation_update_type');
    }

    public function setOperationUpdateTypeAttribute($operation_update_type)
    {
        return $this->setOption('operation_update_type', $operation_update_type);
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (is_null(array_get($model, 'update_value_text'))) {
                $model->update_value_text = '';
            }
        });
    }
}
