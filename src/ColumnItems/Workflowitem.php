<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Select;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\Define;

class Workflowitem extends SystemItem
{
    protected $table_name = 'workflow_values';

    protected static $addSubQuery = false;

    /**
     * whether column is enabled index.
     *
     */
    public function sortable()
    {
        return false;
    }

    /**
     * get sql query column name
     */
    protected function getSqlColumnName()
    {
        // get SystemColumn enum
        $option = SystemColumn::getOption(['name' => $this->column_name]);
        if (!isset($option)) {
            $sqlname = $this->column_name;
        } else {
            $sqlname = array_get($option, 'sqlname');
        }
        return $this->table_name.'.'.$sqlname;
    }

    public static function getItem(...$args)
    {
        list($custom_table, $column_name, $custom_value) = $args + [null, null, null];
        return new self($custom_table, $column_name, $custom_value);
    }

    protected function getTargetValue($html)
    {
        $val = parent::getTargetValue($html);

        if (boolval(array_get($this->options, 'summary'))) {
            if (isset($val)) {
                $model = WorkflowStatus::find($val);
                return array_get($model, 'status_name');
            }else{
                return $this->custom_table->workflow->start_status_name;
            }
        }

        return $val;
    }
    
    public function getFilterField($value_type = null)
    {
        $field = new Select($this->name(), [$this->label()]);

        // get workflow statuses
        $workflow =$this->custom_table->workflow;
        $options = $workflow->getStatusOptions() ?? [];

        $field->options($options);
        $field->default($this->value);

        return $field;
    }

    /**
     * get 
     */
    public function getTableName() {
        return $this->table_name;
    }

    /**
     * create subquery for join
     */
    public static function getSubquery($query, $custom_table) {
        if(static::$addSubQuery){
            return;
        }
        static::$addSubQuery = true;

        $tableName = getDBTableName($custom_table);
        $subquery = \DB::table($tableName)
            ->leftJoin(SystemTableName::WORKFLOW_VALUE, function ($join) use($tableName, $custom_table) {
                $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_id', "$tableName.id")
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                    ->where(SystemTableName::WORKFLOW_VALUE . '.enabled_flg', true);
            })->select(["$tableName.id as morph_id", 'morph_type', 'workflow_status_id']);
            
        $query->joinSub($subquery, 'workflow_values',  function ($join) use($tableName) {
            $join->on($tableName . '.id', 'workflow_values.morph_id');
        });
    }

    
    /**
     * set workflow status condition
     */
    public static function scopeWorkflowStatus($query, $custom_table, $condition, $status) 
    {   
        ///// Introduction: When the workflow status is "start", one of the following two conditions is required.
        ///// *No value in workflow_values ​​when registering data for the first time
        ///// *When workflow_status_id of workflow_values ​​is null. Ex.Rejection

        static::getSubquery($query, $custom_table);

        // if $status is start
        if($status == Define::WORKFLOW_START_KEYNAME){
            $func = ($condition == ViewColumnFilterOption::NE) ? 'whereNotNull' : 'whereNull';
            $query->{$func}('workflow_status_id');
        }else{
            $mark = ($condition == ViewColumnFilterOption::NE) ? '<>' : '=';
            $query->where('workflow_status_id', $mark, $status);
        }

        return $query;
    }
}
