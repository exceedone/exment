<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\NotifyTargetType;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowValue;

class Column extends NotifyTargetBase
{
    /**
     * CustomColumn
     *
     * @var string|CustomColumn
     */
    protected $column;

    public function __construct(Notify $notify, array $action_setting, $column)
    {
        parent::__construct($notify, $action_setting);

        $this->column = $column;
    }

    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        $result = collect();

        $custom_table = $custom_value->custom_table;
        $custom_column = CustomColumn::getEloquent($this->column, $custom_table);

        if (!isset($custom_column)) {
            return $result;
        }

        // get target's value
        $target_value = $custom_value->getValue($custom_column);

        if (!isset($target_value)) {
            return $result;
        }

        if (!is_list($target_value)) {
            $target_value = [$target_value];
        }

        foreach ($target_value as $v) {
            if (!isset($v)) {
                continue;
            }

            // if email, return as only email
            if ($custom_column->column_type == ColumnType::EMAIL) {
                $result->push(NotifyTarget::getModelAsEmail($v));
            }

            // if select table is organization
            elseif ($custom_column->column_type == ColumnType::ORGANIZATION) {
                collect(NotifyTarget::getModelsAsOrganization($v, $custom_column))->each(function ($item) use (&$result) {
                    $result->push($item);
                });
            }

            // if select table is user
            elseif ($custom_column->column_type == ColumnType::USER) {
                $result->push(NotifyTarget::getModelAsUser($v));
            }

            // if select table(cotains user)
            elseif (ColumnType::isSelectTable($custom_column->column_type)) {
                // get email column
                $select_target_table = $custom_column->select_target_table;
                $email_column = $select_target_table ? $select_target_table->custom_columns->first(function ($custom_column) {
                    return $custom_column->column_type == ColumnType::EMAIL;
                }) : null;
                $result->push(NotifyTarget::getModelAsSelectTable($v, NotifyTargetType::EMAIL_COLUMN, $email_column));
            }
        }

        return $result;
    }

    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        return $this->getModels($custom_value, null);
    }
}
