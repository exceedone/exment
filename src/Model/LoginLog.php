<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One login, a failed one, a lockout or a logout (ISS02839). Written by
 * Services\LogManagement\LoginRecorder; read by the log management and the IT audit report.
 *
 * @property int $id
 * @property int|null $login_user_id
 * @property int|null $base_user_id
 * @property string|null $login_name
 * @property string|null $provider
 * @property string|null $provider_name
 * @property string $result
 * @property string|null $failure_reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Carbon\Carbon|null $created_at
 */
class LoginLog extends Model
{
    // on the connection of the logs when one is set (ISS02905)
    use Traits\UsesLogConnection;

    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';
    public const RESULT_LOCKOUT = 'lockout';
    public const RESULT_LOGOUT = 'logout';

    public const REASON_WRONG_PASSWORD = 'wrong_password';
    public const REASON_UNKNOWN_USER = 'unknown_user';
    public const REASON_2FA_FAILED = '2fa_failed';

    protected $table = 'login_logs';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @var bool|null whether the table is there (a site not migrated yet) */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = \Exceedone\Exment\Services\LogManagement\LogConnection::schema()->hasTable('login_logs');
            } catch (\Throwable $e) {
                static::$tableExists = false;
            }
        }
        return static::$tableExists;
    }

    /** Forget whether the table is there (tests, the migration). */
    public static function resetTableExists(): void
    {
        static::$tableExists = null;
    }
}
