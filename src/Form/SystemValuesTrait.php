<?php

namespace Exceedone\Exment\Form;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemColumn;

trait SystemValuesTrait
{
    public function renderSystemItem(?CustomValue $custom_value)
    {
        if (!isset($custom_value) || !isset($custom_value->id)) {
            return null;
        }
        
        // get label and value
        $keys = [
            'workflows' => [
                SystemColumn::WORKFLOW_STATUS => ['nullHidden' => true],
                SystemColumn::WORKFLOW_WORK_USERS => ['nullHidden' => true],
                'show_workflow_histories' => ['nullHidden' => true, 'function' => 'showWorkflowHistories'],
            ],
            'bodies' => [
                SystemColumn::ID => [],
                SystemColumn::CREATED_USER => [],
                SystemColumn::UPDATED_USER => [],
                SystemColumn::CREATED_AT => [],
                SystemColumn::UPDATED_AT => [],
            ]
        ];

        
        $workflows = $this->getValues($custom_value, $keys['workflows']);
        $bodies = $this->getValues($custom_value, $keys['bodies']);
        
        // return any content that can be rendered
        return view('exment::form.field.system_values', [
            'workflows' => $workflows,
            'bodies' => $bodies,
        ]);
    }

    protected function getValues($custom_value, $items)
    {
        $result = [];
        foreach ($items as $key => $options) {
            if (array_has($options, 'function')) {
                $func = array_get($options, 'function');
                $funcResult = $this->{$func}($custom_value);

                if (!isset($funcResult)) {
                    continue;
                }

                $result[] = $funcResult;
                continue;
            }
        
            $option = SystemColumn::getEnum($key)->option();
            $param = array_get($option, 'avatarname') ?: array_get($option, 'tagname') ?: array_get($option, 'name');
            $value = $custom_value->{$param};

            if (boolval(array_get($options, 'nullHidden')) && empty($value)) {
                continue;
            }

            $result[] = [
                'label' => exmtrans("common.$key"),
                'value' => $custom_value->{$param}
            ];
        }

        return $result;
    }
    
    protected function showWorkflowHistories($custom_value)
    {
        $workflowHistories = $custom_value->getWorkflowHistories();
        if (count($workflowHistories) == 0) {
            return null;
        }

        $link = url_join($custom_value->getUrl(), 'workflowHistoryModal');
        return [
            'label' => exmtrans('common.history'),
            'value' => '<a href="javascript:void(0);" data-widgetmodal_url="' . $link . '">' . trans('admin.show') . '</a>'
        ];
    }
}
