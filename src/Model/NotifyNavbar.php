<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;

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

        // the bell of the target user answers fresh on their next poll (ISS02824)
        static::saved(function ($model) {
            static::navbarCacheForget($model->target_user_id);
        });
        static::deleted(function ($model) {
            static::navbarCacheForget($model->target_user_id);
        });
    }

    /**
     * The cache key of the bell's answer (ApiController::notifyPage) for the login user.
     * It carries a per-user version that navbarCacheForget() moves, so an answer made before
     * a change is never read after it; the cache also ends after one poll interval.
     *
     * @return string|null null when there is no user to key on
     */
    public static function navbarCacheKey(): ?string
    {
        $userId = \Exment::getUserId();
        if (is_nullorempty($userId)) {
            return null;
        }
        $version = (int) \Cache::get(static::navbarVersionKey($userId), 0);
        return 'exment_notify_nav_' . $userId . '_' . $version . '_' . app()->getLocale();
    }

    /**
     * Make the next poll of these users answer fresh. Called when a notification is saved or
     * deleted, and by the writes that skip the model events (bulk insert, "mark all", "delete all").
     *
     * @param mixed $userIds a user id, or a list of them
     */
    public static function navbarCacheForget($userIds): void
    {
        $userIds = array_unique(array_filter((array) $userIds, function ($id) {
            return !is_nullorempty($id);
        }));
        foreach ($userIds as $userId) {
            try {
                $key = static::navbarVersionKey($userId);
                \Cache::forever($key, (int) \Cache::get($key, 0) + 1);
            } catch (\Throwable $ex) {
                // the cache ends after one interval anyway
            }
        }
    }

    /**
     * @param int|string $userId
     */
    protected static function navbarVersionKey($userId): string
    {
        return 'exment_notify_nav_ver_' . $userId;
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

        return CustomTable::whereIn('table_name', $parent_types->all())
            ->pluck('table_view_name', 'table_name')
            ->toArray();
    }
}
