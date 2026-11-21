<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;

/**
 * Logs for investigation write on every request: the system performance log
 * (a system setting) and the debug modes of .env (EXMENT_DEBUG_MODE_*).
 * The system performance log can be turned off from the tuning page.
 */
class DebugLogTuning extends TuningBase
{
    public const ACTION = 'logging_off';

    /** config key => .env name */
    public const DEBUG_CONFIGS = [
        'debugmode_request' => 'EXMENT_DEBUG_MODE_REQUEST',
        'debugmode_sql' => 'EXMENT_DEBUG_MODE_SQL',
        'debugmode_sqlfunction' => 'EXMENT_DEBUG_MODE_SQLFUNCTION',
        'debugmode_sqlfunction1' => 'EXMENT_DEBUG_MODE_SQLFUNCTION1',
        'debugmode_schedule' => 'EXMENT_DEBUG_MODE_SCHEDULE',
    ];

    protected function key(): string
    {
        return 'debug_log';
    }

    public function performanceLogOn(): bool
    {
        try {
            return hasTable(SystemTableName::SYSTEM) && boolval(System::logging_toggle_available());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string> the .env names of the debug modes that are on
     */
    public function debugModesOn(): array
    {
        $result = [];
        foreach (static::DEBUG_CONFIGS as $key => $env) {
            if (boolval(config("exment.{$key}"))) {
                $result[] = $env;
            }
        }
        return $result;
    }

    public function checkResult(): string
    {
        return $this->performanceLogOn() || count($this->debugModesOn()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getFixText(): ?string
    {
        $modes = $this->debugModesOn();
        if (count($modes) == 0) {
            return null;
        }
        return "# .env\n" . implode("\n", array_map(function ($env) {
            return "{$env}=false";
        }, $modes));
    }

    public function getAction(): ?string
    {
        return $this->performanceLogOn() ? static::ACTION : null;
    }

    public function isServerWide(): bool
    {
        // the performance log is a setting of the site, kept in its own database
        return false;
    }
}
