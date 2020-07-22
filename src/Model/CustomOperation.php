<?php

namespace Exceedone\Exment\Model;

class CustomOperation extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    
    protected $casts = ['options' => 'json'];
    protected $appends = ['condition_join'];

   
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_operation_columns()
    {
        return $this->hasMany(CustomOperationColumn::class, 'custom_operation_id');
    }

    public function custom_operation_conditions()
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
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
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
        
    public function getConditionJoinAttribute()
    {
        return $this->getOption('condition_join');
    }

    public function setConditionJoinAttribute($val)
    {
        $this->setOption('condition_join', $val);

        return $this;
    }
    
    public function deletingChildren()
    {
        $this->custom_operation_columns()->delete();
        $this->custom_operation_conditions()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
    
    /**
     * check if operation target data
     *
     * @param CustomValue $custom_value
     * @param CustomOperationType|array $operation_types
     * @return boolean is match operation type and conditions.
     */
    public function isOperationTarget($custom_value, $operation_types)
    {
        if (!$this->matchOperationType($operation_types)) {
            return false;
        }
        return $this->isMatchCondition($custom_value);
    }

    /**
     * Match(contain) operation type.
     *
     * @param CustomOperationType|array $operation_types
     * @return bool is match operation type. if $operation_types is multiple, whether contains.
     */
    public function matchOperationType($operation_types)
    {
        $operation_types = toArray($operation_types);

        return collect($operation_types)->contains(function ($value, $key) {
            return $value == $this->operation_type;
        });
    }

    /**
     * check if custom_value is match for conditions.
     * @param CustomValue $custom_value
     * @return bool is match condition.
     */
    public function isMatchCondition($custom_value)
    {
        $is_or = $this->condition_join == 'or';
        foreach ($this->custom_operation_conditions as $condition) {
            if ($is_or) {
                if ($condition->isMatchCondition($custom_value)) {
                    return true;
                }
            } else {
                if (!$condition->isMatchCondition($custom_value)) {
                    return false;
                }
            }
        }
        return !$is_or;
    }
    
    /**
     * Check all operations related to custom-table
     * If operation type and filter is matched, then update target column's value
     *
     * @param CustomOperationType|array $operation_types
     * @param CustomValue $custom_value
     * @param boolean $is_save
     */
    public static function operationExecuteEvent($operation_types, &$custom_value, $is_save = false)
    {
        $custom_table = $custom_value->custom_table;
        $operations = $custom_table->operations;
        $update_flg = false;

        if (count($operations) > 0) {
            foreach ($operations as $operation) {
                // if $operation_type is trigger and custom-value is match for conditions, execute
                if ($operation->isOperationTarget($custom_value, $operation_types)) {
                    collect($operation->custom_operation_columns)->each(function ($operation_column) use(&$custom_value, &$update_flg) {
                        $custom_value->setValue($operation_column->custom_column->column_name, $operation_column['update_value_text']);
                        $update_flg = true;
                    });
                }
            }
        }

        if ($is_save && $update_flg) {
            $custom_value->save();
        }
    }

    /**
     * execute update operation 
     *
     * @param CustomTable $custom_table
     * @param int $id
     * @return bool success or not
     */
    public function execute($custom_table, $id) {
        $model = $custom_table->getValueModel()->find($id);

        $updates = collect($this->custom_operation_columns)->mapWithKeys(function ($operation_column) {
            $column_name= 'value->'.$operation_column->custom_column->column_name;
            return [$column_name => $operation_column['update_value_text']];
        })->toArray();

        $model->update($updates);

        return true;
    }
}
