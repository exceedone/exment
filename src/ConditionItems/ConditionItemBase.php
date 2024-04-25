<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowValueAuthority;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Form\Field\ChangeField;
use Exceedone\Exment\Validator\ChangeFieldRule;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

/**
 *
 * @method mixed getFilterOption()
 * @method mixed getChangeField($key, $show_condition_key = true)
 * @method string getText($key, $value, $showFilter = true)
 */
abstract class ConditionItemBase implements ConditionItemInterface
{
    protected $custom_table;
    protected $target;

    /**
     * Dynamic field element name
     *
     * @var string
     */
    protected $elementName;

    /**
     * Dynamic field class name
     *
     * @var string
     */
    protected $className;

    /**
     * filter kind (view, workflow, form)
     *
     * @var bool
     */
    protected $filterKind;

    /**
     * Dynamic field label
     *
     * @var string|null
     */
    protected $label;

    public function __construct(?CustomTable $custom_table, $target)
    {
        $this->custom_table = $custom_table;
        $this->target = $target;
    }

    public function setElement($elementName, $className, $label)
    {
        $this->elementName = $elementName;
        $this->className = $className;
        $this->label = $label;

        return $this;
    }

    public function filterKind($filterKind = null)
    {
        if (isset($filterKind)) {
            $this->filterKind = $filterKind;
        }

        return $this;
    }


    /**
     * Get condition item
     */
    public static function getItem(?CustomTable $custom_table, string $target, string $target_column_id)
    {
        if (is_nullorempty($target)) {
            return null;
        }

        return static::getConditionItem($custom_table, $target, $target_column_id);
    }


    /**
     * Get condition item by request
     */
    public static function getItemByRequest(?CustomTable $custom_table, ?string $target_query)
    {
        if (is_nullorempty($target_query)) {
            return null;
        }

        // separate ? for removing table id
        $target = explode('?', $target_query)[0];

        if (!$custom_table) {
            // get model by key
            $column_item = CustomViewFilter::getColumnItem($target_query);
            $custom_table = $column_item->getCustomTable();
        }

        // convert enum using target_query
        $enum = ConditionType::getEnumByTargetKey(strtolower($target));
        return static::getConditionItem($custom_table, $enum, $target);
    }


    /**
     * get detail item by authority
     *
     * @param CustomTable|null $custom_table
     * @param WorkflowAuthority|WorkflowValueAuthority $authority
     * @return \Exceedone\Exment\ConditionItems\ConditionItemBase
     */
    public static function getDetailItemByAuthority(?CustomTable $custom_table, $authority)
    {
        return static::getConditionDetailItem($custom_table, $authority->related_type);
    }


    /**
     * Get condition type
     *
     * @param CustomTable|null $custom_table
     * @param string $target Condition Type or key name
     * @param string|null $target_column_id
     * @return self|null
     */
    protected static function getConditionItem(?CustomTable $custom_table, string $target, ?string $target_column_id): ?self
    {
        $enum = ConditionType::getEnum(strtolower($target));
        switch ($enum) {
            case ConditionType::COLUMN:
                return new ColumnItem($custom_table, $target_column_id);
            case ConditionType::SYSTEM:
                return new SystemItem($custom_table, $target_column_id);
            case ConditionType::PARENT_ID:
                return new ParentIdItem($custom_table, $target_column_id);
            case ConditionType::WORKFLOW:
                return new WorkflowItem($custom_table, $target_column_id);
            case ConditionType::CONDITION:
                return static::getConditionDetailItem($custom_table, $target_column_id);
        }
        return null;
    }


    /**
     * Get condition detail item
     *
     * @param CustomTable|null $custom_table
     * @param string $target
     * @return ConditionItemBase|null
     */
    protected static function getConditionDetailItem(?CustomTable $custom_table, string $target): ?self
    {
        $enum = ConditionTypeDetail::getEnum(strtolower($target));
        switch ($enum) {
            case ConditionTypeDetail::USER:
                return new UserItem($custom_table, $target);
            case ConditionTypeDetail::ORGANIZATION:
                return new OrganizationItem($custom_table, $target);
            case ConditionTypeDetail::ROLE:
                return new RoleGroupItem($custom_table, $target);
            case ConditionTypeDetail::SYSTEM:
                return new SystemItem($custom_table, $target);
            case ConditionTypeDetail::LOGIN_USER_COLUMN:
                return new LoginUserColumnItem($custom_table, $target);
            case ConditionTypeDetail::COLUMN:
                return new ColumnItem($custom_table, $target);
            case ConditionTypeDetail::FORM:
                return new FormDataItem($custom_table, $target);
        }
        return null;
    }


    /**
     * get filter condition
     */
    public function getFilterCondition()
    {
        $options = $this->getFilterOption();

        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.filter_condition_options.'.array_get($array, 'name'))];
        });
    }

    /**
     * get Update Type Condition
     */
    public function getOperationUpdateType()
    {
        return collect([Enums\OperationUpdateType::DEFAULT])->map(function ($val) {
            return ['id' => $val, 'text' => exmtrans('custom_operation.operation_update_type_options.'.$val)];
        });
    }

    /**
     * get Update Type Condition
     */
    public function getOperationFilterValue($target_key, $target_name, $show_condition_key = true)
    {
        return $this->getFilterValue($target_key, $target_name, $show_condition_key);
    }

    /**
     * get filter value
     */
    public function getFilterValueAjax($target_key, $target_name, $show_condition_key = true)
    {
        $field = $this->getFilterValue($target_key, $target_name, $show_condition_key);
        if (is_null($field)) {
            return [];
        }

        $view = $field->render();
        return json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }

    /**
     * get filter value
     */
    public function getFilterValue($target_key, $target_name, $show_condition_key = true)
    {
        if (is_nullorempty($this->target) || is_nullorempty($target_key) || is_nullorempty($target_name)) {
            return null;
        }

        $field = new ChangeField($this->className, $this->label);
        $field->rules([new ChangeFieldRule($this->custom_table, $this->label, $this->target)]);
        $field->adminField(function () use ($target_key, $show_condition_key) {
            return $this->getChangeField($target_key, $show_condition_key);
        });
        $field->setElementName($this->elementName);

        return $field;
    }

    protected function getFilterOptionConditon()
    {
        return array_get(FilterOption::FILTER_OPTIONS(), FilterType::CONDITION);
    }

    /**
     * Get Condition Label
     *
     * @param Condition $condition
     * @return array|bool|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Translation\Translator|mixed|string|null
     */
    public function getConditionLabel(Condition $condition)
    {
        $enum = ConditionTypeDetail::getEnum($condition->target_column_id);
        return $enum->transKey('condition.condition_type_options') ?: null;
    }

    /**
     * compare condition value and saved value
     *
     * @param Condition $condition
     * @param mixed $value
     * @return bool
     */
    protected function compareValue(Condition $condition, $value)
    {
        $viewFilterItem = ViewFilterBase::makeForCondition($condition);
        return $viewFilterItem->compareValue($value, $condition->condition_value);
    }


    /**
     * get condition value text.
     *
     * @param Condition $condition
     * @return string
     */
    public function getConditionText(Condition $condition)
    {
        return $this->getText($condition->condition_key, $condition->condition_value);
    }


    /**
     * get query key Name for display
     *
     * @return string|null
     */
    public function getQueryKey(Condition $condition): ?string
    {
        return $condition->target_column_id;
    }


    /**
     * Set query sort for custom value's sort
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param Model\CustomViewSort $custom_view_sort
     * @return void
     */
    public function setQuerySort($query, Model\CustomViewSort $custom_view_sort)
    {
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
        return null;
    }


    /**
     * Whether this column is number
     *
     * @param Model\CustomViewColumn|Model\CustomViewSummary $custom_view_column
     * @return boolean
     */
    public function isSelectColumnNumber($custom_view_column): bool
    {
        return false;
    }


    /**
     * get Column Key Name for getting value
     *
     * @param string $column_type_target
     * @param Model\CustomColumn $custom_column
     * @return string|null
     */
    public function getColumnValueKey($column_type_target, $custom_column): ?string
    {
        return null;
    }



    /**
     * get column and table id
     *
     * @return array offset 0 : column id, 1 : table id
     */
    public function getColumnAndTableId($column_name, $custom_table): array
    {
        return [null, null];
    }
}
