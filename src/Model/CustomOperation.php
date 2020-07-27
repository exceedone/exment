<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums;

class CustomOperation extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    
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
     * @param int|string $id signle id or id string
     * @return bool success or not
     */
    public function execute($custom_table, $id) {
        $ids = stringToArray($id);
        $custom_values = $custom_table->getValueModel()->find($ids);

        // check isMatchCondition
        $notMatchConditions = $custom_values->filter(function($custom_value){
            return !$this->isMatchCondition($custom_value);
        });
        if($notMatchConditions->count() > 0){
            $label = $notMatchConditions->map(function($notMatchCondition){
                return $notMatchCondition->getLabel();
            })->implode(exmtrans('common.separate_word'));

            return getAjaxResponse([
                'result'  => false,
                'swal' => exmtrans('common.error'),
                'swaltext' => exmtrans('custom_value.message.operation_contains_notmatch_condition', $label),
            ]);
        }

        // Update value
        \DB::transaction(function () {
            foreach ($custom_values as $custom_value) {
                $updates = $this->getUpdateValues($custom_value);
                $custom_value->update($updates);
            }
        });

        return true;
    }

    /**
     * Get update values. Convert update_value, or set system value.
     *
     * @param CustomValue $model
     * @return array "value"'s array.
     */
    protected function getUpdateValues($model){
        return collect($this->custom_operation_columns)->mapWithKeys(function ($operation_column) {
            $custom_column = $operation_column->custom_column;
            if(is_nullorempty($custom_column)){
                return null;
            }
            $column_name = 'value->'.$custom_column->column_name;

            // if update as system value, set system
            if(Enums\ColumnType::isOperationEnableSystem($custom_column->column_type) && isMatchString($operation_column->operation_update_type, Enums\OperationUpdateType::SYSTEM)){
                return [$column_name => Enums\OperationValueType::getOperationValue($operation_column['update_value_text'])];
            }

            return [$column_name => $operation_column['update_value_text']];
        })->toArray();
    }
}
