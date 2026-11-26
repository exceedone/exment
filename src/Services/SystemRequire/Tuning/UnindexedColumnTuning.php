<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Jobs\AddColumnIndexJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;

/**
 * The columns of the large tables without "search index" that the views filter, sort or group by,
 * and the reference columns (ISS02927). The tuning page lists them with a link to the column, and
 * adds the index to the columns the administrator chooses, in the background (AddColumnIndexJob):
 * it can take long on a large table. Nothing is added by itself.
 */
class UnindexedColumnTuning extends DataTuningBase
{
    public const ACTION = 'add_index';

    /** Shown, at most. */
    public const SHOWN = 20;

    /** A column waits for its index this long, at most (minutes): then it is shown again. */
    public const PENDING_MINUTES = 60;

    /** @var array<int, array{column: CustomColumn, table: \Exceedone\Exment\Model\CustomTable, rows: int, uses: array<int, string>}>|null */
    protected $columns;

    protected function key(): string
    {
        return 'unindexed_columns';
    }

    /**
     * The columns waiting for their index: column id => since when.
     *
     * @return array<int, int>
     */
    public static function pending(): array
    {
        try {
            $pending = json_decode(strval(json_encode(System::tuning_index_pending())), true);
        } catch (\Throwable $e) {
            return [];
        }
        $since = time() - static::PENDING_MINUTES * 60;
        $result = [];
        foreach ((array) $pending as $id => $time) {
            if (is_numeric($id) && intval($time) >= $since) {
                $result[intval($id)] = intval($time);
            }
        }
        return $result;
    }

    /**
     * @param array<int, int> $pending
     */
    public static function setPending(array $pending): void
    {
        System::tuning_index_pending((object) $pending);
        System::clearCache(sprintf(\Exceedone\Exment\Model\Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, 'tuning_index_pending'));
    }

    /**
     * The columns to tell, without the ones waiting for their index.
     *
     * @return array<int, array{column: CustomColumn, table: \Exceedone\Exment\Model\CustomTable, rows: int, uses: array<int, string>}>
     */
    public function columns(): array
    {
        if (!isset($this->columns)) {
            $this->columns = array_diff_key((new ViewUsage($this->stats()))->unindexedColumns(), static::pending());
        }
        return $this->columns;
    }

    protected function warns(): bool
    {
        return count($this->columns()) > 0;
    }

    /**
     * @param array{column: CustomColumn, table: \Exceedone\Exment\Model\CustomTable, rows: int, uses: array<int, string>} $item
     */
    protected static function label(array $item): string
    {
        $uses = array_map(function ($use) {
            return exmtrans('tuning.item.unindexed_columns.use.' . $use);
        }, $item['uses']);
        return $item['table']->table_view_name . ' / ' . $item['column']->column_view_name . ' (' . implode(', ', $uses) . ')';
    }

    protected function found(): string
    {
        $columns = $this->columns();
        $pending = count(static::pending());
        if (count($columns) == 0) {
            $text = exmtrans('tuning.result_ok');
        } else {
            $labels = array_map([static::class, 'label'], array_slice($columns, 0, static::SHOWN, true));
            $text = implode(', ', $labels);
            if (count($columns) > static::SHOWN) {
                $text .= ' ' . exmtrans('tuning.more', ['count' => count($columns) - static::SHOWN]);
            }
        }
        if ($pending > 0) {
            $text .= ' / ' . exmtrans('tuning.item.unindexed_columns.pending', ['count' => $pending]);
        }
        return $text;
    }

    public function getAction(): ?string
    {
        return count($this->getChoices() ?? []) > 0 ? static::ACTION : null;
    }

    /**
     * The columns that may have the index now (the table has not all its index columns yet).
     *
     * @return array<int, string>|null
     */
    public function getChoices(): ?array
    {
        $limit = intval(config('exment.column_index_enabled_count', 20));
        $counts = [];
        $choices = [];
        foreach (array_slice($this->columns(), 0, static::SHOWN, true) as $id => $item) {
            $tableId = intval($item['table']->id);
            if (!isset($counts[$tableId])) {
                $counts[$tableId] = CustomColumn::where('custom_table_id', $tableId)->whereIn('options->index_enabled', [1, '1'])->count();
            }
            if ($counts[$tableId] >= $limit) {
                continue;
            }
            $choices[$id] = static::label($item);
        }
        return $choices;
    }

    /**
     * Add the index to the chosen columns, in the background. Only the columns of getChoices().
     *
     * @param array<mixed> $ids
     * @return int how many were started
     */
    public function addIndexes(array $ids): int
    {
        $choices = $this->getChoices() ?? [];
        $pending = static::pending();
        $started = [];
        foreach ($ids as $id) {
            $id = is_numeric($id) ? intval($id) : 0;
            if (!isset($choices[$id]) || in_array($id, $started, true)) {
                continue;
            }
            $pending[$id] = time();
            $started[] = $id;
        }
        if (count($started) == 0) {
            return 0;
        }
        static::setPending($pending);
        foreach ($started as $id) {
            AddColumnIndexJob::start($id, \Exment::getUserId());
        }
        return count($started);
    }

    public function getSettingUrl(): ?string
    {
        $first = array_values($this->columns())[0] ?? null;
        return isset($first) ? admin_urls('column', $first['table']->table_name, $first['column']->id, 'edit') : parent::getSettingUrl();
    }
}
