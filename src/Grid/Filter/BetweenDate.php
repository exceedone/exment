<?php

namespace Exceedone\Exment\Grid\Filter;

use Illuminate\Support\Arr;
use Encore\Admin\Grid\Filter\Between;

class BetweenDate extends Between
{
    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::filter.betweenDatetime';
    
    /**
     * Get condition of this filter.
     *
     * @param array $inputs
     *
     * @return array|mixed|void
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

        $column = $this->column;

        if (!isset($value['start'])) {
            return $this->buildCondition($column, '<=', $value['end']);
        }

        if (!isset($value['end'])) {
            return $this->buildCondition($column, '>=', $value['start']);
        }

        $this->query = 'whereBetweenQuery';

        return $this->buildCondition($column, $this->value);
    }
}
