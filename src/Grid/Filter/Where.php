<?php

namespace Exceedone\Exment\Grid\Filter;

use Encore\Admin\Grid\Filter\Where as BaseWhere;
use Illuminate\Support\Arr;

class Where extends BaseWhere
{
    /**
     * Get condition of this filter.
     *
     * @param array $inputs
     *
     * @return array|mixed|void
     */
    public function condition($inputs)
    {
        $value = Arr::get($inputs, $this->column ?: static::getQueryHash($this->where, $this->label));

        if (is_null($value)) {
            return;
        }

        $this->input = $this->value = $value;

        $func = $this->where;
        return $this->buildCondition(function($query) use($func, $value){
            $func($query, $value, $this);
        });
    }
}
