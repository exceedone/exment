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
        try {
            $_query = clone $this->query;
            $sql = $_query->select('id as sid')->forPage($page, $perPage)->toSql();
            $bindings = $this->getBindings();
            $query = vsprintf(str_replace('?', "'%s'", $sql), $bindings);
            return $this->join(\DB::raw('(' . $query . ') s'), function ($join) {
                $join->whereRaw('id in (s.sid)');
            })->get($columns);
        } catch (\Throwable $th) {
            return $this->forPage($page, $perPage)->get($columns);
        }
    }
}
