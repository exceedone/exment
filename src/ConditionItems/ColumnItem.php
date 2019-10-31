<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\SystemTableName;

class ColumnItem extends ConditionItemBase implements ConditionItemInterface
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
        $custom_column = CustomColumn::getEloquent($condition->target_column_id);
        $value = array_get($custom_value, 'value.' . $custom_column->column_name);

        return $this->compareValue($condition, $value);
    }
    
    /**
     * get condition value text.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function getConditionText(Condition $condition)
    {
        $custom_column = CustomColumn::getEloquent($condition->target_column_id);
        
        $column_name = $custom_column->column_name;
        $column_item = $custom_column->column_item;

        return $column_item->setCustomValue(["value.$column_name" => $condition->condition_value])->text();
    }

    /**
     * Check has workflow authority
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function hasAuthority($workflow_authority, $custom_value, $targetUser)
    {
        $custom_column = CustomColumn::find($workflow_authority->related_id);
        if (!ColumnType::isUserOrganization($custom_column->column_type)) {
            return false;
        }
        $auth_values = array_get($custom_value, 'value.' . $custom_column->column_name);
        if (is_null($auth_values)) {
            return false;
        }
        if (!is_array($auth_values)) {
            $auth_values = [$auth_values];
        }

        switch ($custom_column->column_type) {
            case ColumnType::USER:
                return in_array($targetUser->id, $auth_values);
            case ColumnType::ORGANIZATION:
                $ids = $targetUser->belong_organizations->pluck('id')->toArray();
                return collect($auth_values)->contains(function ($auth_value) use ($ids) {
                    return collect($ids)->contains($auth_value);
                });
        }
        return false;
    }
    
    public static function setConditionQuery($query, $tableName, $custom_table)
    {
        /// get user or organization list
        $custom_columns = CustomColumn::allRecordsCache(function ($custom_column) use ($custom_table) {
            if ($custom_table->id != $custom_column->custom_table_id) {
                return false;
            }
            if (!$custom_column->index_enabled) {
                return false;
            }
            if (!ColumnType::isUserOrganization($custom_column->column_type)) {
                return false;
            }
            return true;
        });

        $ids = \Exment::user()->base_user->belong_organizations->pluck('id')->toArray();

        foreach ($custom_columns as $custom_column) {
            $query->orWhere(function ($query) use ($custom_column, $tableName, $ids) {
                $indexName = $custom_column->getIndexColumnName();
                
                $query->where(SystemTableName::WORKFLOW_AUTHORITY . '.related_id', $custom_column->id)
                    ->where(SystemTableName::WORKFLOW_AUTHORITY . '.related_type', ConditionTypeDetail::COLUMN()->lowerkey());
                    
                if ($custom_column->column_type == ColumnType::USER) {
                    $query->where($tableName . '.' . $indexName, \Exment::user()->id);
                } else {
                    $query->whereIn($tableName . '.' . $indexName, $ids);
                }
            });
        }
    }
}
