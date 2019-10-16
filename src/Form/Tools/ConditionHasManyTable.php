<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Validator\ChangeFieldRule;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\ChangeFieldItems\ChangeFieldItem;

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

    public function __construct(&$form, $options = [])
    {
        $this->form = $form;
        foreach($options as $key => $value){
            if(property_exists($this, $key)){
                $this->{$key} = $value;
            }
        }
    }

    public function render()
    {
        $this->form->hasManyTable($this->name, exmtrans("custom_view.custom_view_filters"), function ($form) {
            $form->select('condition_target', exmtrans("condition.condition_target"))->required()
                ->options($this->targetOptions)
                ->attribute([
                    'data-linkage' => $this->linkage,
                    'data-change_field_target' => 'condition_target',
                ]);

            $form->select('condition_key', exmtrans("condition.condition_key"))->required()
                ->options(function ($val, $select) {
                    if (!isset($val)) {
                        return [];
                    }

                    $data = $select->data();
                    $condition_target = array_get($data, 'condition_target');

                    $item = ChangeFieldItem::getItem($this->custom_table, $condition_target);
                    if(!isset($item)){
                        return null;
                    }
                    
                    return $item->getFilterCondition()->mapWithKeys(function($item){
                        return [$item['id'] => $item['text']];
                    });
                });

            $label = exmtrans('custom_view.view_filter_condition_value_text');
            $form->changeField('condition_value', $label)
                ->ajax($this->ajax)
                ->setEventTrigger('select.condition_key')
                ->setEventTarget('select.condition_target')
                ->adminField(function($data, $field){
                    if(is_null($data)){
                        return null;
                    }
                    $item = ChangeFieldItem::getItem($this->custom_table, array_get($data, 'condition_target'));
                                
                    $label = exmtrans('custom_form_priority.condition_value');
                    $item->setElement($field->getElementName(), 'condition_value', $label);

                    return $item->getChangeField(array_get($data, 'condition_key'));
                });
                //->rules([new ChangeFieldRule(null, $label, 'condition_target')])
                ;
        })->setTableColumnWidth(4, 4, 3, 1)
        ->setTableWidth(10, 1)
        ->setElementClass('work_conditions_filter')
        //->setRelatedValue($default)
        //->attribute(['data-filter' => json_encode(['key' => "enabled_{$index}", 'value' => '1'])])
        ->disableHeader();
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
