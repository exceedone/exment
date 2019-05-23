<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Select;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\WorkflowStatus;

class Workflowitem extends SystemItem
{
    protected $table_name = 'workflow_values';

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

    protected function getTargetValue($custom_value)
    {
        $val = parent::getTargetValue($custom_value);

        if (boolval(array_get($this->options, 'summary'))) {
            if (isset($val)) {
                $model = WorkflowStatus::find($val);
                $val = array_get($model, 'status_name');
            }
        }

        return $val;
    }
    
    public function getFilterField($value_type = null)
    {
        $field = new Select($this->name(), [$this->label()]);
        $field->options(function ($value) {
            // get DB option value
            return WorkflowStatus::where('workflow_id', array_get($this->custom_table, 'workflow_id'))
                ->get()->pluck("status_name", "id");
        });
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
    public function getSubquery() {
        return \DB::table('workflow_values')
            ->select(['workflow_values.morph_id as id', 'workflow_values.workflow_status_id'])
            ->where('workflow_values.morph_type', array_get($this->custom_table, 'table_name'))
            ->where('workflow_values.enabled_flg', 1);
    }
}
