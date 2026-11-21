<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\CustomTable;

/**
 * A large table without any "search index" column filters and sorts by reading every row.
 */
class SearchIndexTuning extends TuningBase
{
    /** A table counts as large from this many rows. */
    public const LARGE_ROWS = 10000;

    /** @var array<string>|null the large tables without an index (view names) */
    protected $tables;

    protected function key(): string
    {
        return 'search_index';
    }

    /**
     * @return array<string>
     */
    public function tables(): array
    {
        if (isset($this->tables)) {
            return $this->tables;
        }
        $this->tables = [];
        try {
            foreach (CustomTable::allRecords() as $table) {
                $hasIndex = $table->custom_columns_cache->contains(function ($column) {
                    return boolval($column->index_enabled);
                });
                if ($hasIndex) {
                    continue;
                }
                // large: there is a row past LARGE_ROWS (reads at most that many ids)
                $large = \DB::table(getDBTableName($table))->offset(static::LARGE_ROWS)->limit(1)->exists();
                if ($large) {
                    $this->tables[] = (string) $table->table_view_name;
                }
            }
        } catch (\Throwable $e) {
            // before the install, there are no tables
        }
        return $this->tables;
    }

    public function checkResult(): string
    {
        return count($this->tables()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $tables = $this->tables();
        return count($tables) == 0 ? exmtrans('tuning.result_ok') : implode(', ', $tables);
    }

    public function getSettingUrl(): ?string
    {
        return admin_url('table');
    }

    public function isServerWide(): bool
    {
        return false;
    }
}
