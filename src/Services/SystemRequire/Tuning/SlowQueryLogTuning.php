<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The slow query log of MySQL / MariaDB (ISS02823). With it, the database writes the queries
 * that take long, so what is slow can be found. Only read (SHOW VARIABLES); the other databases
 * have their own ways and are not judged.
 */
class SlowQueryLogTuning extends TuningBase
{
    /** Queries longer than this many seconds should be in the log. */
    public const MAX_LONG_QUERY_TIME = 2.0;

    /** @var array<string, string>|null|false the variables; false: not MySQL / MariaDB (a test sets it) */
    public $variables;

    protected function key(): string
    {
        return 'slow_query_log';
    }

    /**
     * @return array<string, string>|false
     */
    protected function variables()
    {
        if (isset($this->variables)) {
            return $this->variables;
        }
        try {
            if (!in_array(\Illuminate\Support\Facades\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                return $this->variables = false;
            }
            return $this->variables = $this->readRows(\Illuminate\Support\Facades\DB::select("SHOW VARIABLES WHERE Variable_name IN ('slow_query_log', 'long_query_time', 'log_output')"));
        } catch (\Throwable $e) {
            return $this->variables = false;
        }
    }

    /**
     * The rows of SHOW VARIABLES as name => value. The case of the keys follows PDO::ATTR_CASE
     * (lower in Exment: variable_name, value), so they are read in any case.
     *
     * @param array<mixed> $rows
     * @return array<string, string>
     */
    protected function readRows(array $rows): array
    {
        $variables = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $variables[strtolower((string) ($row['variable_name'] ?? ''))] = (string) ($row['value'] ?? '');
        }
        return $variables;
    }

    public function on(): bool
    {
        $variables = $this->variables();
        return is_array($variables) && in_array(strtoupper($variables['slow_query_log'] ?? ''), ['ON', '1'], true);
    }

    public function longQueryTime(): ?float
    {
        $variables = $this->variables();
        return is_array($variables) && isset($variables['long_query_time']) ? floatval($variables['long_query_time']) : null;
    }

    public function checkResult(): string
    {
        if ($this->variables() === false) {
            return SystemRequireResult::OK;
        }
        $time = $this->longQueryTime();
        return $this->on() && !is_null($time) && $time <= static::MAX_LONG_QUERY_TIME
            ? SystemRequireResult::OK
            : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        $variables = $this->variables();
        if ($variables === false) {
            return exmtrans('tuning.item.slow_query_log.not_mysql');
        }
        return ($this->on() ? 'ON' : 'OFF') . ' (long_query_time=' . ($variables['long_query_time'] ?? '-') . ')';
    }

    public function getFixText(): ?string
    {
        return implode("\n", [
            '# my.cnf ([mysqld]) / Amazon RDS: the parameter group',
            'slow_query_log = 1',
            'long_query_time = 1',
        ]);
    }
}
