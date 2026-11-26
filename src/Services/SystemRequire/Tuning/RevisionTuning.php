<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

/**
 * The tables with very many revisions (ISS02927): the saving of the data and the backups get slow.
 * "Data Revision Versioning Count" of the table keeps fewer for each row.
 */
class RevisionTuning extends DataTuningBase
{
    public const LIMIT = 1000000;

    protected function key(): string
    {
        return 'revisions';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function many(): array
    {
        $tables = array_filter($this->tables(), function ($counts) {
            return intval($counts['revisions']) > static::LIMIT;
        });
        uasort($tables, function ($a, $b) {
            return intval($b['revisions']) <=> intval($a['revisions']);
        });
        return $tables;
    }

    protected function warns(): bool
    {
        return count($this->many()) > 0;
    }

    protected function found(): string
    {
        $many = $this->many();
        if (count($many) == 0) {
            return exmtrans('tuning.result_ok');
        }
        return implode(', ', array_map(function ($counts) {
            return $counts['table']->table_view_name . ': ' . number_format(intval($counts['revisions'])) . ' (' . exmtrans('tuning.item.revisions.count', ['count' => $counts['table']->getOption('revision_count', 100)]) . ')';
        }, $many));
    }

    public function getSettingUrl(): ?string
    {
        $first = array_values($this->many())[0] ?? null;
        return isset($first) ? static::tableUrl($first['table']) : parent::getSettingUrl();
    }
}
