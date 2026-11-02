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
    /**
     * System tables a notification links to, instead of a custom value. (warnings of the login security)
     * key: parent_type (the table name), value: uri of the admin page, and the key of its header.
     */
    public const SYSTEM_PARENTS = [
        SystemTableName::LOGIN_HISTORY => ['uri' => 'login_history', 'header' => 'login_history.header'],
        SystemTableName::LOGIN_IP_BLOCK => ['uri' => 'login_ip_block', 'header' => 'login_ip_block.header'],
    ];

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
     * Whether this notification links to a system table (SYSTEM_PARENTS), not to a custom value.
     * (Ex. warning of login history, blocked IP address)
     *
     * @return bool
     */
    public function isSystemParent(): bool
    {
        return !is_null(static::getSystemParentHeader($this->parent_type)) && !is_nullorempty($this->parent_id);
    }

    /**
     * Header of the system table of the parent type. (Ex. "login history")
     *
     * @param mixed $parent_type
     * @return string|null null: not a system table of SYSTEM_PARENTS
     */
    public static function getSystemParentHeader($parent_type): ?string
    {
        if (!is_string($parent_type) || !array_key_exists($parent_type, static::SYSTEM_PARENTS)) {
            return null;
        }
        return exmtrans(static::SYSTEM_PARENTS[$parent_type]['header']);
    }

    /**
     * Url of the linked data of the system table.
     *
     * @return string|null null: not linked to a system table
     */
    public function getSystemParentUrl(): ?string
    {
        if (!$this->isSystemParent()) {
            return null;
        }
        return admin_urls(static::SYSTEM_PARENTS[$this->parent_type]['uri'], $this->parent_id);
    }

    /**
     * Whether the linked data of the system table still exists. (It may have been deleted manually or automatically)
     *
     * @return bool
     */
    public function systemParentExists(): bool
    {
        if (!$this->isSystemParent() || !hasTable($this->parent_type)) {
            return false;
        }
        return \DB::table($this->parent_type)->where('id', $this->parent_id)->exists();
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
     * The warnings linked to a system table (Ex. "login_histories", see SYSTEM_PARENTS) are listed under its own name.
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
        foreach ($parent_types as $parent_type) {
            if (!is_null($header = static::getSystemParentHeader($parent_type))) {
                $options[$parent_type] = $header;
            }
        }

        return $options;
    }
}
