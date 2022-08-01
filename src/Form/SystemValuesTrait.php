<?php

namespace Exceedone\Exment\Form;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemColumn;

trait SystemValuesTrait
{
    public $withTrashed = false;

    public function renderSystemItem(?CustomValue $custom_value)
    {
        if (!isset($custom_value) || !isset($custom_value->id)) {
            return null;
        }

        if ($custom_value->custom_table->isOneRecord()) {
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
                SystemColumn::ID => ['getOld' => true],
                SystemColumn::CREATED_USER => ['getOld' => true],
                SystemColumn::UPDATED_USER => ['getOld' => true],
                SystemColumn::DELETED_USER => ['getOld' => true, 'nullHidden' => true],
                SystemColumn::CREATED_AT => ['getOld' => true],
                SystemColumn::UPDATED_AT => ['getOld' => true],
                SystemColumn::DELETED_AT => ['getOld' => true, 'nullHidden' => true],
            ]
        ];


        $workflows = $this->getValues($custom_value, $keys['workflows']);
        $bodies = $this->getValues($custom_value, $keys['bodies']);

        $positon = $custom_value->custom_table->getSystemValuesPosition();

        // return any content that can be rendered
        return view('exment::form.field.system_values', [
            'workflows' => $workflows,
            'bodies' => $bodies,
            'positon' => $positon,
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

            $value = null;
            if (boolval(array_get($options, 'getOld'))) {
                $value = old($param);
            }
            if (is_nullorempty($value)) {
                $value = $custom_value->{$param};
            }

            if (boolval(array_get($options, 'nullHidden')) && empty($value)) {
                continue;
            }

            $result[] = [
                'label' => exmtrans("common.$key"),
                'value' => $value
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
        if ($this->withTrashed) {
            $link .= '?trashed=1';
        }
        return [
            'label' => exmtrans('common.history'),
            'value' => '<a href="javascript:void(0);" data-widgetmodal_url="' . $link . '">' . trans('admin.show') . '</a>'
        ];
    }
}
