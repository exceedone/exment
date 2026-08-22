{{-- AI summary strip under a chart: collapsed, fetched only when expanded (dashboard.js). --}}
<div class="exment-ai-summary" data-ai-summary>
    <button type="button" class="exment-ai-toggle" data-ai-toggle aria-expanded="false">
        <i class="fa fa-magic"></i>
        <span>{{ exmtrans('dashboard.ai.toggle') }}</span>
        <i class="fa fa-angle-down exment-ai-caret"></i>
    </button>
    <div class="exment-ai-panel" data-ai-panel hidden></div>
</div>
