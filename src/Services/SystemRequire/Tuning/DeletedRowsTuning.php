<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Services\TrashPurge;

/**
 * The tables with many deleted rows kept (ISS02927): the deleted data (in the trash) stays in the
 * table until it is deleted for good, and the lists and the summaries read past it. "Days to keep
 * deleted data" of the table deletes it for good every night (kept in the archive).
 */
class DeletedRowsTuning extends DataTuningBase
{
    public const LIMIT = 10000;

    /** ... and this share of the rows of the table (in percent) */
    public const SHARE = 20;

    protected function key(): string
    {
        return 'deleted_rows';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function many(): array
    {
        return array_filter($this->tables(), function ($counts) {
            $deleted = intval($counts['deleted']);
            $all = $deleted + intval($counts['rows']);
            if ($deleted < static::LIMIT || $deleted * 100 < $all * static::SHARE) {
                return false;
            }
            // not when it is already deleted for good after some days
            return TrashPurge::purgeable($counts['table']) && TrashPurge::keepDays($counts['table']) == 0;
        });
    }

    protected function warns(): bool
    {
        return count($this->many()) > 0;
    }

    protected function found(): string
    {
        $many = $this->many();
        if (count($many) == 0) {
            // the most, though it is not too many
            return static::topCounts($this->tables(), 'deleted');
        }
        return implode(', ', array_map(function ($counts) {
            return $counts['table']->table_view_name . ': ' . number_format(intval($counts['deleted']));
        }, $many));
    }

    public function getSettingUrl(): ?string
    {
        $first = array_values($this->many())[0] ?? null;
        return isset($first) ? static::tableUrl($first['table']) : parent::getSettingUrl();
    }
}
