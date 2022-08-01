<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\WorkflowTargetSystem;

class SystemItem extends ConditionItemBase implements ConditionItemInterface
{
    use ColumnSystemItemTrait;

    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        return false;
    }

    /**
     * get text.
     *
     * @param string $key
     * @param string $value
     * @param bool $showFilter
     * @return string
     */
    public function getText($key, $value, $showFilter = true)
    {
        $enum = WorkflowTargetSystem::getEnum($value);
        return isset($enum) ? exmtrans('common.' . $enum->lowerkey()) : null;
    }


    /**
     * Check has workflow authority with this item.
     *
     * @param WorkflowAuthorityInterface $workflow_authority
     * @param CustomValue|null $custom_value
     * @param CustomValue $targetUser
     * @return boolean
     */
    public function hasAuthority(WorkflowAuthorityInterface $workflow_authority, ?CustomValue $custom_value, $targetUser)
    {
        return $workflow_authority->related_id == WorkflowTargetSystem::CREATED_USER && $custom_value->created_user_id == $targetUser->id;
    }

    public static function setWorkflowConditionQuery($query, $tableName, $custom_table)
    {
        $query->orWhere(function ($query) use ($tableName) {
            $query->where('authority_related_id', WorkflowTargetSystem::CREATED_USER)
                ->where('authority_related_type', ConditionTypeDetail::SYSTEM()->lowerkey())
                ->where($tableName . '.created_user_id', \Exment::getUserId());
        });
    }


    /**
     * Set query sort for custom value's sort
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param CustomViewSort $custom_view_sort
     * @return void
     */
    public function setQuerySort($query, CustomViewSort $custom_view_sort)
    {
        $column_item = $custom_view_sort->column_item;
        if (!isset($column_item)) {
            return;
        }
        // if cannot sort column, break
        if (!$column_item->sortable()) {
            return;
        }

        $view_column_target = $column_item->getSortWrapTableColumn();
        $sort_order = $custom_view_sort->sort == Enums\ViewColumnSort::ASC ? 'asc' : 'desc';
        //set order
        // $view_column_target is wraped
        $query->orderByRaw("$view_column_target $sort_order");
    }


    /**
     * get select column display text
     *
     * @param Model\CustomViewColumn|Model\CustomViewSummary $custom_view_column
     * @param Model\CustomTable $custom_table
     * @return string|null
     */
    public function getSelectColumnText($custom_view_column, Model\CustomTable $custom_table): ?string
    {
        $column_view_name = array_get($custom_view_column, 'view_column_name');

        $system_info = SystemColumn::getOption(['id' => array_get($custom_view_column, 'view_column_target_id')]);
        if (is_nullorempty($column_view_name)) {
            $column_view_name = exmtrans('common.'.$system_info['name']);
        }

        return $column_view_name;
    }


    /**
     * get Column Key Name
     *
     * @param string $column_type_target
     * @param Model\CustomColumn $custom_column
     * @return string|null
     */
    public function getColumnValueKey($column_type_target, $custom_column): ?string
    {
        return SystemColumn::getOption(['id' => $column_type_target])['name'] ?? null;
    }


    /**
     * get column and table id
     *
     * @return array offset 0 : column id, 1 : table id
     */
    public function getColumnAndTableId($column_name, $custom_table): array
    {
        $target_column_id = SystemColumn::getOption(['name' => $column_name])['id'] ?? null;
        // set parent table info
        if (isset($custom_table)) {
            $target_table_id = $custom_table->id;
        }

        return [
            $target_column_id ?? null,
            $target_table_id ?? null,
        ];
    }
}
