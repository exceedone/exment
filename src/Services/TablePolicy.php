<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * The rules of one table set by an application built on Exment (registered from its service
 * provider with TablePolicy::register()): who may see and change its rows, which rows a user
 * reads, and the options of a select column. Each method returns null to leave the decision
 * to Exment, as the base class does.
 */
abstract class TablePolicy
{
    /** @var array<string, TablePolicy> table_name => policy */
    protected static $policies = [];

    public static function register(string $tableName, TablePolicy $policy): void
    {
        static::$policies[$tableName] = $policy;
    }

    public static function of(?string $tableName): ?TablePolicy
    {
        return is_null($tableName) ? null : (static::$policies[$tableName] ?? null);
    }

    /**
     * Forget the policies (tests).
     */
    public static function reset(): void
    {
        static::$policies = [];
    }

    /**
     * CustomTable::hasPermission(): true or false decides, null goes on.
     *
     * @param string|array<string> $role_key
     */
    public function hasPermission(CustomTable $table, $role_key): ?bool
    {
        return null;
    }

    /**
     * CustomTable::hasPermissionData() (the row may be read): true or false decides, null goes on.
     *
     * @param mixed $id the id or the CustomValue, null for the table
     */
    public function hasPermissionData(CustomTable $table, $id): ?bool
    {
        return null;
    }

    /**
     * CustomTable::hasPermissionEditData(): true or false decides, null goes on.
     *
     * @param mixed $id the id or the CustomValue, null for the table
     */
    public function hasPermissionEditData(CustomTable $table, $id): ?bool
    {
        return null;
    }

    /**
     * The rows the user reads (CustomValueModelScope). Return true when this is the whole
     * filter (Exment adds nothing), false to let Exment add its own after it.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $builder
     * @param mixed $user the login user, or null (console, before the login)
     */
    public function scope($builder, CustomTable $table, $user): bool
    {
        return false;
    }

    /**
     * The options of a select column (CustomColumn::createSelectOptions()), null for its own.
     *
     * @return array<mixed>|\Illuminate\Support\Collection<int|string, mixed>|null
     */
    public function selectOptions(CustomColumn $column)
    {
        return null;
    }

    /**
     * Before a column of the table is saved (to keep a setting the screen must not change).
     */
    public function savingColumn(CustomColumn $column): void
    {
    }
}
