<?php

namespace Exceedone\Exment\Grid\Filter;

use Encore\Admin\Grid\Filter\Where as BaseWhere;
use Illuminate\Support\Arr;

class Where extends BaseWhere
{
    /**
     * where null query closure.
     *
     * @var \Closure|null
     */
    protected $whereNull;

    /**
     * Set where null query.
     *
     * @param \Closure $whereNull
     * @return $this
     */
    public function whereNull($whereNull)
    {
        $this->whereNull = $whereNull;
        return $this;
    }

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
        return $this->buildCondition(function ($query) use ($func, $value) {
            $func($query, $value, $this);
        });
    }

    /**
     * Get query where null condition from filter.
     *
     * @return array|array[]|mixed|null
     */
    public function whereNullCondition()
    {
        if (!$this->whereNull) {
            return parent::whereNullCondition();
        }

        $this->isnull = true;
        $whereNull = $this->whereNull;
        return $this->buildCondition(function ($query) use ($whereNull) {
            $whereNull($query, $this);
        });
    }

    /**
     * Get query condition from filter.
     *
     * @param array $inputs
     *
     * @return array|mixed|null
     */
    public function getCondition($inputs)
    {
        $isnull = Arr::get($inputs, 'isnull-'. $this->column);

        if (isset($isnull)) {
            return $this->whereNullCondition();
        }

        return $this->condition($inputs);
    }
}
