<?php

namespace Exceedone\Exment\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * How many bound parameters one statement may carry, and what the statement carries now.
 *
 * SQL Server (pdo_sqlsrv) refuses a statement with more than 2,100 bound parameters ("Tried to bind
 * parameter number 2101"). The other drivers have no practical limit here and are left as they are:
 * limit() is null for them, so every check answers "fits".
 *
 * This class only counts and answers. What to do when a statement does not fit depends on the caller
 * (a list of ids read from the database is replaced by a subquery, a bulk write is cut into several
 * statements...), so the fallback lives with the caller. preferSubqueries() is the one switch shared
 * by the builder helpers that have a set-based form (ExtendedBuilderTrait::whereInMultiple()).
 *
 * Not a way to make a statement fit: several OR-ed IN lists carry the sum of their values, and no
 * input is ever truncated.
 */
final class ParameterBudget
{
    /** The most bound parameters pdo_sqlsrv accepts in one statement. */
    public const SQLSRV_LIMIT = 2100;

    /**
     * Left free for what the check cannot see: parts added after it (a later scope, a filter of the
     * caller, paging) and whatever the driver adds.
     */
    public const SQLSRV_RESERVE = 100;

    /** @var int how many preferSubqueries() calls are open */
    private static $subqueryDepth = 0;

    /**
     * The bound parameters one statement of the connection may carry (reserve already taken off),
     * null when the driver needs no budget.
     */
    public static function limit(?string $connection = null): ?int
    {
        return static::driverName($connection) === 'sqlsrv' ? static::SQLSRV_LIMIT - static::SQLSRV_RESERVE : null;
    }

    public static function isLimited(?string $connection = null): bool
    {
        return static::limit($connection) !== null;
    }

    /**
     * The bound parameters of the statement as it is built now. For an Eloquent builder this is the
     * statement without the global scopes still to be applied (toBase() applies them, and must not be
     * called from inside a scope): pass what the scope itself will add as $additional of fits().
     *
     * @param EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder|array<mixed> $query a builder, or the bindings
     */
    public static function bindingCount($query): int
    {
        if ($query instanceof EloquentBuilder) {
            $query = $query->getQuery();
        }
        if ($query instanceof QueryBuilder) {
            return count($query->getBindings());
        }
        return count((array)$query);
    }

    /**
     * Whether the statement still fits with $additional more bound parameters. Always true for a driver
     * without a limit.
     *
     * @param EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder|array<mixed> $query
     */
    public static function fits($query, int $additional = 0, ?string $connection = null): bool
    {
        $limit = static::limit($connection);
        return $limit === null || static::bindingCount($query) + $additional <= $limit;
    }

    /**
     * Run $callback with the set-based (subquery) form of the builder helpers that have one. What is
     * built inside carries the values of a lookup as a subquery instead of as bound ids.
     *
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    public static function preferSubqueries(\Closure $callback)
    {
        static::$subqueryDepth++;
        try {
            return $callback();
        } finally {
            static::$subqueryDepth--;
        }
    }

    public static function subqueriesPreferred(): bool
    {
        return static::$subqueryDepth > 0;
    }

    private static function driverName(?string $connection): string
    {
        return DB::connection($connection)->getDriverName();
    }
}
