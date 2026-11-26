<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogConnection;

/**
 * The logs take much space (ISS02839): the files of the application log over LIMIT_BYTES, or a
 * log of the database with more than LIMIT_ROWS rows that is kept until it is deleted by hand.
 * The log management says how long each is kept.
 */
class LogSizeTuning extends TuningBase
{
    public const LIMIT_BYTES = 1024 * 1024 * 1024;

    public const LIMIT_ROWS = 1000000;

    protected function key(): string
    {
        return 'log_size';
    }

    /**
     * The kinds that are too big.
     *
     * @return array<int, string> their keys
     */
    public function tooBig(): array
    {
        $big = [];
        foreach (LogCatalog::types() as $key => $type) {
            if (is_int($type['size_bytes']) && $type['size_bytes'] > static::LIMIT_BYTES) {
                $big[] = $key;
            } elseif (is_int($type['count']) && $type['count'] > static::LIMIT_ROWS && is_null($type['retention_days']) && $type['location'] === 'database') {
                $big[] = $key;
            }
        }
        return $big;
    }

    public function checkResult(): string
    {
        return count($this->tooBig()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getSettingUrl(): ?string
    {
        return admin_url('log_management');
    }

    /**
     * Big logs in the database of the data: a database of their own keeps them from the data
     * and its backups (ISS02905, ISS02927).
     */
    public function getFixText(): ?string
    {
        if (!is_null(LogConnection::name())) {
            return null;
        }
        $catalog = LogCatalog::types();
        $inDatabase = array_filter($this->tooBig(), function ($key) use ($catalog) {
            return ($catalog[$key]['location'] ?? null) === 'database';
        });
        if (count($inDatabase) == 0) {
            return null;
        }
        return exmtrans('tuning.item.log_size.log_db') . "
EXMENT_LOG_DB_CONNECTION=(the connection of config/database.php)
php artisan exment:log-db --copy";
    }
}
