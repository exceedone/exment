<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomTable;

trait UserOrganizationItemTrait
{
    protected function getChangeFieldUserOrg(CustomTable $target_table, $key, $show_condition_key = true)
    {
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);
        $className = "class_" . make_uuid();
        $field->setElementClass($className);

        $selectOption = [
            'display_table' => $this->custom_table
        ];

        // set buttons
        $buttons = [];
        if (!boolval(config('exment.select_table_modal_search_disabled', false))) {
            $buttons[] = [
                'label' => trans('admin.search'),
                'btn_class' => 'btn-info',
                'icon' => 'fa-search',
                'attributes' => [
                    'data-widgetmodal_url' => admin_urls_query('data', $target_table->table_name, ['modalframe' => 1]),
                    'data-widgetmodal_getdata_fieldsgroup' => json_encode(['selected_items' => $className]),
                    'data-widgetmodal_expand' => json_encode(['display_table_id' => $this->custom_table->id, 'target_column_class' => $className, 'target_column_multiple' => true, ]),
                ],
            ];
        }

        return $target_table->setSelectTableField($field, [
            'label' => $this->label, // almost use 'data-add-select2'.
            'buttons' => $buttons, // append buttons for select field searching etc.
            'select_option' => $selectOption, // select option's option
        ]);
    }
}
