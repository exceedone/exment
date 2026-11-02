<?php

namespace Exceedone\Exment\Model;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Failed login attempt. Counted to block an IP address, to show CAPTCHA, to warn of an account tried from many IP addresses,
 * and to lock the one-time password of a user. Not a login, so not recorded in the login history.
 *
 * @property mixed $ip_address
 * @property mixed $ip_key
 * @property mixed $login_user_id
 * @property mixed $base_user_id
 * @property mixed $username
 * @property mixed $reason
 * @property mixed $login_type
 * @property mixed $login_provider
 * @property mixed $multi_ip_warned
 * @property mixed $created_at
 * @property mixed $updated_at
 * @phpstan-consistent-constructor
 */
class LoginFailure extends ModelBase
{
    /**
     * Wrong ID or password.
     */
    public const REASON_PASSWORD = 'password';

    /**
     * Wrong CAPTCHA. The password was not checked.
     */
    public const REASON_CAPTCHA = 'captcha';

    /**
     * Wrong one-time password or recovery code of 2factor authentication. (The password was correct)
     */
    public const REASON_2FACTOR = '2factor';

    /**
     * Reasons counted as failures of the IP address. (A wrong one-time password is counted for the user only)
     */
    public const IP_REASONS = [self::REASON_PASSWORD, self::REASON_CAPTCHA];

    /**
     * Chunk size for deleting old records.
     */
    public const DELETE_CHUNK_SIZE = 1000;

    protected $table = SystemTableName::LOGIN_FAILURE;

    /**
     * No created_user_id / updated_user_id: nobody is logged in.
     *
     * @var bool
     */
    public $saving_users = false;

    protected $casts = [
        'multi_ip_warned' => 'boolean',
    ];

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
