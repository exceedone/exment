<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * An IT audit report made as PDF (ISS02840): its period, who made it, its SHA-256 and, for the
 * ones of the job, the file kept on the server. Services\AuditReport\AuditReportStore.
 *
 * @property int $id
 * @property \Carbon\Carbon $period_from
 * @property \Carbon\Carbon $period_to
 * @property string $source
 * @property string $sha256
 * @property int $size
 * @property string|null $file
 * @property int|null $made_user_id
 * @property \Carbon\Carbon|null $created_at
 */
class AuditReportFile extends Model
{
    public const SOURCE_PAGE = 'page';
    public const SOURCE_SCHEDULE = 'schedule';

    protected $table = 'audit_reports';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'size' => 'integer',
        'made_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @var bool|null */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = Schema::hasTable('audit_reports');
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
