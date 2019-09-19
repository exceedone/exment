<?php

namespace Exceedone\Exment\Grid\Filter;

use Illuminate\Support\Arr;
use Encore\Admin\Grid\Filter\Between;
use Exceedone\Exment\Enums\GroupCondition;

class BetweenDatetime extends Between
{
    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::filter.betweenDatetime';

    protected $group_condition;

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

        if (isset($this->group_condition)) {
            $column = \DB::raw(\DB::getQueryGrammar()
                ->getDateFormatString($this->group_condition, $this->column, false, false));
        } else {
            $column = $this->column;
        }

        if (!isset($value['start'])) {
            return $this->buildCondition($column, '<=', $value['end']);
        }

        if (!isset($value['end'])) {
            return $this->buildCondition($column, '>=', $value['start']);
        }

        $this->query = 'whereBetween';

        return $this->buildCondition($column, $this->value);
    }

    /**
     * Date filter.
     *
     * @return DateTime
     */
    public function date()
    {
        $this->group_condition = GroupCondition::YMD;
        return parent::date();
    }
}
