<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\FormDataType;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;

class FormDataItem extends ConditionDetailBase implements ConditionItemInterface
{
    public function getFilterOption()
    {
        return $this->getFilterOptionConditon();
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        $form_data_type = System::requestSession(Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE);
        if (is_null($form_data_type)) {
            return false;
        }

        return $this->compareValue($condition, $form_data_type);
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
        return collect($value)->map(function ($v) {
            return exmtrans("condition.form_data_type_options.$v");
        })->implode(",");
    }

    /**
     * Get change field
     *
     * @param string $key
     * @param bool $show_condition_key
     * @return \Encore\Admin\Form\Field
     */
    public function getChangeField($key, $show_condition_key = true)
    {
        $options = FormDataType::transArray('condition.form_data_type_options');
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);
        return $field->options($options);
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
        return false;
    }
}
