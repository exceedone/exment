<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Select;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\Define;

class WorkflowItem extends SystemItem
{
    protected $table_name = 'workflow_values';

    protected static $addStatusSubQuery = false;

    protected static $addWorkUsersSubQuery = false;

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

    /**
     * get text(for display)
     */
    protected function _text($v)
    {
        return $this->getWorkflowValue($v, false);
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    protected function _html($v)
    {
        return $this->getWorkflowValue($v, true);
    }

    /**
     * Get workflow item as status name string
     *
     * @param bool $html is call as html, set true
     * @return string
     */
    protected function getWorkflowValue($val, $html)
    {
        if (boolval(array_get($this->options, 'summary'))) {
            if (isset($val)) {
                $model = WorkflowStatus::find($val);

                $status_name = array_get($model, 'status_name');

                return $html ? esc_html($status_name) : $status_name;
            }
        }

        // if null, get default status name
        if (!isset($val)) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
            if (!$workflow) {
                return null;
            }

            $status_name = WorkflowStatus::getWorkflowStatusName(null, $workflow);

            return $html ? esc_html($status_name) : $status_name;
        } elseif (is_string($val)) {
            return $val;
        } else {
            $status_name = array_get($val, 'status_name');
            return $html ? esc_html($status_name) : $status_name;
        }
    }
    
    public function getFilterField($value_type = null)
    {
        $field = new Select($this->name(), [$this->label()]);

        // get workflow statuses
        $workflow = Workflow::getWorkflowByTable($this->custom_table);
        $options = $workflow->getStatusOptions() ?? [];

        $field->options($options);
        $field->default($this->value);

        return $field;
    }

    /**
     * get
     */
    public function getTableName()
    {
        return $this->table_name;
    }

    /**
     * create subquery for join
     */
    public static function getStatusSubquery($query, $custom_table)
    {
        if (static::$addStatusSubQuery) {
            return;
        }
        static::$addStatusSubQuery = true;

        $tableName = getDBTableName($custom_table);
        $subquery = \DB::table($tableName)
            ->leftJoin(SystemTableName::WORKFLOW_VALUE, function ($join) use ($tableName, $custom_table) {
                $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_id', "$tableName.id")
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                    ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', true);
            })->select(["$tableName.id as morph_id", 'morph_type', 'workflow_status_from_id', 'workflow_status_to_id']);
            
        $query->joinSub($subquery, 'workflow_values', function ($join) use ($tableName) {
            $join->on($tableName . '.id', 'workflow_values.morph_id');
        });
    }

    /**
     * create subquery for join
     */
    public static function getWorkUsersSubQuery($query, $custom_table, $or_option = false)
    {
        if (static::$addWorkUsersSubQuery) {
            return;
        }
        static::$addWorkUsersSubQuery = true;

        $tableName = getDBTableName($custom_table);

        /////// first query. has workflow value's custom value
        $subquery = \DB::table($tableName)
            ->join(SystemTableName::VIEW_WORKFLOW_VALUE_UNION, function ($join) use($tableName, $custom_table) {
                $join->on(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.custom_value_id', "$tableName.id")
                    ->where(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.custom_value_type', $custom_table->table_name)
                    ->where(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.workflow_table_id', $custom_table->id)
                    ;
            })
            ///// add authority function for user or org
            ->where(function ($query) use ($tableName, $custom_table) {
                $classes = [
                    \Exceedone\Exment\ConditionItems\UserItem::class,
                    \Exceedone\Exment\ConditionItems\OrganizationItem::class,
                    \Exceedone\Exment\ConditionItems\ColumnItem::class,
                    \Exceedone\Exment\ConditionItems\SystemItem::class,
                ];

                foreach ($classes as $class) {
                    $class::setWorkflowConditionQuery($query, $tableName, $custom_table);
                }
            })
            ->distinct()
            ->select([$tableName .'.id  as morph_id']);

        
        /////// second query. not has workflow value's custom value
        $subquery2 = \DB::table($tableName)
            ->join(SystemTableName::VIEW_WORKFLOW_START, function ($join) use($tableName, $custom_table) {
                $join->where(SystemTableName::VIEW_WORKFLOW_START . '.workflow_table_id', $custom_table->id)
                    ;
            })
            // filtering not contains workflow value
            ->whereNotExists(function ($query) use ($tableName, $custom_table) {
                $query->select(\DB::raw(1))
                    ->from(SystemTableName::WORKFLOW_VALUE)
                    ->whereColumn(SystemTableName::WORKFLOW_VALUE . '.morph_id',  "$tableName.id")
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                    ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', 1)
                    ;
            })
            ///// add authority function for user or org
            ->where(function ($query) use ($tableName, $custom_table) {
                $classes = [
                    \Exceedone\Exment\ConditionItems\UserItem::class,
                    \Exceedone\Exment\ConditionItems\OrganizationItem::class,
                    \Exceedone\Exment\ConditionItems\ColumnItem::class,
                    \Exceedone\Exment\ConditionItems\SystemItem::class,
                ];

                foreach ($classes as $class) {
                    $class::setWorkflowConditionQuery($query, $tableName, $custom_table);
                }
            })
            ->union($subquery)
            ->distinct()
            ->select([$tableName .'.id as morph_id']);
 
        // join query is $or_option is true then leftJoin
        $join = $or_option ? 'leftJoinSub' : 'joinSub';
        $query->{$join}($subquery2, 'workflow_values_wf', function ($join) use ($tableName) {
            $join->on($tableName . '.id', 'workflow_values_wf.morph_id');
        });
    }

    /**
     * set workflow status or work user condition
     */
    public static function scopeWorkflow($query, $view_column_target_id, $custom_table, $condition, $status, $or_option = false)
    {
        $enum = SystemColumn::getEnum($view_column_target_id);
        if ($enum == SystemColumn::WORKFLOW_WORK_USERS) {
            static::scopeWorkflowWorkUsers($query, $custom_table, $condition, $status, $or_option);
        } else {
            static::scopeWorkflowStatus($query, $custom_table, $condition, $status, $or_option);
        }
    }

    /**
     * set workflow status condition
     */
    public static function scopeWorkflowStatus($query, $custom_table, $condition, $status, $or_option = false)
    {
        ///// Introduction: When the workflow status is "start", one of the following two conditions is required.
        ///// *No value in workflow_values ​​when registering data for the first time
        ///// *When workflow_status_id of workflow_values ​​is null. Ex.Rejection

        // if $status is start
        if ($status == Define::WORKFLOW_START_KEYNAME) {
            if ($condition == FilterOption::NE) {
                $func = $or_option ? 'orWhereNotNull': 'whereNotNull';
            } else {
                $func = $or_option ? 'orWhereNull': 'whereNull';
            }
            $query->{$func}('workflow_status_to_id');
        } else {
            $mark = ($condition == FilterOption::NE) ? '<>' : '=';
            $func = $or_option ? 'orWhere': 'where';
            $query->{$func}('workflow_status_to_id', $mark, $status);
        }

        return $query;
    }
    
    /**
     * set workflow work users condition
     */
    protected static function scopeWorkflowWorkUsers($query, $custom_table, $condition, $value, $or_option = false)
    {
        $func = $or_option ? 'orWhereNotNull': 'whereNotNull';
        $query->{$func}('workflow_values_wf.morph_id');

        return $query;
    }
}
