<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Encore\Admin\Validator\HasOptionRule;

class Checkboxone extends Field
{
    protected $view = 'exment::form.field.checkboxone';

    protected static $css = [
        '/vendor/laravel-admin/AdminLTE/plugins/iCheck/all.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/AdminLTE/plugins/iCheck/icheck.min.js',
    ];

    protected $check_label = '';
    protected $check_value = '';
    protected $options = [];



    /**
     * Field constructor.
     *
     * @param       $column
     * @param array $arguments
     */
    public function __construct($column = '', $arguments = [])
    {
        parent::__construct($column, $arguments);

        $this->rules([new HasOptionRule($this)]);
    }

    /**
     * Set options.
     *
     * @param array|callable|string $option
     *
     * @return $this|mixed
     */
    public function option($option = [])
    {
        $this->options = $option;
        if (count($option) == 0) {
            return $this;
        }
        foreach ($option as $k => $v) {
            $this->check_value = $k;
            $this->check_label = $v;
            break;
        }
        return $this;
    }

    public function getOptions()
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $this->script = "$('{$this->getElementClassSelector()}').iCheck({checkboxClass:'icheckbox_minimal-blue'});";

        return parent::render()->with(['check_value' => $this->check_value, 'check_label' => $this->check_label]);
    }
}
