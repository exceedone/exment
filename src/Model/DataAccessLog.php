<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A read or a write of the data (ISS02905): Services\LogManagement\DataAccessLogs. No value.
 *
 * @property int $id
 * @property int|null $base_user_id
 * @property int $custom_table_id
 * @property int|null $record_id
 * @property int|null $custom_view_id
 * @property string $kind
 * @property string $via
 * @property string|null $ip
 * @property \Carbon\Carbon|null $created_at
 */
class DataAccessLog extends Model
{
    public const KIND_READ = 'read';
    public const KIND_LIST = 'list';
    public const KIND_EXPORT = 'export';
    public const KIND_CREATE = 'create';
    public const KIND_UPDATE = 'update';
    public const KIND_DELETE = 'delete';
    public const KIND_RESTORE = 'restore';
    public const KIND_FORCE_DELETE = 'force_delete';

    /** The kinds that read (recorded only when the operation log keeps everything). */
    public const READS = [self::KIND_READ, self::KIND_LIST, self::KIND_EXPORT];
    public const WRITES = [self::KIND_CREATE, self::KIND_UPDATE, self::KIND_DELETE, self::KIND_RESTORE, self::KIND_FORCE_DELETE];
    public const KINDS = [self::KIND_READ, self::KIND_LIST, self::KIND_EXPORT, self::KIND_CREATE, self::KIND_UPDATE, self::KIND_DELETE, self::KIND_RESTORE, self::KIND_FORCE_DELETE];

    public const VIA_PAGE = 'page';
    public const VIA_API = 'api';
    public const VIA_MCP = 'mcp';
    public const VIA_SYSTEM = 'system';
    public const VIAS = [self::VIA_PAGE, self::VIA_API, self::VIA_MCP, self::VIA_SYSTEM];

    protected $table = 'data_access_logs';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @var bool|null */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = Schema::hasTable('data_access_logs');
            } catch (\Throwable $e) {
                static::$tableExists = false;
            }
        }
        return static::$tableExists;
    }

    public static function resetTableExists(): void
    {
        static::$tableExists = null;
    }
}
