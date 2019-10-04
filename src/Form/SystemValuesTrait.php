<?php

namespace Exceedone\Exment\Form;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemColumn;

trait SystemValuesTrait
{
    public function renderSystemItem(?CustomValue $custom_value)
    {
        if(!isset($custom_value)){
            return null;
        }
        
        // get label and value
        $keys = [
            SystemColumn::ID => [],
            SystemColumn::WORKFLOW_STATUS => [],
            SystemColumn::CREATED_USER => [],
            SystemColumn::UPDATED_USER => [],
            SystemColumn::CREATED_AT => [],
            SystemColumn::UPDATED_AT => [],
        ];

        //set items
        $items = [];
        foreach($keys as $key => $options){
            $option = SystemColumn::getEnum($key)->option();
            $param = array_has($option, 'tagname') ? array_get($option, 'tagname') : array_get($option, 'name');
            
            $items[] = [
                'label' => exmtrans("common.$key"),
                'value' => $custom_value->{$param}
            ];
        }

        // return any content that can be rendered
        return view('exment::form.field.system_values', [
            'items' => $items,
        ]);
    }
}
