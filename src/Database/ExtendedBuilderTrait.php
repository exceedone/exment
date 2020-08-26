<?php

namespace Exceedone\Exment\Database;

/**
 *
 * @property mixed $query
 */
trait ExtendedBuilderTrait
{
    /**
     * Execute query "where" or "whereIn". If args is array, call whereIn
     *
     * @param  string|array|\Closure  $column
     * @param  mixed   $operator
     * @param  mixed   $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereOrIn($column, $operator = null, $value = null, $boolean = 'and')
    {
        // if arg is array or list, execute whereIn
        $checkArray = (func_num_args() == 3 ? $value : $operator);
        if (is_list($checkArray)) {
            if (func_num_args() == 3 && $operator == '<>') {
                return $this->whereNotIn($column, toArray($checkArray));
            }
            return $this->whereIn($column, toArray($checkArray));
        }

        return $this->where($column, $operator, $value, $boolean);
    }


    /**
     * Multiple wherein querys.
     * *NOW Only support columns is 2 column. *
     *
     * @param  array                                          $columns
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     * @param  bool  $zeroQueryIfEmpty if true and values is empty, set "1 = 0(always false)" query.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInMultiple(array $columns, $values, bool $zeroQueryIfEmpty = false)
    {
        if(count($columns) !== 2){
            throw new \Exception('Now whereInMultiple is only support 2 columns.');
        }
        if (boolval($zeroQueryIfEmpty) && empty($values)) {
            return $this->whereRaw('1 = 0');
        }

        // is suport where in multiple ----------------------------------------------------
        if($this->query->grammar->isSupportWhereInMultiple()){
            $columns = $this->query->grammar->wrapWhereInMultiple($columns);
            list($bindStrings, $binds) = $this->query->grammar->bindValueWhereInMultiple($values);
    
            return $this->whereRaw(
                '('.implode(', ', $columns).') in ('.implode(', ', $bindStrings).')',
                $binds
            );
        }

        // if not suport where in multiple, first getting target id, and add query. ----------------------------------------------------
        $tableName = $this->model->getTable();
        $subquery = \DB::table($tableName);

        // group "$values" index.
        $groups = collect($values)->groupBy(function ($item, $key) {
            return $item[0];
        });

        $ids = $subquery->where(function($query) use($groups, $columns){
            foreach($groups as $key => $group){
                $query->orWhere(function($query) use($key, $group, $columns){
                    $values = collect($group)->map(function($g){
                        return $g[1];
                    })->toArray();
                    $query->where($columns[0], $key)
                        ->whereIn($columns[1], $values);
                });
            }
        })->select(['id'])->get()->pluck('id');

        // set id filter
        return $this->whereIn('id', $ids);
    }
    

    /**
     * wherein string.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param  string                                         $column
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInArrayString($column, $values)
    {
        if (is_null($values)) {
            return $this->whereRaw('1 = 0');
        }

        $tableName = $this->model->getTable();
        $this->query->grammar->whereInArrayString($this, $tableName, $column, $values);

        return $this;
    }
    

    /**
     * Where between, but call as (start) <= column and column <= (end). for performance
     * @param  string                                          $column
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereBetweenQuery($column, array $values)
    {
        return $this->_between($column, $values, '>=', '<=');
    }

    /**
     * Where between, but call as (start) <= column and column < (end)
     * @param  string                                          $column
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereBetweenLt($column, array $values)
    {
        return $this->_between($column, $values, '>=', '<');
    }


    protected function _between($column, array $values, $startMark, $endMark){
        $values = array_values($values);

        if (count($values) < 2) {
            return $this->whereRaw('1 = 0');
        }

        $this->query->where($column, $startMark, $values[0]);
        $this->query->where($column, $endMark, $values[1]);

        return $this;
    }
}
