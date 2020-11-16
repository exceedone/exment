<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Encore\Admin\Form\Field\MultipleSelect;

class PivotMultiSelect extends MultipleSelect
{
    protected $view = 'admin::form.multipleselect';

    /**
     * @var array
     */
    protected $pivot;

    public function __construct($column, $arguments = array())
    {
        parent::__construct($column, $arguments);
        $this->pivot = [];
    }

    /**
     * add pivot function
     *
     * @param array $pivot
     *
     * @return mixed
     */
    public function pivot($pivot)
    {
        $this->pivot = $pivot;
        return $this;
    }

    /**
     * Prepare for a field value before update or insert.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function prepare($value)
    {
        $value = parent::prepare($value);

        $newValue = [];
        foreach ($value as $v) {
            $newValue[$v] = $this->pivot;
        }

        return $newValue;
    }
}
