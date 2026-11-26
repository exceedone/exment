<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of the log of the MCP calls (ISS02915). Written by Services\LogManagement\McpLogs; read by
 * the log management.
 *
 * @property int $id
 * @property int|null $base_user_id
 * @property string|null $method
 * @property string|null $tool
 * @property string $status
 * @property string|null $reason
 * @property int|null $rpc_code
 * @property int|null $duration_ms
 * @property int|null $result_bytes
 * @property string|null $args
 * @property string|null $ip
 * @property \Carbon\Carbon|null $created_at
 */
class McpLog extends Model
{
    // on the connection of the logs when one is set (ISS02905)
    use Traits\UsesLogConnection;

    public const STATUS_OK = 'ok';
    public const STATUS_TOOL_ERROR = 'tool_error';
    public const STATUS_ERROR = 'error';
    public const STATUS_REFUSED = 'refused';
    public const STATUSES = [self::STATUS_OK, self::STATUS_TOOL_ERROR, self::STATUS_ERROR, self::STATUS_REFUSED];

    protected $table = 'mcp_logs';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'rpc_code' => 'integer',
        'duration_ms' => 'integer',
        'result_bytes' => 'integer',
    ];

    /** @var bool|null whether the table is there (a site not migrated yet) */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = \Exceedone\Exment\Services\LogManagement\LogConnection::schema()->hasTable('mcp_logs');
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
