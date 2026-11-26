<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

/**
 * The views of the large tables that read every row (ISS02927): a filter or a sort by a column
 * without "search index", a group of a summary view without one, a summary of a child table.
 */
class HeavyViewTuning extends DataTuningBase
{
    public const SHOWN = 20;

    /** @var array<int, array{view_id: int, table_id: int, rows: int, reasons: array<int, string>}>|null */
    protected $views;

    protected function key(): string
    {
        return 'heavy_views';
    }

    /**
     * @return array<int, array{view_id: int, table_id: int, rows: int, reasons: array<int, string>}>
     */
    public function views(): array
    {
        if (!isset($this->views)) {
            $this->views = (new ViewUsage($this->stats()))->heavyViews();
        }
        return $this->views;
    }

    protected function warns(): bool
    {
        return count($this->views()) > 0;
    }

    protected function found(): string
    {
        $views = $this->views();
        if (count($views) == 0) {
            return exmtrans('tuning.result_ok');
        }
        $texts = [];
        foreach (array_slice($views, 0, static::SHOWN, true) as $item) {
            $view = CustomView::getEloquent($item['view_id']);
            $table = CustomTable::getEloquent($item['table_id']);
            if (!isset($view, $table)) {
                continue;
            }
            $reasons = array_map(function ($reason) {
                return exmtrans('tuning.item.heavy_views.reason.' . $reason);
            }, $item['reasons']);
            $texts[] = $table->table_view_name . ' / ' . $view->view_view_name . ' (' . implode(', ', $reasons) . ')';
        }
        $text = implode(', ', $texts);
        if (count($views) > static::SHOWN) {
            $text .= ' ' . exmtrans('tuning.more', ['count' => count($views) - static::SHOWN]);
        }
        return $text;
    }

    public function getSettingUrl(): ?string
    {
        $first = array_values($this->views())[0] ?? null;
        if (isset($first)) {
            $table = CustomTable::getEloquent($first['table_id']);
            if (isset($table)) {
                return admin_urls('view', $table->table_name, $first['view_id'], 'edit');
            }
        }
        return parent::getSettingUrl();
    }
}
