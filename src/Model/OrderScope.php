<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrderScope implements Scope
{
    private $column;

    private $direction;

    public function __construct($column, $direction = 'asc')
    {
        $this->column = $column;
        $this->direction = $direction;
    }

    public function apply(Builder $builder, Model $model)
    {
        $table_name = $model->getTable();

        if (!$this->hasOrderById($builder, $this->column)) {
            $builder->orderBy("$table_name.". $this->column, $this->direction);
        }

        $primaryKey = $model->getKeyName();

        if (isset($primaryKey) && $this->column != $primaryKey && !$this->hasOrderById($builder, $primaryKey)) {
            $builder->orderBy("$table_name.$primaryKey", 'asc');
        }
    }


    /**
     * Whether builder has orderby and has id column
     *
     * @param Builder $builder
     * @return boolean
     */
    protected function hasOrderById(Builder $builder, string $key)
    {
        if (empty($builder->getQuery()->orders)) {
            return false;
        }

        foreach ($builder->getQuery()->orders as $order) {
            if (isMatchString(array_get($order, 'column'), $key)) {
                return true;
            }
        }

        return false;
    }
}
