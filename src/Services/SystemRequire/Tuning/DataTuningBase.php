<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\CustomTable;

/**
 * A tuning item of the data (ISS02927): it reads what DataStats counted at night, and is
 * "not counted yet" (cannot check) before the first count. Only tells: it changes nothing.
 */
abstract class DataTuningBase extends TuningBase
{
    /** @var array<string, mixed>|null|false false: not read yet */
    protected $stats = false;

    /**
     * @param array<string, mixed>|null $stats what was counted (a test gives it)
     */
    public function __construct(?array $stats = null)
    {
        if (isset($stats)) {
            $this->stats = $stats;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function stats(): ?array
    {
        if ($this->stats === false) {
            $this->stats = DataStats::load();
        }
        return $this->stats;
    }

    /**
     * The counts of the tables that still are, with the table.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function tables(): array
    {
        $result = [];
        foreach ((array) ($this->stats()['tables'] ?? []) as $name => $counts) {
            $table = CustomTable::getEloquent($name);
            if (!isset($table) || !is_array($counts)) {
                continue;
            }
            $result[$name] = $counts + ['table' => $table];
        }
        return $result;
    }

    /**
     * Whether it is a problem (with what was counted).
     */
    abstract protected function warns(): bool;

    /**
     * What was found (shown as the result).
     */
    abstract protected function found(): string;

    public function checkResult(): string
    {
        if (is_null($this->stats())) {
            return SystemRequireResult::CANNOT_CHECK;
        }
        return $this->warns() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $stats = $this->stats();
        if (is_null($stats)) {
            return exmtrans('tuning.data_not_counted');
        }
        $time = \Carbon\Carbon::createFromTimestamp(intval($stats['time']))->setTimezone(config('app.timezone'))->format('Y/m/d H:i');
        return $this->found() . ' ' . exmtrans('tuning.data_counted_at', ['time' => $time]);
    }

    protected function getMessageCannotCheck(): ?string
    {
        return exmtrans('tuning.data_not_counted_help');
    }

    public function getSettingUrl(): ?string
    {
        return admin_url('table');
    }

    /**
     * The edit page of a table, for the advice of one table.
     */
    protected static function tableUrl(CustomTable $table): string
    {
        return admin_urls('table', $table->id, 'edit');
    }

    public function isServerWide(): bool
    {
        return false;
    }

    /**
     * The tables with the most of a count ("table: count", the first TOP), or "0".
     *
     * @param array<string, array<string, mixed>> $tables
     */
    protected static function topCounts(array $tables, string $key, int $top = 3): string
    {
        $tables = array_filter($tables, function ($counts) use ($key) {
            return intval($counts[$key] ?? 0) > 0;
        });
        uasort($tables, function ($a, $b) use ($key) {
            return intval($b[$key]) <=> intval($a[$key]);
        });
        if (count($tables) == 0) {
            return '0';
        }
        return implode(', ', array_map(function ($counts) use ($key) {
            return $counts['table']->table_view_name . ': ' . number_format(intval($counts[$key]));
        }, array_slice($tables, 0, $top, true)));
    }

    public static function bytes(?int $bytes): string
    {
        if (is_null($bytes)) {
            return '-';
        }
        return bytesToHuman($bytes);
    }
}
