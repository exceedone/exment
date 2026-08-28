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
     * @param  \Closure|int|null  $total
     * @return \Illuminate\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $total = value($total) ?? $this->toBase()->getCountForPagination();

        $perPage = value($perPage, $total) ?: $this->model->getPerPage();

        $results = $total
            ? $this->executeQuery($page, $perPage, $columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    // @phpstan-ignore-next-line
    protected function executeQuery($page, $perPage, $columns)
    {
        if (isset($this->query->groups) && count($this->query->groups) > 0) {
            return $this->forPage($page, $perPage)->get($columns);
        }
        $_query = clone $this;
        $table = $this->model->getTable();
        $sql = $_query->select($table . '.id as sid')->forPage($page, $perPage)->toSql();
        // Use the bindings of the cloned query, not of $this.
        // select() above drops the "select" bindings (withCount / selectRaw / selectSub ...) from the clone,
        // so the bindings of $this would be shifted against the placeholders of $sql.
        $bindings = $_query->getBindings();
        if (count($bindings) > 0) {
            // @phpstan-ignore-next-line
            $query = preg_replace_callback('/\?/', function() use (&$bindings) {
                $binding = array_shift($bindings);
                return is_numeric($binding) ? $binding : "'" . addslashes($binding) . "'";
            }, $sql);
        } else {
            $query = $sql;
        }
        return $this->join(\DB::raw('(' . $query . ') s'), function ($join) use ($table) {
            $join->whereRaw($table . '.id in (s.sid)');
        })->get($columns);
    }
}
