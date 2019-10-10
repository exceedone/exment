<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

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
                    // if null, return empty array.
                    if (!isset($val)) {
                        return [];
                    }

                    $data = $select->data();
                    $view_column_target = array_get($data, 'view_column_target');

                    // get column item
                    $column_item = CustomViewFilter::getColumnItem($view_column_target);

                    ///// get column_type
                    $column_type = $column_item->getViewFilterType();

                    // if null, return []
                    if (!isset($column_type)) {
                        return [];
                    }

                    // get target array
                    $options = array_get(ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS(), $column_type);
                    return collect($options)->mapWithKeys(function ($array) {
                        return [$array['id'] => exmtrans('custom_view.filter_condition_options.'.$array['name'])];
                    });

                    return [];
                });

            $label = exmtrans('custom_view.view_filter_condition_value_text');
            $form->changeField('condition_value', $label)
                ->ajax($this->ajax)
                ->setEventTrigger('select.condition_key')
                ->setEventTarget('select.condition_target')
                ->rules("changeFieldValue:$label");
        })->setTableColumnWidth(4, 4, 3, 1)
        ->setTableWidth(10, 2)
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
