<?php

namespace Exceedone\Exment\Enums;

class ApiScope extends EnumBase
{
    public const ME = 'me';
    //public const SYSTEM_READ = 'system_read';
    //public const SYSTEM_WRITE = 'system_write';
    public const TABLE_READ = 'table_read';
    //public const TABLE_WRITE = 'table_write';
    public const VIEW_READ = 'view_read';
    public const VALUE_READ = 'value_read';
    public const VALUE_WRITE = 'value_write';
    public const WORKFLOW_READ = 'workflow_read';
    public const WORKFLOW_EXECUTE = 'workflow_execute';
    public const NOTIFY_READ = 'notify_read';
    public const NOTIFY_WRITE = 'notify_write';
    public const PLUGIN = 'plugin';
    public const LOG = 'log';
    // the users of an organization (ISS00335)
    public const USER_ORG_READ = 'user_org_read';
    public const USER_ORG_WRITE = 'user_org_write';
    // the role groups and their users and organizations (ISS00335)
    public const ROLE_GROUP_READ = 'role_group_read';
    public const ROLE_GROUP_WRITE = 'role_group_write';

    /**
     * get scope string for middleware
     */
    // @phpstan-ignore-next-line
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
