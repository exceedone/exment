<?php

namespace Exceedone\Exment\Model;

use Carbon\Carbon;
use Exceedone\Exment\Enums\LoginType;

/**
 * Login history. 1 record per successful login to the admin page.
 *
 * @property-read string|null $location
 * @property-read string|null $country_text
 * @property-read string|null $login_type_text
 * @property mixed $login_user_id
 * @property mixed $base_user_id
 * @property mixed $user_code
 * @property mixed $user_name
 * @property mixed $login_type
 * @property mixed $login_provider
 * @property mixed $ip_address
 * @property mixed $country_code
 * @property mixed $country
 * @property mixed $region
 * @property mixed $city
 * @property mixed $user_agent
 * @property mixed $is_new_ip
 * @property mixed $via_remember
 * @property mixed $auth_2factor_verified
 * @property mixed $created_at
 * @property mixed $updated_at
 * @property mixed $created_user_id
 * @property mixed $updated_user_id
 */
class LoginHistory extends ModelBase
{
    /**
     * Chunk size for deleting old records.
     */
    public const DELETE_CHUNK_SIZE = 1000;

    protected $casts = [
        'is_new_ip' => 'boolean',
        'via_remember' => 'boolean',
        'auth_2factor_verified' => 'boolean',
    ];

    /**
     * Location for display. "region, city". Returns null if both are unknown.
     *
     * @return string|null
     */
    public function getLocationAttribute()
    {
        $items = [];
        foreach ([$this->region, $this->city] as $item) {
            if (is_nullorempty($item) || in_array($item, $items)) {
                continue;
            }
            $items[] = $item;
        }

        return count($items) > 0 ? implode(', ', $items) : null;
    }

    /**
     * Country for display. "Japan (JP)". Returns null if unknown.
     *
     * @return string|null
     */
    public function getCountryTextAttribute()
    {
        if (is_nullorempty($this->country)) {
            return is_nullorempty($this->country_code) ? null : (string)$this->country_code;
        }
        if (is_nullorempty($this->country_code)) {
            return (string)$this->country;
        }

        return "{$this->country} ({$this->country_code})";
    }

    /**
     * Login type for display. "OAuth (google)"
     *
     * @return string|null
     */
    public function getLoginTypeTextAttribute()
    {
        if (is_nullorempty($this->login_type)) {
            return null;
        }

        $text = in_array($this->login_type, LoginType::arrays())
            ? exmtrans("login_history.login_type_options.{$this->login_type}")
            : (string)$this->login_type;

        if (!is_nullorempty($this->login_provider)) {
            $text .= " ({$this->login_provider})";
        }

        return $text;
    }

    /**
     * Delete records created before $threshold, in chunks to avoid locking the table for too long.
     *
     * @param Carbon $threshold
     * @return int deleted count
     */
    public static function deleteOlderThan(Carbon $threshold): int
    {
        $deleted = 0;

        do {
            $ids = static::query()
                ->where('created_at', '<', $threshold)
                ->orderBy('id')
                ->limit(static::DELETE_CHUNK_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += static::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === static::DELETE_CHUNK_SIZE);

        return $deleted;
    }
}
