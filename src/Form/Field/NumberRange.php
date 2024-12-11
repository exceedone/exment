<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class NumberRange extends Field
{
    protected $view = 'exment::form.field.numberrange';

    // protected $rules = [
    //     'start' => ['nullable', 'numeric'],
    //     'end' => ['nullable', 'numeric'],
    // ];

    protected $rules = ['nullable', 'numeric'];

    /**
     * Column name.
     *
     * @var array
     */
    protected $column = [];

    public function __construct($column, $arguments)
    {
        $this->column['start'] = $column;
        $this->column['end'] = $arguments[0];

        array_shift($arguments);
        $this->label = $this->formatLabel($arguments);
        $this->id = $this->formatId($this->column);
    }

    /**
     * @param mixed|null $value
     * @return $this|mixed
     */
    public function value($value = null)
    {
        if (is_null($value)) {
            if (is_null($this->value['start']) && is_null($this->value['end'])) {
                return $this->getDefault();
            }

            return $this->value;
        }

        $this->value = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($value)
    {
        if ($value === '') {
            $value = null;
        }

        return $value;
    }

    public function render()
    {
        return parent::render();
    }
}
