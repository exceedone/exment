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
        $builder->orderBy($this->column, $this->direction);

        if(\DB::isSqlServer() && !$this->hasOrderById($builder)){
            $builder->orderBy('id', 'asc');
        }
    }


    /**
     * Whether builder has orderby and has id column
     *
     * @param Builder $builder
     * @return boolean
     */
    protected function hasOrderById(Builder $builder){
        if(empty($builder->getQuery()->orders)){
            return false;
        }

        foreach($builder->getQuery()->orders as $order){
            if(isMatchString(array_get($order, 'column'), 'id')){
                return true;
            }
        }

        return false;
    }
}
