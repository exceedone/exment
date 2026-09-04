<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\MultipleSelect;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class WorkflowItem extends SystemItem
{
    // @phpstan-ignore-next-line
    protected $table_name = 'workflow_values';

    /**
     * get workflow
     */
    protected function getWorkflow(): ?Workflow
    {
        return Workflow::getWorkflowByTable($this->custom_table);
    }


    /**
     * whether column is enabled index.
     *
     */
    // @phpstan-ignore-next-line
    public function sortable()
    {
        return false;
    }

    /**
     * get sql query column name
     */
    protected function getSqlColumnName(bool $appendTable)
    {
        // get SystemColumn enum
        $option = SystemColumn::getOption(['name' => $this->column_name]);
        if (!isset($option)) {
            $sqlname = $this->column_name;
        } else {
            $sqlname = array_get($option, 'sqlname');
        }

        if ($appendTable) {
            return $this->sqlUniqueTableName() .'.'. $sqlname;
        }
        return $sqlname;
    }

    // @phpstan-ignore-next-line
    public static function getItem(...$args)
    {
        list($custom_table, $column_name, $custom_value) = $args + [null, null, null];
        return new self($custom_table, $column_name, $custom_value);
    }

    /**
     * get text(for display)
     */
    // @phpstan-ignore-next-line
    protected function _text($v)
    {
        return $this->getWorkflowValue($v, false);
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    // @phpstan-ignore-next-line
    protected function _html($v)
    {
        return $this->getWorkflowValue($v, true);
    }

    /**
     * Get workflow item as status name string
     *
     * @param bool $html is call as html, set true
     * @return string|null
     */
    // @phpstan-ignore-next-line
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
            $workflow = $this->getWorkflow();
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

    // @phpstan-ignore-next-line
    public function getFilterField($value_type = null)
    {
        $field = new MultipleSelect($this->name(), [$this->label()]);

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
    // @phpstan-ignore-next-line
    public function getTableName()
    {
        return $this->table_name;
    }


    /**
     * get real table name.
     * If workflow, this name is workflow view.
     */
    public function sqlRealTableName()
    {
        return $this->getTableName();
    }

    /**
     * Set admin filter options
     *
     * @param $filter
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    // @phpstan-ignore-next-line
    protected function setAdminFilterOptions(&$filter)
    {
        $option = $this->getSystemColumnOption();
        $workflow = $this->getWorkflow();

        if ($workflow) {
            // Whether executed search.
            $searched = boolval(request()->get($filter->getId()));

            if (array_get($option, 'name') == SystemColumn::WORKFLOW_WORK_USERS) {
                $filter->checkbox([1 => 'YES']);
                $key = Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK;
            } else {
                $filter->select($workflow->getStatusOptions());
                $key = Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK;
            }

            if ($searched) {
                System::setRequestSession($key, true);
            }
        }
    }
}
