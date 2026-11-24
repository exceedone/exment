<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A temporary raise of the API rate limit (Services\ApiRateRaises, ISS00442): a user or an
 * API client may make more requests per minute until a time.
 *
 * @property int $id
 * @property string $target_type "user" or "client"
 * @property string $target_id the id of the user or of the API client
 * @property int $per_minute
 * @property \Carbon\Carbon $expires_at
 * @property string|null $reason
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class ApiRateRaise extends Model
{
    public const TABLE = 'api_rate_raises';

    public const TARGET_USER = 'user';
    public const TARGET_CLIENT = 'client';

    protected $table = self::TABLE;

    protected $fillable = [
        'target_type', 'target_id', 'per_minute', 'expires_at', 'reason', 'created_user_id',
    ];

    protected $casts = [
        'per_minute' => 'integer',
        'expires_at' => 'datetime',
        'created_user_id' => 'integer',
    ];

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
