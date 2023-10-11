<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ConditionItems\ConditionItemBase;
use Encore\Admin\Form;
use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;

/**
 * Custom value condition. Use form priority, workflow action.
 *
 * @property mixed $target_column_id
 * @property mixed $morph_type
 * @property mixed $morph_id
 * @property mixed $condition_type
 * @property mixed $condition_item
 * @property mixed $condition_key
 * @property mixed $view_filter_condition
 * @property mixed $view_filter_condition_value_text
 * @property mixed $view_column_type
 * @property mixed $view_column_target_id
 * @method static ExtendedBuilder create(array $attributes = [])
 * @phpstan-consistent-constructor
 */
class Condition extends ModelBase
{
    use Traits\ColumnOptionQueryTrait;
    use Traits\ConditionTypeTrait;

    protected $guarded = ['id'];
    protected $appends = ['condition_target'];
    protected $condition_type_key = 'condition_type';
    protected $condition_column_key = 'target_column_id';

    public function getCustomTable()
    {
        if ($this->morph_type == 'custom_form_priority') {
            $parent_table = CustomFormPriority::find($this->morph_id);
            if (isset($parent_table)) {
                return $parent_table->custom_form->custom_table;
            }
        }
        return null;
    }

    public function getConditionTargetAttribute()
    {
        return $this->getConditionTarget();
    }

    /**
     * set condition_target.
     */
    public function setConditionTargetAttribute($condition_target)
    {
        $params = $this->getViewColumnTargetItems($condition_target, null);

        $this->condition_type = $params[0];
        $this->target_column_id = $params[2];
    }

    /**
     * Get target condition.
     *
     * @return string|null
     */
    public function getConditionTarget()
    {
        return $this->condition_item ? $this->condition_item->getQueryKey($this) : null;
    }

    /**
     * get priority condition text.
     */
    public function getConditionTextAttribute()
    {
        if (!isset($this->condition_item)) {
            return null;
        }

        //$this->condition_item->setCustomTable($parent_table->custom_form->custom_table);
        return $this->condition_item->getConditionLabel($this) . ' : ' . $this->condition_item->getConditionText($this);
    }

    /**
     * get edited condition_value_text.
     */
    public function getConditionValueAttribute()
    {
        $condition_value = array_get($this->attributes, 'condition_value');
        if (is_null($condition_value)) {
            return null;
        }

        if (is_string($condition_value)) {
            $array = json_decode_ex($condition_value);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $condition_value;
    }

    /**
     * set condition_value_text.
     * * we have to convert int if view_filter_condition_value is array*
     */
    public function setConditionValueAttribute($condition_value)
    {
        if (is_array($condition_value)) {
            $array = array_filter($condition_value, function ($val) {
                return !is_null($val);
            });
            $this->attributes['condition_value'] = json_encode($array);
        } else {
            $this->attributes['condition_value'] = $condition_value;
        }
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     */
    public function isMatchCondition($custom_value)
    {
        $item = ConditionItemBase::getItem($custom_value->custom_table, $this->condition_type, $this->target_column_id);
        if (is_null($item)) {
            return false;
        }

        return $item->isMatchCondition($this, $custom_value);
    }

    /**
     * get work conditions.
     * *Convert to "_X" format to array. ex.enabled_0
     *
     * @param array $work_conditions
     * @return array
     */
    public static function getWorkConditions($work_conditions)
    {
        $work_conditions = jsonToArray($work_conditions);

        // modify work_condition_filter
        $new_work_conditions = [];
        foreach ($work_conditions as $key => $work_condition) {
            // preg_match using key(as filter)
            preg_match('/(?<key>.+)_(?<no>[0-9])+\[(?<index>.+)\]\[(?<name>.+)\]/u', $key, $match);

            if (!is_nullorempty($match)) {
                $new_work_conditions[array_get($match, 'no')][array_get($match, 'key')][array_get($match, 'index')][array_get($match, 'name')] = $work_condition;
                continue;
            }

            // preg_match using key (as enabled)
            preg_match('/(?<key>.+)_(?<no>[0-9])/u', $key, $match);
            if (!is_nullorempty($match)) {
                $new_work_conditions[array_get($match, 'no')][array_get($match, 'key')] = $work_condition;
                continue;
            }

            // default
            $new_work_conditions[$key] = $work_condition;
        }

        // re-loop and replace work_condition_filter
        foreach ($new_work_conditions as $key => &$new_work_condition) {
            if (!array_has($new_work_condition, 'workflow_conditions')) {
                if (empty($new_work_condition)) {
                    unset($new_work_conditions[$key]);
                }
                continue;
            }

            $filters = [];
            foreach ($new_work_condition['workflow_conditions'] as $k => &$n) {
                // remove "_remove_" array
                if (array_has($n, Form::REMOVE_FLAG_NAME)) {
                    if (boolval(array_get($n, Form::REMOVE_FLAG_NAME))) {
                        array_forget($new_work_condition, $k);
                        break;
                    }
                    array_forget($n, Form::REMOVE_FLAG_NAME);
                }
                $filters[] = $n;
                array_forget($new_work_condition['workflow_conditions'], $k);
            }

            // replace key name "_new_1" to index
            $new_work_condition['workflow_conditions'] = $filters;
        }

        return $new_work_conditions;
    }
}
