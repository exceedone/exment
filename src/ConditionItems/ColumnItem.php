<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Form\Field\ChangeField;
use Exceedone\Exment\Validator\ChangeFieldRule;
use Encore\Admin\Form\Field;

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
     * get Update Type Condition
     */
    public function getOperationUpdateType()
    {
        $isEnableSystem = false;

        $item = $this->getFormColumnItem();
        if(isset($item)){
            $isEnableSystem = ColumnType::isOperationEnableSystem($item->getCustomColumn()->column_type);
        }

        if(!$isEnableSystem){
            return parent::getOperationUpdateType();
        }
        
        return collect(Enums\OperationUpdateType::values())->map(function ($val) {
            return ['id' => $val->lowerkey(), 'text' => exmtrans('custom_operation_data.operation_update_type_options.'.$val->lowerkey())];
        });
    }
    
    /**
     * get Update Type Condition
     */
    public function getOperationFilterValue($target_key, $target_name, $show_condition_key = true)
    {
        $item = $this->getFormColumnItem();
        $options = Enums\OperationValueType::getOperationValueOptions($target_key, $item->getCustomColumn());
        
        if(empty($options)){
            return $this->getFilterValue($target_key, $target_name, $show_condition_key);
        }

        // system update, set select items
        $field = new ChangeField($this->className, $this->label);
        $field->rules([new ChangeFieldRule($this->custom_table, $this->label, $this->target)]);
        $field->adminField(function () use ($target_key, $show_condition_key, $options) {
            $field = new Field\Select($this->elementName, [$this->label]);
            $field->options($options);
            
            return $field;
        });
        $field->setElementName($this->elementName);

        $view = $field->render();
        return json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
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

        $result = $column_item->options([
            'filterKind' => FilterKind::FORM,
        ])->setCustomValue(["value.$column_name" => $condition->condition_value])->text();

        return $result . FilterOption::getConditionKeyText($condition->condition_key);
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
        $custom_column = CustomColumn::getEloquent($value);

        return ($custom_column->column_view_name ?? null) . ($showFilter ? FilterOption::getConditionKeyText($key) : '');
    }
    
    /**
     * Get Condition Label
     *
     * @return void
     */
    public function getConditionLabel(Condition $condition)
    {
        $custom_column = CustomColumn::getEloquent($condition->target_column_id);
        return $custom_column->column_view_name ?? null;
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
    
    /**
     * Set condition query. For data list and use workflow status
     *
     * @param [type] $query
     * @param [type] $tableName
     * @param [type] $custom_table
     * @param string $authorityTableName target table name. WORKFLOW_AUTHORITY or WORKFLOW_VALUE_AUTHORITY
     * @return void
     */
    public static function setConditionQuery($query, $tableName, $custom_table, $authorityTableName = SystemTableName::WORKFLOW_AUTHORITY)
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
            $query->orWhere(function ($query) use ($custom_column, $tableName, $ids, $authorityTableName) {
                $indexName = $custom_column->getIndexColumnName();
                
                $query->where($authorityTableName . '.related_id', $custom_column->id)
                    ->where($authorityTableName . '.related_type', ConditionTypeDetail::COLUMN()->lowerkey());
                    
                if ($custom_column->column_type == ColumnType::USER) {
                    $query->where($tableName . '.' . $indexName, \Exment::user()->getUserId());
                } else {
                    $query->whereIn($tableName . '.' . $indexName, $ids);
                }
            });
        }
    }
}
