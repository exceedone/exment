<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * @property mixed $notify_id
 * @property mixed $parent_id
 * @property mixed $parent_type
 * @property mixed $target_user_id
 * @property mixed $trigger_user_id
 * @property mixed $read_flg
 * @property mixed $notify_subject
 * @property mixed $notify_body
 * @method static ExtendedBuilder take($value)
 * @method static ExtendedBuilder whereIn($column, $values, $boolean = 'and', $not = false)
 * @method static ExtendedBuilder withoutGlobalScopes(array $scopes = null)
 * @phpstan-consistent-constructor
 */
class NotifyNavbar extends ModelBase
{
    protected static function boot()
    {
        parent::boot();

        // add global scope
        static::addGlobalScope('target_user', function ($builder) {
            return $builder->where('target_user_id', \Exment::getUserId())
                ->orderBy('read_flg', 'asc')->orderBy('created_at', 'desc');
        });
    }

    /**
     * Whether this notification is a warning of login history.
     * (parent_type is "login_histories", parent_id is the id of the login history.)
     *
     * @return bool
     */
    public function isLoginHistory(): bool
    {
        return isMatchString($this->parent_type, SystemTableName::LOGIN_HISTORY) && !is_nullorempty($this->parent_id);
    }

    /**
     * Get the custom tables appearing in the login user's own notifications,
     * as options of the "target table" filter on the notification list.
     * Key is table_name (the value stored in parent_type), value is table_view_name.
     *
     * The tables are taken from the notification data itself, not from the login user's
     * table permissions, so a user without any table permission still gets the list.
     * Notifications without parent_type, and parent_type of a table that no longer exists, are skipped.
     * Hidden tables (showlist_flg false) are kept, because their notification rows are listed anyway.
     * The warning of login history (parent_type is the system table "login_histories") is listed under its own name.
     *
     * @return array<string, string>
     */
    public static function getTargetTableOptions(): array
    {
        $user_id = \Exment::getUserId();
        if (is_null($user_id)) {
            return [];
        }

        // Not using the "target_user" global scope: its "order by read_flg, created_at"
        // is not allowed together with "select distinct parent_type".
        $parent_types = static::withoutGlobalScope('target_user')
            ->where('target_user_id', $user_id)
            ->whereNotNull('parent_type')
            ->distinct()
            ->pluck('parent_type');

        if ($parent_types->isEmpty()) {
            return [];
        }

        $options = CustomTable::whereIn('table_name', $parent_types->all())
            ->pluck('table_view_name', 'table_name')
            ->toArray();

        // Set after the custom tables, so that a custom table of the same name never takes the place of the warning.
        if ($parent_types->contains(SystemTableName::LOGIN_HISTORY)) {
            $options[SystemTableName::LOGIN_HISTORY] = exmtrans('login_history.header');
        }

        return $options;
    }
}
