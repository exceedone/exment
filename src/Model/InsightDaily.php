<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A number of a day for the page "Insights" (ISS02964): made every night (exment:insights), or
 * counted when it happens (Services\Insights\Insights::hit).
 *
 * @property int $id
 * @property \Carbon\Carbon $date
 * @property string $metric
 * @property string $key
 * @property int $value
 */
class InsightDaily extends Model
{
    protected $table = 'insight_daily';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'value' => 'integer',
    ];

    /** @var bool|null */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = \Schema::hasTable('insight_daily');
            } catch (\Throwable $e) {
                static::$tableExists = false;
            }
        }
        return static::$tableExists;
    }

    /** Ask the database again (tests, after a migration). */
    public static function forgetTableExists(): void
    {
        static::$tableExists = null;
    }
}
