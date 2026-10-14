<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

/**
 * Recovery code of 2factor authentication: a one-time code the user can enter instead of the one-time password,
 * when the user cannot receive or see it. Only the hash of the code is stored.
 *
 * @property mixed $login_user_id
 * @property mixed $code_hash
 * @property mixed $used_at
 * @property mixed $created_at
 * @property mixed $updated_at
 * @phpstan-consistent-constructor
 */
class Auth2factorRecoveryCode extends ModelBase
{
    protected $table = SystemTableName::AUTH2FACTOR_RECOVERY_CODE;

    protected $guarded = ['id'];

    protected $casts = ['used_at' => 'datetime'];

    /**
     * Scope: codes not used yet.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }
}
