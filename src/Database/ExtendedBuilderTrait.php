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
     * Multiple wherein querys
     *
     * @param  array                                          $columns
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $values
     * @param  bool  $zeroQueryIfEmpty if true and values is empty, set "1 = 0(always false)" query.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInMultiple(array $columns, $values, bool $zeroQueryIfEmpty = false)
    {
        if (boolval($zeroQueryIfEmpty) && empty($values)) {
            return $this->whereRaw('1 = 0');
        }

        $columns = $this->query->grammar->wrapWhereInMultiple($columns);
        list($bindStrings, $binds) = $this->query->grammar->bindValueWhereInMultiple($values);

        return $this->whereRaw(
            '('.implode(', ', $columns).') in ('.implode(', ', $bindStrings).')',
            $binds
        );
    }
}
