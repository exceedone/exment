<?php

namespace Exceedone\Exment\Form\Field\Workflow;

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

    /**
     * @param $column
     * @param $arguments
     * @phpstan-ignore-next-line
     */
    public function __construct($column = '', $arguments = [])
    {
        $this->column['action_name'] = 'action_name';
        $this->column['status_from'] = 'status_from';

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
        $classItem = collect($class)->map(function ($c) {
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

        if (empty($this->script)) {
            $this->script = "$('.workflow_actions_status_from').select2($configs);";
        }

        /** @phpstan-ignore-next-line Instanceof between array and Closure will always evaluate to false. */
        if ($this->options instanceof \Closure) {
            /** @phpstan-ignore-next-line Left side of && is always true. and Right side of && is always true. */
            if ($this->form && $this->form->model()) {
                $this->options = $this->options->bindTo($this->form->model());
            }

            $this->options(call_user_func($this->options, $this->value, $this, $this->form->model()));
        }

        $this->options = array_filter($this->options, 'strlen');

        // Whether is show id
        $action_id = null;
        if (boolval(config('exment.show_workflow_id', false))) {
            $action_id = array_get($this->data, 'id');
        }

        return parent::render()->with([
            'options' => $this->options,
            'action_id' => $action_id,
        ]);
    }
}
