<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * An API key of a user (see Services\UserApiKeys). The key itself is not kept: only its
 * hash and its first characters.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property array<int, string> $scopes
 * @property array<int, string>|null $tables
 * @property string|null $allowed_ips
 * @property string|null $client_cert_fingerprint
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property int $use_count
 * @property \Carbon\Carbon|null $revoked_at
 * @property int|null $revoked_user_id
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 */
class UserApiKey extends Model
{
    protected $table = 'user_api_keys';

    protected $fillable = [
        'user_id', 'name', 'key_prefix', 'key_hash', 'scopes', 'tables', 'allowed_ips',
        'client_cert_fingerprint', 'expires_at', 'created_user_id',
    ];

    /** never in an answer or a log, even as a hash */
    protected $hidden = ['key_hash'];

    protected $casts = [
        'user_id' => 'integer',
        'scopes' => 'array',
        'tables' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'use_count' => 'integer',
        'revoked_user_id' => 'integer',
        'created_user_id' => 'integer',
    ];

    /**
     * Whether it may be used now (not stopped, not past its date).
     */
    public function isActive(): bool
    {
        return !isset($this->revoked_at) && $this->expires_at->isFuture();
    }

    /**
     * The IP addresses or ranges it may come from (empty = any).
     *
     * @return array<int, string>
     */
    public function ipList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$this->allowed_ips) ?: [])));
    }
}
