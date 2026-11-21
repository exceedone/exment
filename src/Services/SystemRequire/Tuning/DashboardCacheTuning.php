<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Services\Dashboard\SummaryCache;

/**
 * A chart of a summary view aggregates its table on every load of the dashboard.
 * EXMENT_DASHBOARD_CACHE_SECONDS keeps the result for a while (ISS00413).
 * Advised only when a dashboard has such a chart.
 */
class DashboardCacheTuning extends TuningBase
{
    protected function key(): string
    {
        return 'dashboard_cache';
    }

    public function checkResult(): string
    {
        if (SummaryCache::enabled()) {
            return SystemRequireResult::OK;
        }
        return $this->hasSummaryChart() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        return SummaryCache::enabled() ? SummaryCache::seconds() . 's' : 'OFF';
    }

    public function getFixText(): ?string
    {
        return "# .env (seconds)\nEXMENT_DASHBOARD_CACHE_SECONDS=60";
    }

    protected function hasSummaryChart(): bool
    {
        try {
            $views = CustomView::where('view_kind_type', ViewKindType::AGGREGATE)->pluck('id')->all();
            if (empty($views)) {
                return false;
            }
            return DashboardBox::where('dashboard_box_type', DashboardBoxType::CHART)->get(['options'])
                ->contains(function ($box) use ($views) {
                    return in_array(array_get($box->options, 'target_view_id'), $views);
                });
        } catch (\Throwable $ex) {
            return false;
        }
    }
}
