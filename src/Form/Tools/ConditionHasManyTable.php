<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Validator\ChangeFieldRule;
use Exceedone\Exment\ConditionItems\ConditionItemBase;

/**
 * ConditionHasManyTable
 */
class ConditionHasManyTable
{
    protected $form;
    protected $ajax;
    protected $linkage;
    protected $targetOptions;
    protected $name;
    protected $custom_table;
    protected $label;

    protected $callbackField;

    protected $condition_target_name = 'condition_target';
    protected $condition_key_name = 'condition_key';
    protected $condition_value_name = 'condition_value';
    protected $viewFilter = false;
    protected $showConditionKey = true;

    public function __construct(&$form, $options = [])
    {
        $this->form = $form;
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        if(!array_has($options, 'label')){
            $this->label = exmtrans("custom_view.custom_view_filters");
        }
    }

    public function render()
    {
        // get key name
        $condition_target_name = $this->condition_target_name;
        $condition_key_name = $this->condition_key_name;
        $condition_value_name = $this->condition_value_name;
        $viewFilter = $this->viewFilter;

        $field = $this->form->hasManyTable($this->name, $this->label, function ($form) use ($condition_target_name, $condition_key_name, $condition_value_name, $viewFilter) {
            $field = $form->select($condition_target_name, exmtrans("condition.condition_target"))->required()
                ->options($this->targetOptions);
            if(isset($this->linkage)){
                $field->attribute([
                    'data-linkage' => $this->linkage,
                    'data-change_field_target' => $condition_target_name,
                ]);
            }

            if($this->showConditionKey){
                $form->select($condition_key_name, exmtrans("condition.condition_key"))->required()
                ->options(function ($val, $select) use ($condition_target_name, $condition_key_name, $condition_value_name, $viewFilter) {
                    if (!isset($val)) {
                        return [];
                    }

                    $data = $select->data();
                    $condition_target = array_get($data, $condition_target_name);

                    $item = ConditionItemBase::getItem($this->custom_table, $condition_target);
                    if (!isset($item)) {
                        return null;
                    }
                    $item->viewFilter($viewFilter);

                    return $item->getFilterCondition()->mapWithKeys(function ($item) {
                        return [$item['id'] => $item['text']];
                    });
                });
            }

            $label = exmtrans('condition.condition_value');
            $form->changeField($condition_value_name, $label)
                ->ajax($this->ajax)
                ->setEventTrigger("select.$condition_key_name")
                ->setEventTarget("select.$condition_target_name")
                ->replaceSearch($condition_key_name)
                ->replaceWord($condition_value_name)
                ->showConditionKey($this->showConditionKey)
                ->adminField(function ($data, $field) use ($condition_target_name, $condition_key_name, $condition_value_name) {
                    if (is_null($data)) {
                        return null;
                    }
                    $item = ConditionItemBase::getItem($this->custom_table, array_get($data, $condition_target_name));
                                
                    $label = exmtrans('condition.condition_value');
                    $item->setElement($field->getElementName(), $condition_value_name, $label);

                    return $item->getChangeField(array_get($data, $condition_key_name), $this->showConditionKey);
                });
            ;
        })->setTableWidth(10, 1);

        if($this->showConditionKey){
            $field->setTableColumnWidth(4, 4, 3, 1);
        }else{
            $field->setTableColumnWidth(6, 5, 1);
        }

        if (isset($this->callbackField)) {
            $func = $this->callbackField;
            $func($field);
        }
    }

    public function callbackField($callbackField)
    {
        $this->callbackField = $callbackField;

        return $this;
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
