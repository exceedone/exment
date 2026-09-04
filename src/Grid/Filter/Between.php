<?php

namespace Exceedone\Exment\Grid\Filter;

use Illuminate\Support\Arr;

class Between extends \Encore\Admin\Grid\Filter\Between
{
    use BetweenTrait;

    /**
     * Where constructor.
     *
     * @param \Closure $query
     * @param string   $label
     * @param string|null   $column
     */
    public function __construct(\Closure $query, $label, $column = null)
    {
        $this->construct($query, $label, $column);
    }

    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::filter.between';

    /**
     * Get condition of this filter.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>|mixed|null
     */
    public function condition($inputs)
    {
        if (!Arr::has($inputs, $this->column)) {
            return;
        }

        $this->value = Arr::get($inputs, $this->column);

        $value = array_filter($this->value, function ($val) {
            return $val !== '';
        });

        if (empty($value)) {
            return;
        }

        $value = $this->convertValue($value);

        $func = $this->where;
        return $this->buildCondition(function ($query) use ($func, $value) {
            $func($query, $value, $this);
        });
    }

    /**
     * Convert value.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function convertValue($value)
    {
        return $value;
    }
}
