<?php

namespace Exceedone\Exment\Database\Eloquent;

use Closure;
use Exceedone\Exment\Database\ExtendedBuilderTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model
 * @extends Builder<TModelClass>
 */
class ExtendedBuilder extends Builder
{
    use ExtendedBuilderTrait;

    /**
     * Paginate the given query.
     *
     * @param  int|null|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $total = func_num_args() === 5 ? value(func_get_arg(4)) : $this->toBase()->getCountForPagination();

        $perPage = ($perPage instanceof Closure
            ? $perPage($total)
            : $perPage
        ) ?: $this->model->getPerPage();

        $results = $total
            ? $this->executeQuery($page, $perPage, $columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    protected function executeQuery($page, $perPage, $columns)
    {
        if (isset($this->query->groups) && count($this->query->groups) > 0) {
            return $this->forPage($page, $perPage)->get($columns);
        }
        $_query = clone $this;
        $table = $this->model->getTable();
        $sql = $_query->select($table . '.id as sid')->forPage($page, $perPage)->toSql();
        $bindings = $this->getBindings();
        if (count($bindings) > 0) {
            $query = vsprintf(str_replace('?', "'%s'", $sql), $bindings);
        } else {
            $query = $sql;
        }
        return $this->join(\DB::raw('(' . $query . ') s'), function ($join) use ($table) {
            $join->whereRaw($table . '.id in (s.sid)');
        })->get($columns);
    }
}
