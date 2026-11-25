<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\LogManagement\LogCatalog;

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
}
