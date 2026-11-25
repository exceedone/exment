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

    /**
     * A page without counting all (no total): the same records as paginate() - read the same
     * way - and one more, to know whether there is a next page (ISS02885).
     *
     * @param  int|null  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Pagination\Paginator
     */
    // @phpstan-ignore-next-line
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        // a number, as paginate() gives it (the count of the request is text)
        $perPage = intval($perPage ?: $this->model->getPerPage());

        $results = $this->executeQuery($page, $perPage, $columns, $perPage + 1);

        // @phpstan-ignore-next-line
        return $this->simplePaginator($results, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * @param int|null $limit how many from the first of the page (default: the page)
     */
    // @phpstan-ignore-next-line
    protected function executeQuery($page, $perPage, $columns, ?int $limit = null)
    {
        if (isset($this->query->groups) && count($this->query->groups) > 0) {
            return $this->forPage($page, $perPage)->limit($limit ?? $perPage)->get($columns);
        }
        $_query = clone $this;
        $table = $this->model->getTable();
        // Inline the bindings of the cloned query (not of $this: select() above drops the "select" bindings
        // such as withCount / selectRaw / selectSub from the clone, so the bindings of $this would be shifted).
        // toRawSql() quotes every string with the connection's escaping and leaves only int/float unquoted,
        // so a string value such as '01' stays a string. Inlining it as the number 01 made
        // FIND_IN_SET(01, '01') fail and emptied the later pages (#1718).
        $query = $_query->select($table . '.id as sid')->forPage($page, $perPage)->limit($limit ?? $perPage)->toBase()->toRawSql();
        return $this->join(\DB::raw('(' . $query . ') s'), function ($join) use ($table) {
            $join->whereRaw($table . '.id in (s.sid)');
        })->get($columns);
    }
}
