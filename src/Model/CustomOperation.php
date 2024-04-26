<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\CustomOperationType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-consistent-constructor
 * @property mixed $operation_type
 * @property mixed $operation_name
 * @property mixed $custom_table_id
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder whereNull($columns, $boolean = 'and', $not = false)
 * @method static ExtendedBuilder orderBy($column, $direction = 'asc')
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomOperation extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $casts = ['options' => 'json', 'operation_type' => 'array'];
    protected $appends = ['condition_join', 'active_flg', 'condition_reverse'];


    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_operation_columns(): HasMany
    {
        return $this->hasMany(CustomOperationColumn::class, 'custom_operation_id')
            ->where('operation_column_type', CopyColumnType::DEFAULT);
    }

    public function custom_operation_input_columns(): HasMany
    {
        return $this->hasMany(CustomOperationColumn::class, 'custom_operation_id')
            ->where('operation_column_type', CopyColumnType::INPUT);
    }

    public function custom_operation_conditions(): MorphMany
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
    }

    public function getCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->custom_table_id);
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

    public function getConditionReverseAttribute()
    {
        return $this->getOption('condition_reverse');
    }

    public function setConditionReverseAttribute($val)
    {
        $this->setOption('condition_reverse', $val);

        return $this;
    }

    public function getActiveFlgAttribute()
    {
        $active_flg = $this->getOption('active_flg');
        return is_null($active_flg) || $active_flg;
    }

    public function setActiveFlgAttribute($val)
    {
        $this->setOption('active_flg', $val);

        return $this;
    }

    public function deletingChildren()
    {
        $this->custom_operation_columns()->delete();
        $this->custom_operation_input_columns()->delete();
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

        // if contains setting's $this->operation_type
        return collect($operation_types)->contains(function ($value, $key) {
            return collect($this->operation_type)->contains(function ($o) use ($value) {
                return isMatchString($value, $o);
            });
        });
    }

    /**
     * check if custom_value is match for conditions(with reverse option).
     * @param CustomValue $custom_value
     * @return bool is match condition.
     */
    public function isMatchCondition($custom_value)
    {
        $result = $this->_isMatchCondition($custom_value);
        if (boolval($this->condition_reverse)) {
            $result = !$result;
        }
        return $result;
    }

    /**
     * check if custom_value is match for conditions.
     * @param CustomValue $custom_value
     * @return bool is match condition.
     */
    public function _isMatchCondition($custom_value)
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
                if ($operation->active_flg && $operation->isOperationTarget($custom_value, $operation_types)) {
                    $updates = $operation->getUpdateValues($custom_value);
                    $custom_value->setValue($updates);
                    $update_flg = true;
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
     * @param array $inputs input from dialog form
     * @return bool success or not
     */
    public function execute($custom_table, $id, $inputs = null)
    {
        $ids = stringToArray($id);
        $custom_values = $custom_table->getValueModel()->find($ids);

        // check isMatchCondition
        $notMatchConditions = $custom_values->filter(function ($custom_value) {
            return !$this->isMatchCondition($custom_value);
        });
        if ($notMatchConditions->count() > 0) {
            $label = $notMatchConditions->map(function ($notMatchCondition) {
                return $notMatchCondition->getLabel();
            })->implode(exmtrans('common.separate_word'));

            return exmtrans('custom_value.message.operation_contains_notmatch_condition', $label);
        }

        // Update value
        \DB::beginTransaction();
        try {
            foreach ($custom_values as $custom_value) {
                $updates = $this->getUpdateValues($custom_value, $inputs);
                $custom_value->setValueStrictly($updates)->save();
            }
            \DB::commit();
        } catch (\Exception $ex) {
            \DB::rollback();
            if ($ex instanceof ValidationException) {
                /** @phpstan-ignore-next-line */
                return array_first(array_flatten($ex->validator->getMessages()));
            }
            throw $ex;
        }

        return true;
    }

    /**
     * Get update values. Convert update_value, or set system value.
     *
     * @param CustomValue $model
     * @param array $inputs
     * @return array "value"'s array.
     */
    protected function getUpdateValues($model, $inputs = null)
    {
        $updates = collect($this->custom_operation_columns)->mapWithKeys(function ($operation_column) use ($model) {
            $custom_column = $operation_column->custom_column;
            if (is_nullorempty($custom_column)) {
                return null;
            }

            $column_name = $custom_column->column_name;
            // if update as system value, set system
            if (Enums\ColumnType::isOperationEnableSystem($custom_column->column_type) && isMatchString($operation_column->operation_update_type, Enums\OperationUpdateType::SYSTEM)) {
                return [$column_name => Enums\OperationValueType::getOperationValue($custom_column, $operation_column['update_value_text'], $model)];
            }

            return [$column_name => $operation_column['update_value_text']];
        });

        $input_updates = [];
        foreach ($this->custom_operation_input_columns as $operation_column) {
            $custom_column = $operation_column->custom_column;
            $column_name = $custom_column->column_name;
            // get input value
            $val = array_get($inputs, $column_name);
            if (isset($val)) {
                $input_updates[$column_name] = $val;
            }
        }

        return $updates->merge($input_updates)->toArray();
    }
}
