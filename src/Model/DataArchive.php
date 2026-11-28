<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * An archive of old rows (ISS02970): the file, what is in it, and where it is in its life
 * (written, verified, the rows deleted from the database, restored; in the trash; held).
 *
 * @property int $id
 * @property string $uuid
 * @property string $kind
 * @property string $source
 * @property int|null $custom_table_id
 * @property string|null $date_column
 * @property \Carbon\Carbon|null $before
 * @property \Carbon\Carbon|null $range_from
 * @property \Carbon\Carbon|null $range_to
 * @property int $row_count
 * @property int $deleted_count
 * @property int $restored_count
 * @property int $bytes
 * @property string $disk
 * @property string|null $path
 * @property string|null $sha256
 * @property string|null $ids_hash
 * @property string|null $exment_version
 * @property string $status
 * @property bool $warning
 * @property string|null $message
 * @property bool $legal_hold
 * @property \Carbon\Carbon|null $deleted_at
 * @property \Carbon\Carbon|null $purge_at
 * @property \Carbon\Carbon|null $purge_notified_at
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class DataArchive extends Model
{
    public const STATUS_WRITING = 'writing';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_FAILED = 'failed';

    public const KIND_SYSTEM = 'system';

    protected $table = 'data_archives';

    protected $guarded = ['id'];

    protected $casts = [
        'before' => 'datetime',
        'range_from' => 'datetime',
        'range_to' => 'datetime',
        'deleted_at' => 'datetime',
        'purge_at' => 'datetime',
        'purge_notified_at' => 'datetime',
        'row_count' => 'integer',
        'deleted_count' => 'integer',
        'restored_count' => 'integer',
        'bytes' => 'integer',
        'warning' => 'boolean',
        'legal_hold' => 'boolean',
    ];

    /** @var bool|null */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = \Schema::hasTable('data_archives');
            } catch (\Throwable $e) {
                static::$tableExists = false;
            }
        }
        return static::$tableExists;
    }

    public static function forgetTableExists(): void
    {
        static::$tableExists = null;
    }

    /** In the trash of the archives. */
    public function inTrash(): bool
    {
        return isset($this->deleted_at);
    }
}
