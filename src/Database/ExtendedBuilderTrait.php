<?php

namespace Exceedone\Exment\Database;

use Carbon\Carbon;

/**
 *
 * @property mixed $query
 */
trait ExtendedBuilderTrait
{
    /**
     * Update a removing json key.
     *
     * @param  string  $targetColumn
     * @param  string  $removingJsonKey
     * @return int
     */
    public function updateRemovingJsonKey(string $key)
    {
        $sql = $this->query->grammar->compileUpdateRemovingJsonKey($this->query, $key);

        return $this->query->connection->statement($sql);
    }


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
     * Execute query "orWhere" or "orWhereIn". If args is array, call whereIn.
     *
     * @param  string|array|\Closure  $column
     * @param  mixed   $operator
     * @param  mixed   $value
     * @param  string  $boolean
     * @return $this
     */
    public function orWhereOrIn($column, $operator = null, $value = null, $boolean = 'and')
    {
        // if arg is array or list, execute whereIn
        $checkArray = (func_num_args() == 3 ? $value : $operator);
        if (is_list($checkArray)) {
            if (func_num_args() == 3 && $operator == '<>') {
                return $this->orWhereNotIn($column, toArray($checkArray));
            }
            return $this->orWhereIn($column, toArray($checkArray));
        }

        return $this->orWhere($column, $operator, $value, $boolean);
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
        if (count($columns) !== 2) {
            throw new \Exception('Now whereInMultiple is only support 2 columns.');
        }
        if (boolval($zeroQueryIfEmpty) && empty($values)) {
            return $this->whereRaw('1 = 0');
        }

        // is suport where in multiple ----------------------------------------------------
        if ($this->query->grammar->isSupportWhereInMultiple()) {
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

        $ids = $subquery->where(function ($query) use ($groups, $columns) {
            foreach ($groups as $key => $group) {
                $query->orWhere(function ($query) use ($key, $group, $columns) {
                    $values = collect($group)->map(function ($g) {
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
        return $this->_whereInArrayString($column, $values, false, false);
    }
    
    /**
     * or wherein string.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param  string                                         $column
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function orWhereInArrayString($column, $values)
    {
        return $this->_whereInArrayString($column, $values, true, false);
    }
    
    /**
     * where not in string.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param  string                                         $column
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereNotInArrayString($column, $values)
    {
        return $this->_whereInArrayString($column, $values, true, true);
    }
    
    /**
     * or where not in string.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param  string                                         $column
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function orWhereNotInArrayString($column, $values)
    {
        return $this->_whereInArrayString($column, $values, true, false);
    }
    

    protected function _whereInArrayString($column, $values, bool $isOr = false, bool $isNot = false)
    {
        if (is_null($values)) {
            return $this->whereRaw('1 = 0');
        }

        $tableName = $this->model->getTable();
        $this->query->grammar->whereInArrayString($this, $tableName, $column, $values, $isOr, $isNot);

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


    protected function _between($column, array $values, $startMark, $endMark, bool $isOr = false)
    {
        $values = array_values($values);

        if (count($values) < 2) {
            return $this->whereRaw('1 = 0');
        }

        if ($isOr) {
            $this->query->orWhere(function ($query) use ($column, $startMark, $endMark, $values) {
                $this->query->where($column, $startMark, $values[0]);
                $this->query->where($column, $endMark, $values[1]);
            });
        } else {
            $this->query->where($column, $startMark, $values[0]);
            $this->query->where($column, $endMark, $values[1]);
        }

        return $this;
    }


    // for date ----------------------------------------------------
    

    /**
     * Where date for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereDateExment(string $column, $value, bool $isDatetime)
    {
        return $this->_whereDate($column, $value, $isDatetime, false);
    }
    
    /**
     * Where date for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function orWhereDateExment(string $column, $value, bool $isDatetime)
    {
        return $this->_whereDate($column, $value, $isDatetime, true);
    }

    /**
     * Where date mark(<=, >=, etc..)for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereDateMarkExment(string $column, $value, $mark, bool $isDatetime)
    {
        return $this->_whereDateMark($column, $value, $mark, $isDatetime, false);
    }
    
    /**
     * or Where date mark(<=, >=, etc..)for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function orWhereDateMarkExment(string $column, $value, $mark, bool $isDatetime)
    {
        return $this->_whereDateMark($column, $value, $mark, $isDatetime, true);
    }
    

    /**
     * Where month for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereYearMonthExment(string $column, $value, bool $isDatetime)
    {
        return $this->_whereYearMonth($column, $value, $isDatetime, false);
    }
    

    /**
     * Where Month for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function orWhereYearMonthExment(string $column, $value, bool $isDatetime)
    {
        return $this->_whereYearMonth($column, $value, $isDatetime, true);
    }


    /**
     * Where month for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereYearExment(string $column, $value, bool $isDatetime)
    {
        return $this->_whereYear($column, $value, $isDatetime, false);
    }

    /**
     * or Where month for performance
     * @param  string $column
     * @param  string|Carbon|null $value
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function orWhereYearExment(string $column, $value, bool $isDatetime)
    {
        return $this->_whereYear($column, $value, $isDatetime, true);
    }
    

    protected function _whereDate(string $column, $value, bool $isDatetime, bool $isOr = false)
    {
        if (is_null($value)) {
            return $this->whereRaw('1 = 0');
        }

        if (is_string($value)) {
            $value = Carbon::parse($value);
        }

        return $this->_setWhereDate($column, [
            'date' => [$value, $value],
            'datetime' => [$value, $value->copy()->addDay(1)],
        ], $isDatetime, $isOr);
    }

    protected function _whereYear(string $column, $value, bool $isDatetime, bool $isOr = false)
    {
        if (is_null($value)) {
            return $this->whereRaw('1 = 0');
        }

        if (is_string($value)) {
            $value = Carbon::parse($value);
        }

        return $this->_setWhereDate($column, [
            'date' => [Carbon::create($value->year, 1, 1), Carbon::create($value->year, 12, 31)],
            'datetime' => [Carbon::create($value->year, 1, 1), Carbon::create($value->year + 1, 1, 1)],
        ], $isDatetime, $isOr);
    }
    

    protected function _whereYearMonth(string $column, $value, bool $isDatetime, bool $isOr = false)
    {
        if (is_null($value)) {
            return $this->whereRaw('1 = 0');
        }

        if (is_string($value)) {
            $value = Carbon::parse($value);
        }

        return $this->_setWhereDate($column, [
            'date' => [Carbon::create($value->year, $value->month, 1), Carbon::create($value->year, $value->month + 1, 1)->addDay(-1)],
            'datetime' => [Carbon::create($value->year, $value->month, 1), Carbon::create($value->year, $value->month + 1, 1)],
        ], $isDatetime, $isOr);
    }
    

    protected function _setWhereDate(string $column, $values, bool $isDatetime, bool $isOr = false)
    {
        if ($isDatetime) {
            $start = $values['datetime'][0];
            $end = $values['datetime'][1];
            $values = [
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ];
            return $this->_between($column, $values, '>=', '<', $isOr);
        }
        
        $start = $values['date'][0];
        $end = $values['date'][1];
        $values = [
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        ];
        return $this->_between($column, $values, '>=', '<=', $isOr);
    }

    
    protected function _whereDateMark(string $column, $value, $mark, bool $isDatetime, bool $isOr = false)
    {
        if (is_null($value)) {
            return $this->whereRaw('1 = 0');
        }

        if (is_string($value)) {
            $value = Carbon::parse($value);
        }

        if ($isDatetime) {
            $date = (in_array($mark, ['<', '<=']) ? $value->copy()->addDay(1) : $value);
            return $this->where($column, $mark, $date->format('Y-m-d'));
        }

        return $this->where($column, $mark, $value->format('Y-m-d'));
    }
}
