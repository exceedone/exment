<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Form;

class WorkflowActionCondition
{
    protected $view_column_target;
    protected $view_filter_condition;
    protected $view_filter_condition_value;

    public function __construct($value){
        $this->view_column_target = array_get($value, 'view_column_target');
        $this->view_filter_condition = array_get($value, 'view_filter_condition');
        $this->view_filter_condition_value = array_get($value, 'view_filter_condition_value');
    }

    /**
     * get work conditions.
     * *Convert to "_X" format to array. ex.enabled_0
     *
     * @param [type] $work_conditions
     * @return void
     */
    public static function getWorkConditions($work_conditions){
        $work_conditions = jsonToArray($work_conditions);

        // modify work_condition_filter
        $new_work_conditions = [];
        foreach($work_conditions as $key => $work_condition){
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
        foreach($new_work_conditions as &$new_work_condition){
            if(!array_has($new_work_condition, 'filter')){
                continue;
            }

            $filters = [];
            foreach($new_work_condition['filter'] as $k => &$n){
                // remove "_remove_" array
                if(array_has($n, Form::REMOVE_FLAG_NAME)){
                    if(boolval(array_get($n, Form::REMOVE_FLAG_NAME))){
                        array_forget($new_work_condition, $k);
                        break;
                    }
                    array_forget($n, Form::REMOVE_FLAG_NAME);
                }
                $filters[] = $n;
                array_forget($new_work_condition['filter'], $k);
            }

            // replace key name "_new_1" to index
            $new_work_condition['filter'] = $filters;
        }

        return $new_work_conditions;
    }
}
