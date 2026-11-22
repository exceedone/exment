<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\CustomColumn;

/**
 * A column with "search index" has a column of its own in the table of the data
 * (column_<suuid>, made from the JSON) and an index on it (index_column_<suuid>) (ISS02823).
 * When one of them is missing (a restore, a failed change), filtering and sorting by the column
 * read every row although the setting says it is indexed. "exment:patchdata alter_index_all" makes them again.
 */
class IndexMissingTuning extends TuningBase
{
    /** @var array<string>|null "table / column" without their index */
    protected $missing;

    protected function key(): string
    {
        return 'index_missing';
    }

    /**
     * @return array<string>
     */
    public function missing(): array
    {
        if (isset($this->missing)) {
            return $this->missing;
        }
        $this->missing = [];
        try {
            foreach (CustomColumn::indexEnabled()->get() as $column) {
                $table = $column->custom_table_cache;
                if (is_null($table)) {
                    continue;
                }
                $dbTable = getDBTableName($table);
                // the table of the data is made with its first column: none yet, no rows to read
                if (!hasTable($dbTable)) {
                    continue;
                }
                $dbColumn = $column->getIndexColumnName(false);
                if (!hasColumn($dbTable, $dbColumn) || !\Schema::hasCustomIndex($dbTable, $dbColumn, "index_{$dbColumn}")) {
                    $this->missing[] = $table->table_view_name . ' / ' . $column->column_view_name;
                }
            }
        } catch (\Throwable $e) {
            // before the install, there are no tables
        }
        return $this->missing;
    }

    public function checkResult(): string
    {
        return count($this->missing()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $missing = $this->missing();
        return count($missing) == 0 ? exmtrans('tuning.item.index_missing.ok') : implode(', ', $missing);
    }

    public function getFixText(): ?string
    {
        return 'php artisan exment:patchdata alter_index_all';
    }

    public function isServerWide(): bool
    {
        return false;
    }
}
