<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\FilterOption;
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
        if (isset($item)) {
            $isEnableSystem = ColumnType::isOperationEnableSystem($item->getCustomColumn()->column_type);
        }

        if (!$isEnableSystem) {
            return parent::getOperationUpdateType();
        }

        return collect(Enums\OperationUpdateType::values())->map(function ($val) {
            return ['id' => $val->lowerkey(), 'text' => exmtrans('custom_operation.operation_update_type_options.'.$val->lowerkey())];
        });
    }

    /**
     * get Operation filter value for field, Call as Ajax
     */
    public function getOperationFilterValueAjax($target_key, $target_name, $show_condition_key = true)
    {
        $field = $this->getOperationFilterValue($target_key, $target_name, $show_condition_key);
        if (is_null($field)) {
            return [];
        }

        $view = $field->render();
        return json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }

    /**
     * get Operation filter value for field
     */
    public function getOperationFilterValue($target_key, $target_name, $show_condition_key = true)
    {
        $field = new ChangeField($this->className, $this->label);
        $field->rules([new ChangeFieldRule($this->custom_table, $this->label, $this->target)]);
        $field->adminField(function ($data, $field) use ($target_key, $target_name, $show_condition_key) {
            return $this->getOperationFilterValueChangeField($target_key, $target_name, $show_condition_key);
        });
        $field->setElementName($this->elementName);

        return $field;
    }


    /**
     * get Operation filter value for field
     */
    public function getOperationFilterValueChangeField($target_key, $target_name, $show_condition_key = true)
    {
        $item = $this->getFormColumnItem();
        $options = Enums\OperationValueType::getOperationValueOptions($target_key, $item->getCustomColumn());

        if (empty($options)) {
            return $this->getChangeField(null, $show_condition_key);
        }

        $field = new Field\Select($this->elementName, [exmtrans('custom_operation.update_value_text')]);
        $field->options($options);

        return $field;
    }

    /**
     * get condition value text.
     *
     * @param Condition $condition
     * @return string
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
     * @return string|null
     */
    public function getConditionLabel(Condition $condition)
    {
        $custom_column = CustomColumn::getEloquent($condition->target_column_id);
        return $custom_column->column_view_name ?? null;
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
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param string $tableName
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setWorkflowConditionQuery($query, $tableName, $custom_table)
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

                $query->where('authority_related_id', $custom_column->id)
                    ->where('authority_related_type', ConditionTypeDetail::COLUMN()->lowerkey());

                if ($custom_column->column_type == ColumnType::USER) {
                    if ($custom_column->isMultipleEnabled()) {
                        $query->whereInArrayString($tableName . '.' . $indexName, \Exment::getUserId());
                    } else {
                        $query->where($tableName . '.' . $indexName, \Exment::getUserId());
                    }
                } else {
                    $query->whereIn($tableName . '.' . $indexName, $ids);
                }
            });
        }
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
        $column = $custom_view_column->custom_column;
        $column_view_name = array_get($custom_view_column, 'view_column_name');

        if (is_nullorempty($column_view_name)) {
            $column_view_name = array_get($column, 'column_view_name');
            // if table is not equal target table, add table name to column name.
            if ($custom_table->id != array_get($column, 'custom_table_id')) {
                $column_view_name = array_get($column->custom_table, 'table_view_name') . '::' . $column_view_name;
            }
        }

        return $column_view_name;
    }

    /**
     * Whether this column is number
     *
     * @param Model\CustomViewColumn|Model\CustomViewSummary $custom_view_column
     * @return boolean
     */
    public function isSelectColumnNumber($custom_view_column): bool
    {
        $column = $custom_view_column->custom_column;
        return ColumnType::isCalc(array_get($column, 'column_type'));
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
        return "value.{$custom_column->column_name}";
    }



    /**
     * get column and table id
     *
     * @return array offset 0 : column id, 1 : table id
     */
    public function getColumnAndTableId($column_name, $custom_table): array
    {
        $target_column = CustomColumn::getEloquent($column_name, $custom_table);
        // get table and column id
        if (isset($target_column)) {
            return [
                $target_column->id ?? null,
                $target_column->custom_table_id
            ];
        }

        return [null, null];
    }
}
