<?php

namespace Exceedone\Exment\Form\Field\Workflow;

use Encore\Admin\Form\Field\Select;
use Exceedone\Exment\Enums\WorkflowCommentType;

class Options extends Select
{
    protected $view = 'exment::workflow.options';

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
        $this->column['comment_type'] = 'comment_type';
        $this->column['flow_next_type'] = 'flow_next_type';
        $this->column['flow_next_count'] = 'flow_next_count';
        $this->column['ignore_work'] = 'ignore_work';

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

    public function prepare($value)
    {
        if (!array_has($value, 'ignore_work')) {
            $value['ignore_work'] = 0;
        } else {
            $value['ignore_work'] = 1;
        }

        return $value;
    }

    public function render()
    {
        $configs = array_merge([
            'allowClear'  => false,
            'language' =>  \App::getLocale(),
            'placeholder' => [
                'id'   => '',
                'text' => $this->label,
            ],
        ], $this->config);

        $configs = json_encode($configs);

        // get classname

        if (empty($this->script)) {
            $this->script = <<<EOT
            $('.workflow_actions_comment_type').select2($configs);
            $('.workflow_actions_flow_next_type').iCheck({radioClass:'iradio_minimal-blue'});
            $('.workflow_actions_ignore_work').iCheck({checkboxClass:'icheckbox_minimal-blue'});
EOT;
        }

        $options = WorkflowCommentType::transArray('workflow.comment_options');

        $options = array_filter($options, 'strlen');

        return parent::render()->with([
            'optionsCommentType' => $options,
            'defaultCommentType' => WorkflowCommentType::NULLABLE,
            'index' => $this->getIndex(),
        ]);
    }
}
