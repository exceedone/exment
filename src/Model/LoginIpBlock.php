<?php

namespace Exceedone\Exment\Model;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * IP address (or IPv6 network) blocked by too many failed logins. 1 record per IP address.
 * The record is kept after the block ends (or is released), to make the next block of the same IP address longer.
 *
 * @property mixed $ip_key
 * @property mixed $ip_address
 * @property mixed $country_code
 * @property mixed $country
 * @property mixed $block_level
 * @property mixed $failed_count
 * @property mixed $last_failure_id
 * @property mixed $blocked_attempts
 * @property mixed $last_username
 * @property Carbon|null $blocked_at
 * @property Carbon|null $blocked_until
 * @property Carbon|null $released_at
 * @property mixed $created_at
 * @property mixed $updated_at
 * @property mixed $created_user_id
 * @property mixed $updated_user_id
 * @property-read string|null $country_text
 * @phpstan-consistent-constructor
 */
class LoginIpBlock extends ModelBase
{
    /**
     * Status: blocked now.
     */
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Status: the block time is over.
     */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Status: released by an administrator.
     */
    public const STATUS_RELEASED = 'released';

    protected $table = SystemTableName::LOGIN_IP_BLOCK;

    protected $casts = [
        'blocked_at' => 'datetime',
        'blocked_until' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * Scope: blocked now.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeBlocked($query)
    {
        return $query->where('blocked_until', '>', Carbon::now());
    }

    /**
     * Whether blocked now.
     *
     * @return bool
     */
    public function isBlocked(): bool
    {
        return !is_null($this->blocked_until) && $this->blocked_until->greaterThan(Carbon::now());
    }

    /**
     * @return string self::STATUS_*
     */
    public function getStatus(): string
    {
        if ($this->isBlocked()) {
            return static::STATUS_BLOCKED;
        }
        return is_null($this->released_at) ? static::STATUS_EXPIRED : static::STATUS_RELEASED;
    }

    /**
     * Remaining minutes of the block. (rounded up, at least 1 while blocked)
     *
     * @return int 0 if not blocked
     */
    public function getRemainingMinutes(): int
    {
        if (!$this->isBlocked() || is_null($this->blocked_until)) {
            return 0;
        }
        return max(1, (int)ceil(Carbon::now()->diffInSeconds($this->blocked_until, true) / 60));
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
}
