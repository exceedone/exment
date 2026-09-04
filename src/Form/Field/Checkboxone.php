<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Encore\Admin\Validator\HasOptionRule;

class Checkboxone extends Field
{
    protected $view = 'exment::form.field.checkboxone';

    // @phpstan-ignore-next-line
    protected static $css = [
        '/vendor/laravel-admin/AdminLTE/plugins/iCheck/all.css',
    ];

    // @phpstan-ignore-next-line
    protected static $js = [
        '/vendor/laravel-admin/AdminLTE/plugins/iCheck/icheck.min.js',
    ];

    // @phpstan-ignore-next-line
    protected $check_label = '';
    // @phpstan-ignore-next-line
    protected $check_value = '';
    // @phpstan-ignore-next-line
    protected $options = [];



    /**
     * Field constructor.
     *
     * @param       $column
     * @param array $arguments
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function option($option = [])
    {
        // @phpstan-ignore-next-line
        $this->options = $option;
        // @phpstan-ignore-next-line
        if (count($option) == 0) {
            return $this;
        }
        // @phpstan-ignore-next-line
        foreach ($option as $k => $v) {
            $this->check_value = $k;
            $this->check_label = $v;
            break;
        }
        return $this;
    }

    // @phpstan-ignore-next-line
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

        // @phpstan-ignore-next-line
        return parent::render()->with(['check_value' => $this->check_value, 'check_label' => $this->check_label]);
    }
}
