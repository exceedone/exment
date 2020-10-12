<?php

namespace Exceedone\Exment\Grid\Filter;

use Illuminate\Support\Arr;
use Encore\Admin\Grid\Filter\Between;

class BetweenDatetime extends Between
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

        $value = $this->convertValue($value);

        $column = $this->column;
        
        if (!isset($value['start'])) {
            return $this->buildCondition($column, '<', $value['end']);
        }

        if (!isset($value['end'])) {
            return $this->buildCondition($column, '>=', $value['start']);
        }

        $this->query = 'whereBetweenLt';

        return $this->buildCondition($column, $value);
    }

    protected function convertValue($value)
    {
        if (isset($value['end'])) {
            $end = \Carbon\Carbon::parse($value['end'])->addDay(1);
            $value['end'] = $end->format('Y-m-d');
        }

        return $value;
    }
}
