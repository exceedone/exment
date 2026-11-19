<?php

namespace Exceedone\Exment\Services\Installer;

use Closure;

/**
 * Schema builder wrapper whose create() skips a table that already exists.
 *
 * Bound as "db.schema" only while SkeletonMigrationReconciler runs one migration;
 * every other call goes to the wrapped builder.
 *
 * @mixin \Illuminate\Database\Schema\Builder
 */
class SkipExistingTablesSchema
{
    /**
     * @var \Illuminate\Database\Schema\Builder
     */
    protected $builder;

    /**
     * @param \Illuminate\Database\Schema\Builder $builder
     */
    public function __construct($builder)
    {
        $this->builder = $builder;
    }

    /**
     * Create the table unless it exists.
     *
     * @param string $table
     * @param Closure $callback
     * @return void
     */
    public function create($table, Closure $callback)
    {
        if ($this->builder->hasTable($table)) {
            return;
        }

        $this->builder->create($table, $callback);
    }

    /**
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->builder->{$method}(...$parameters);
    }
}
