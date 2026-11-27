<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\NotifyTargetType;
use Exceedone\Exment\Enums\NotifyTrigger;
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

    // @phpstan-ignore-next-line
    public function __construct(Notify $notify, array $action_setting, $column)
    {
        parent::__construct($notify, $action_setting);

        $this->column = $column;
    }

    // @phpstan-ignore-next-line
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        $result = collect();

        $custom_table = $custom_value->custom_table;
        $custom_column = CustomColumn::getEloquent($this->column, $custom_table);

        if (!isset($custom_column)) {
            return $result;
        }

        // a user, an organization or a record of a table: the records the column points at
        if (ColumnType::isSelectTable($custom_column->column_type)) {
            return $this->getSelectTableModels($custom_value, $custom_column);
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
        }

        return $result;
    }

    /**
     * The notify targets of a user, organization or select table column.
     *
     * @param CustomValue $custom_value
     * @param CustomColumn $custom_column
     * @return Collection<int, NotifyTarget|null>
     */
    protected function getSelectTableModels(CustomValue $custom_value, CustomColumn $custom_column): Collection
    {
        $result = collect();

        $select_target_table = $custom_column->select_target_table;
        if (!isset($select_target_table)) {
            return $result;
        }

        $readsStored = $this->readsStoredTargets();
        if ($readsStored) {
            // the ids as stored, whoever saved the record
            $target_values = NotifyTarget::getTargetValues($select_target_table, array_get($custom_value->value, $custom_column->column_name));
        } else {
            // what the login user sees: a record they cannot see is not a CustomValue here
            // (getValue gives the "no permission" text for it), and is left out
            $target_value = $custom_value->getValue($custom_column);
            if (!is_list($target_value)) {
                $target_value = [$target_value];
            }
            $target_values = collect($target_value)->filter(function ($v) {
                return $v instanceof CustomValue;
            });
        }

        $email_column = null;
        if (!ColumnType::isUserOrganization($custom_column->column_type)) {
            $email_column = $select_target_table->custom_columns->first(function ($custom_column) {
                return $custom_column->column_type == ColumnType::EMAIL;
            });
        }

        foreach ($target_values as $v) {
            // if select table is organization
            if ($custom_column->column_type == ColumnType::ORGANIZATION) {
                NotifyTarget::getModelsAsOrganization($v, $custom_column, $readsStored)->each(function ($item) use (&$result) {
                    $result->push($item);
                });
            }

            // if select table is user
            elseif ($custom_column->column_type == ColumnType::USER) {
                $result->push(NotifyTarget::getModelAsUser($v));
            }

            // if select table(cotains user)
            else {
                $result->push(NotifyTarget::getModelAsSelectTable($v, NotifyTargetType::EMAIL_COLUMN, $email_column));
            }
        }

        return $result;
    }

    /**
     * Whether the targets are the records stored in the column, read without the visibility of
     * the login user (filter_multi_user, the permissions of the table pointed at).
     *
     * A notification made by a save, a schedule or a workflow goes to the users the record says,
     * whoever saved it: someone who cannot see the assignee may still add a record assigned to
     * them, and the assignee is still told. Only the names and addresses of the records are read
     * for this, and they go to nobody but the targets themselves.
     *
     * A notify button is different: its dialog lists the targets to the login user, so it lists
     * only those the login user can see.
     *
     * @return bool
     */
    protected function readsStoredTargets(): bool
    {
        return strval(array_get($this->notify, 'notify_trigger')) !== strval(NotifyTrigger::BUTTON);
    }

    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    // @phpstan-ignore-next-line
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        return $this->getModels($custom_value, null);
    }
}
