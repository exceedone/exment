<?php

namespace Exceedone\Exment\Form\Field\WorkFlow;

use Encore\Admin\Form\Field\Select;

class StatusSelects extends Select
{
    protected $view = 'exment::workflow.status-selects';

    /**
     * Column name.
     *
     * @var array
     */
    protected $column = [];

    public function __construct($column = '', $arguments = [])
    {
        $this->column['start'] = 'status_start';
        $this->column['end'] = 'status_end';

        $this->label = $this->formatLabel($arguments);
        $this->id = $this->formatId($this->column);

        // $this->options(['format' => $this->format]);
    }

    /**
     * Set form element class.
     *
     * @param string|array $class
     *
     * @return $this
     */
    public function setElementClass($class)
    {
        $classItem = collect($class)->map(function($c){
            return is_array($c) ? implode("_", $c) : $c;
        })->toArray();
        $this->elementClass = array_merge($this->elementClass, $classItem);

        $this->elementClass = array_unique($this->elementClass);

        return $this;
    }
    
    public function render()
    {
        $configs = array_merge([
            'allowClear'  => true,
            'language' =>  \App::getLocale(),
            'placeholder' => [
                'id'   => '',
                'text' => $this->label,
            ],
        ], $this->config);

        $configs = json_encode($configs);

        // get classname
        $classname = implode(',', array_values($this->getElementClassSelector()));

        if (empty($this->script)) {
            $this->script = "$(\"{$classname}\").select2($configs);";
        }

        if ($this->options instanceof \Closure) {
            if ($this->form) {
                $this->options = $this->options->bindTo($this->form->model());
            }

            $this->options(call_user_func($this->options, $this->value, $this));
        }

        $this->options = array_filter($this->options, 'strlen');

        return parent::render()->with([
            'options' => $this->options
        ]);
    }
}
