{{--
    🧠 AI summary strip — shared by every chart-box renderer (chart.blade.php,
    echart.blade.php, echart_multi.blade.php) so ALL chart types get it, not just the
    native Chart.js ones. Included once directly under the chart canvas/container.

    Lazy + collapsed: it fetches the insight only when a user expands it, so a dashboard
    load never triggers an LLM call. It finds its own chart via the closest [data-suuid]
    ancestor — no variables required. Handlers + CSS live in dashboard/insight.blade.php.
--}}
<div class="exment-chart-insight" data-chart-insight>
    <button type="button" class="exment-chart-insight-toggle" data-insight-toggle aria-expanded="false">
        <i class="fa fa-magic"></i>
        <span class="exment-chart-insight-label">{{ exmtrans('dashboard.ai_insight.toggle') }}</span>
        <i class="fa fa-angle-down exment-chart-insight-caret"></i>
    </button>
    <div class="exment-chart-insight-panel" data-insight-panel hidden></div>
</div>
