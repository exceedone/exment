<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\FilterKind;

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
     * @var string
     */
    protected $replaceSearch = 'condition_key';

    /**
     * @var string
     */
    protected $replaceWord = 'condition_value';

    /**
     * @var string
     */
    protected $hasManyTableClass = 'has-many-table';

    /**
     *
     * @var bool
     */
    protected $showConditionKey = true;

    /**
     * decide admin field element Closure fucntioon
     *
     * @var \Closure|null
     */
    protected $adminField;

    /**
     * filter kind (view, workflow, form)
     *
     * @var bool|null
     */
    protected $filterKind = null;

    /**
     * allow null
     *
     * @var bool
     */
    protected $allowNull = false;

    // @phpstan-ignore-next-line
    protected static $scripts = [];

    // @phpstan-ignore-next-line
    protected function getElementClass()
    {
        if (preg_match('/(^[^\[\]]+)\[([^\[\]]+)\]\[([^\[\]]+)\]$/', $this->elementName, $array_result)) {
            array_shift($array_result);
            $array_result[1] = 'rowno-'.$array_result[1];
            return $array_result;
        }
        return [];
    }

    // @phpstan-ignore-next-line
    public function filterKind($filterKind = null)
    {
        if (isset($filterKind)) {
            $this->filterKind = $filterKind;
        }

        return $this;
    }

    // @phpstan-ignore-next-line
    public function ajax($ajax)
    {
        $this->ajax = $ajax;

        return $this;
    }

    // @phpstan-ignore-next-line
    public function allowNull($allowNull = true)
    {
        $this->allowNull = $allowNull;

        return $this;
    }

    /**
     * Set event trigger column for change event
     *
     * @param string $eventTriggerSelector
     * @return $this
     */
    public function setEventTrigger($eventTriggerSelector)
    {
        $this->eventTriggerSelector = $eventTriggerSelector;

        return $this;
    }

    /**
     * Set event target column for change event
     *
     * @param string $eventTargetSelector
     * @return $this
     */
    public function setEventTarget($eventTargetSelector)
    {
        $this->eventTargetSelector = $eventTargetSelector;

        return $this;
    }

    /**
     * Show Condition Key
     *
     * @param bool $showConditionKey
     * @return $this
     */
    public function showConditionKey($showConditionKey)
    {
        $this->showConditionKey = $showConditionKey;

        return $this;
    }

    /**
     * hasManyTableClass
     *
     * @param string $hasManyTableClass
     * @return $this
     */
    public function hasManyTableClass($hasManyTableClass)
    {
        $this->hasManyTableClass = $hasManyTableClass;

        return $this;
    }

    /**
     */
    // @phpstan-ignore-next-line
    public function replaceSearch($replaceSearch)
    {
        $this->replaceSearch = $replaceSearch;

        return $this;
    }

    /**
     */
    // @phpstan-ignore-next-line
    public function replaceWord($replaceWord)
    {
        $this->replaceWord = $replaceWord;

        return $this;
    }

    // @phpstan-ignore-next-line
    protected function script()
    {
        $ajax = $this->ajax;
        $filterKind = $this->filterKind ?? FilterKind::VIEW;
        $eventTriggerSelector = $this->eventTriggerSelector;
        $eventTargetSelector = $this->eventTargetSelector;
        $showConditionKey = $this->showConditionKey;
        $hasManyTableClass = $this->hasManyTableClass;
        $replaceSearch = $this->replaceSearch;
        $replaceWord = $this->replaceWord;

        $script = <<<EOT
            Exment.ChangeFieldEvent.ChangeFieldEvent('$ajax', '$eventTriggerSelector', '$eventTargetSelector', '$replaceSearch', '$replaceWord', '$showConditionKey', '$hasManyTableClass');
EOT;

        static::$scripts[] = $script;
    }

    public function getScript()
    {
        $script = collect(static::$scripts)->filter()->unique()->implode("");
        //static::$scripts = [];
        //\Admin::script($script);
        return $script;
    }

    public function render()
    {
        if (isset($this->adminField)) {
            $func = $this->adminField;
            $field = $func($this->data, $this);
        }

        $this->script();

        if (isset($field)) {
            if (!($field instanceof \Exceedone\Exment\Form\Field\SwitchField) &&
                !($field instanceof \Exceedone\Exment\Form\Field\Checkboxone) &&
                !$this->allowNull) {
                // required if visible
                $field->required();
            }

            $field->setWidth(12, 0)->setLabelClass(['hidden'])->setElementClass(['w-100'])->attribute(['style' => 'max-width:999999px']);
            $field->value($this->value());
            $field->setElementName($this->elementName)
                ->setErrorKey($this->getErrorKey())
                ->setElementClass($this->getElementClass())
                ->setFieldClass('changefield-div');
            $field->forgetHelp();
            $view = $field->render();
            static::$scripts[] = $field->getScript();
            return $view;
        } else {
            $this->script = $this->getScript();
            return parent::render();
        }
    }

    // @phpstan-ignore-next-line
    public function adminField($adminField): self
    {
        $this->adminField = $adminField;
        return $this;
    }

    // @phpstan-ignore-next-line
    public function prepareRecord($value, $record)
    {
        if (isset($this->adminField)) {
            $func = $this->adminField;
            $field = $func($record, $this);
        }

        if (!isset($field)) {
            return $value;
        }

        return $field->prepare($value);
    }
}
