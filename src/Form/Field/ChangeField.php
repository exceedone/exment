<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

/**
 * change field. If user select other input select, change input field
 */
class ChangeField extends Field
{
    protected $view = 'exment::form.field.changefield';

    /**
     * ajax url
     *
     * @var string
     */
    protected $ajax;

    /**
     * Selector name that call event dynamic field type
     *
     * @var string
     */
    protected $eventTriggerSelector;

    /**
     * Selector name that decide dynamic field type
     *
     * @var string
     */
    protected $eventTargetSelector;

    /**
     * decide admin field element Closure fucntioon
     *
     * @var Closure
     */
    protected  $adminField;

    protected function getElementClass()
    {
        if (preg_match('/(^[^\[\]]+)\[([^\[\]]+)\]\[([^\[\]]+)\]$/', $this->elementName, $array_result)) {
            array_shift($array_result);
            $array_result[1] = 'rowno-'.$array_result[1];
            return $array_result;
        }
        return [];
    }

    public function ajax($ajax){
        $this->ajax = $ajax;

        return $this;
    }

    /**
     * Set event trigger column for change event
     *
     * @param [type] $ajax
     * @return void
     */
    public function setEventTrigger($eventTriggerSelector){
        $this->eventTriggerSelector = $eventTriggerSelector;

        return $this;
    }

    /**
     * Set event target column for change event
     *
     * @param [type] $ajax
     * @return void
     */
    public function setEventTarget($eventTargetSelector){
        $this->eventTargetSelector = $eventTargetSelector;

        return $this;
    }

    protected function script(){
        $ajax = $this->ajax;
        $eventTriggerSelector = $this->eventTriggerSelector;
        $eventTargetSelector = $this->eventTargetSelector;

        $script = <<<EOT
            $('.has-many-table').off('change').on('change', '{$eventTriggerSelector}', function (ev) {
                var changeTd = $(ev.target).closest('tr').find('td:nth-child(3)>div>div');
                if(!hasValue($(ev.target).val())){
                    changeTd.html('');
                    return;
                }
                $.ajax({
                    url: '$ajax',
                    type: "GET",
                    data: {
                        'target': $(this).closest('tr').find('{$eventTargetSelector}').val(),
                        'cond_name': $(this).attr('name'),
                        'cond_key': $(this).val(),
                    },
                    context: this,
                    success: function (data) {
                        var json = JSON.parse(data);
                        $(this).closest('tr').find('td:nth-child(3)>div>div').html(json.html);
                        if (json.script) {
                            eval(json.script);
                        }
                    },
                });
            });
EOT;

        $this->script = $script;
    }

    public function render()
    {
        if(isset($this->adminField)){
            $func = $this->adminField;
            $field = $func($this->data, $this);
        }

        $this->script();

        if (isset($field)) {
            if (boolval(array_get($this->attributes, 'required'))) {
                if (!($field instanceof \Exceedone\Exment\Form\Field\SwitchField)) {
                    $field->required();
                }
            }
            
            $field->setWidth(12, 0)->setLabelClass(['hidden'])->setElementClass(['w-100'])->attribute(['style' => 'max-width:999999px']);
            $field->value($this->value());
            $field->setElementName($this->elementName)
                ->setErrorKey($this->getErrorKey())
                ->setElementClass($this->getElementClass());
            $field->forgetHelp();
            $view = $field->render();
            $this->script = $field->getScript();
            return $view;
        } else {
            return parent::render();
        }
    }

    public function adminField($adminField)
        :self {
        $this->adminField = $adminField;
        return $this;
    }

    public function prepareRecord($value, $record)
    {
        if(isset($this->adminField)){
            $func = $this->adminField;
            $field = $func($record, $this);
        }

        if(!isset($field)){
            return $value;
        }

        return $field->prepare($value);
    }
}
