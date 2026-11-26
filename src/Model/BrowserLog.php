<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A row of the log of the browsers (ISS02904): an error of a script, a request that failed or a
 * page opened. Written by Services\LogManagement\BrowserLogs; read by the log management.
 *
 * @property int $id
 * @property int|null $base_user_id
 * @property string $kind
 * @property string|null $path
 * @property int|null $status
 * @property int|null $duration_ms
 * @property string|null $message
 * @property string|null $source
 * @property int $count
 * @property string|null $tab
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $screen
 * @property string|null $lang
 * @property \Carbon\Carbon|null $created_at
 */
class BrowserLog extends Model
{
    public const KIND_ERROR = 'error';
    public const KIND_REQUEST = 'request';
    public const KIND_PAGE = 'page';
    public const KINDS = [self::KIND_ERROR, self::KIND_REQUEST, self::KIND_PAGE];

    protected $table = 'browser_logs';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'status' => 'integer',
        'duration_ms' => 'integer',
        'count' => 'integer',
    ];

    /** @var bool|null whether the table is there (a site not migrated yet) */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = Schema::hasTable('browser_logs');
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
