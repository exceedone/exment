<?php

namespace Exceedone\Exment\Form\Field\WorkFlow;

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

    public function __construct($column = '', $arguments = [])
    {
        $this->column['comment'] = 'comment';
        $this->column['flowNextType'] = 'flowNextType';
        $this->column['flowNextCount'] = 'flowNextCount';

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
            $('.workflow_actions_comment').select2($configs);
            $('.workflow_actions_flowNextType').iCheck({radioClass:'iradio_minimal-blue'});
EOT;
        }

        $options = WorkflowCommentType::transArray('workflow.comment_options');

        $options = array_filter($options, 'strlen');

        return parent::render()->with([
            'optionsComment' => $options,
            'defaultComment' => WorkflowCommentType::NULLABLE,
        ]);
    }
}
