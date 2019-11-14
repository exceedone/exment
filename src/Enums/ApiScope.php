<?php

namespace Exceedone\Exment\Enums;

class ApiScope extends EnumBase
{
    public const ME = 'me';
    public const SYSTEM_READ = 'system_read';
    public const SYSTEM_WRITE = 'system_write';
    public const TABLE_READ = 'table_read';
    public const TABLE_WRITE = 'table_write';
    public const VALUE_READ = 'value_read';
    public const VALUE_WRITE = 'value_write';
    public const NOTIFY_READ = 'notify_read';
    public const NOTIFY_WRITE = 'notify_write';

    /**
     * get scope string for middleware
     */
    public static function getScopeString($addScope, ...$scopes)
    {
        if (!$addScope) {
            return null;
        }

        return "scope:" . implode(",", collect($scopes)->map(function ($scope) {
            $enum = static::getEnum($scope);
            return $enum->getValue();
        })->toArray());
    }
}
