<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Services\Dashboard\ChartDetailRequest;

/**
 * "These are the rows behind a chart point" — on a data list opened by a double click on a
 * dashboard chart (明細, ChartDetailRequest). The row of the grid's own filter chips
 * (GridFilterChips) in the same look: the chart it came from, one chip per condition the rows
 * were picked by, the way back to the dashboard and to the whole list. The conditions are
 * no grid filters a chip could take off one by one: they name the point together.
 */
class ChartDetailChips extends AbstractTool
{
    /** @var ChartDetailRequest */
    protected $detail;

    public function __construct(ChartDetailRequest $detail)
    {
        $this->detail = $detail;
    }

    /**
     * @return string
     */
    public function render()
    {
        $back = e($this->detail->backUrl());
        $backLabel = e(exmtrans('dashboard.chart_detail.back'));
        if (!$this->detail->valid()) {
            $message = e(exmtrans('dashboard.chart_detail.invalid'));
            return <<<HTML
<div class="exm-filter-chips exm-chart-detail">
    <span class="exm-filter-chips-title text-danger"><i class="fa fa-exclamation-triangle"></i>&nbsp;{$message}</span>
    <a class="exm-filter-chip exm-chart-detail-back" href="{$back}"><i class="fa fa-arrow-left"></i>&nbsp;{$backLabel}</a>
</div>
HTML;
        }

        $title = e(exmtrans('dashboard.chart_detail.title', $this->detail->title()));
        $chips = '';
        foreach ($this->detail->captions() as $caption) {
            $label = e($caption['label']);
            $value = e(mb_strimwidth($caption['value'], 0, 60, '…'));
            $chips .= <<<HTML
<span class="exm-filter-chip exm-chart-detail-chip">
    <span class="exm-filter-chip-key">{$label}</span>
    <span class="exm-filter-chip-value">{$value}</span>
</span>
HTML;
        }
        $clear = e($this->detail->clearUrl());
        $clearLabel = e(exmtrans('dashboard.chart_detail.clear'));

        return <<<HTML
<div class="exm-filter-chips exm-chart-detail">
    <span class="exm-filter-chips-title"><i class="fa fa-bar-chart"></i>&nbsp;{$title}</span>
    {$chips}
    <a class="exm-filter-chip exm-filter-chip-clear" href="{$clear}">{$clearLabel}</a>
    <a class="exm-filter-chip exm-chart-detail-back" href="{$back}"><i class="fa fa-arrow-left"></i>&nbsp;{$backLabel}</a>
</div>
HTML;
    }
}
