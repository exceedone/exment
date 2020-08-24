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
    public static function getWorkUsersSubQuery($query, $custom_table)
    {
        if (static::$addWorkUsersSubQuery) {
            return;
        }
        static::$addWorkUsersSubQuery = true;

        $tableName = getDBTableName($custom_table);

        /////// first query. not has workflow value's custom value
        $subquery = \DB::table($tableName)
        ->join(SystemTableName::WORKFLOW_TABLE, function ($join) use ($custom_table) {
            $join->where(SystemTableName::WORKFLOW_TABLE . '.custom_table_id', $custom_table->id)
                ->where(SystemTableName::WORKFLOW_TABLE . '.active_flg', 1)
                ;
        })
        ->join(SystemTableName::WORKFLOW, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
                ;
        })
        ->join(SystemTableName::WORKFLOW_ACTION, function ($join) {
            $join->on(SystemTableName::WORKFLOW_ACTION . '.workflow_id', SystemTableName::WORKFLOW . ".id")
                ->where(SystemTableName::WORKFLOW_ACTION . '.status_from', Define::WORKFLOW_START_KEYNAME)
                ;
        })
        ->join(SystemTableName::WORKFLOW_AUTHORITY, function ($join) {
            $join->on(SystemTableName::WORKFLOW_AUTHORITY . '.workflow_action_id', SystemTableName::WORKFLOW_ACTION . ".id")
                ;
        })->whereNotExists(function ($query) use ($tableName, $custom_table) {
            $escapeTableName = \esc_sqlTable($tableName);
            $query->select(\DB::raw(1))
                    ->from(SystemTableName::WORKFLOW_VALUE)
                    ->whereRaw(SystemTableName::WORKFLOW_VALUE . '.morph_id = ' . $escapeTableName .'.id')
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
                $class::setConditionQuery($query, $tableName, $custom_table);
            }
        })
        ->distinct()
        ->select([$tableName .'.id  as morph_id']);




        /////// second query. has workflow value's custom value
        $subquery2 = \DB::table($tableName)
        ->join(SystemTableName::WORKFLOW_VALUE, function ($join) use ($tableName, $custom_table) {
            $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_id', $tableName .'.id')
                ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', 1);
        })
        ->join(SystemTableName::WORKFLOW_TABLE, function ($join) use ($custom_table) {
            $join->where(SystemTableName::WORKFLOW_TABLE . '.custom_table_id', $custom_table->id)
                ->where(SystemTableName::WORKFLOW_TABLE . '.active_flg', 1)
                ;
        })
        ->join(SystemTableName::WORKFLOW, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ->on(SystemTableName::WORKFLOW_VALUE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
                ;
        })
        ->join(SystemTableName::WORKFLOW_ACTION, function ($join) {
            $join
            ->on(SystemTableName::WORKFLOW_ACTION . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ->where('ignore_work', false)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', Define::WORKFLOW_START_KEYNAME)
                        ->whereNull(SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id')
                    ;
                })->orWhere(function ($query) {
                    $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', \DB::raw(SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id'))
                    ;
                });
            });
        })
        ->join(SystemTableName::WORKFLOW_AUTHORITY, function ($join) {
            $join->on(SystemTableName::WORKFLOW_AUTHORITY . '.workflow_action_id', SystemTableName::WORKFLOW_ACTION . ".id")
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
                $class::setConditionQuery($query, $tableName, $custom_table);
            }
        })
        ->distinct()
        ->select([$tableName .'.id  as morph_id']);

        /////// third query. has workflow value's custom value and workflow value authorities
        $subquery3 = \DB::table($tableName)
        ->join(SystemTableName::WORKFLOW_VALUE, function ($join) use ($tableName, $custom_table) {
            $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_id', $tableName .'.id')
                ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', 1);
        })
        ->join(SystemTableName::WORKFLOW_TABLE, function ($join) use ($custom_table) {
            $join->where(SystemTableName::WORKFLOW_TABLE . '.custom_table_id', $custom_table->id)
                ->where(SystemTableName::WORKFLOW_TABLE . '.active_flg', 1)
                ;
        })
        ->join(SystemTableName::WORKFLOW, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ->on(SystemTableName::WORKFLOW_VALUE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
                ;
        })
        ->join(SystemTableName::WORKFLOW_ACTION, function ($join) {
            $join
            ->on(SystemTableName::WORKFLOW_ACTION . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ->where('ignore_work', false)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', Define::WORKFLOW_START_KEYNAME)
                        ->whereNull(SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id')
                    ;
                })->orWhere(function ($query) {
                    $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', \DB::raw(SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id'))
                    ;
                });
            });
        })
        ->join(SystemTableName::WORKFLOW_VALUE_AUTHORITY, function ($join) {
            $join->on(SystemTableName::WORKFLOW_VALUE_AUTHORITY . '.workflow_value_id', SystemTableName::WORKFLOW_VALUE . ".id")
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
                $class::setConditionQuery($query, $tableName, $custom_table, SystemTableName::WORKFLOW_VALUE_AUTHORITY);
            }
        })


        

        ->union($subquery)
        ->union($subquery2)
        
        ->distinct()
        ->select([$tableName .'.id as morph_id']);
 
        $query->joinSub($subquery3, 'workflow_values_wf', function ($join) use ($tableName) {
            $join->on($tableName . '.id', 'workflow_values_wf.morph_id');
        });

        //$query = \DB::query()->fromSub($query, 'sub');
    }

    /**
     * set workflow status or work user condition
     */
    public static function scopeWorkflow($query, $view_column_target_id, $custom_table, $condition, $status, $or_option = false)
    {
        $enum = SystemColumn::getEnum($view_column_target_id);
        if ($enum == SystemColumn::WORKFLOW_WORK_USERS) {
            //static::scopeWorkflowWorkUsers($query, $custom_table, $condition, $status);
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
    protected static function scopeWorkflowWorkUsers($query, $custom_table, $condition, $value)
    {
        return $query;
    }
}
