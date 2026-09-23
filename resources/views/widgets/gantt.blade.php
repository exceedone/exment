@php
    $chartId = 'exment-gantt-' . uniqid();
    $lang = [
        'today' => exmtrans('custom_view.gantt_today'),
        'due' => exmtrans('custom_view.gantt_due'),
        'progress' => exmtrans('custom_view.gantt_progress'),
        'no_bar' => exmtrans('custom_view.gantt_no_bar'),
        'empty' => exmtrans('custom_view.gantt_empty'),
        'collapse' => exmtrans('custom_view.kanban_collapse'),
        'expand' => exmtrans('custom_view.kanban_expand'),
    ];
@endphp
<div class="box card p-2 exment-gantt" id="{{ $chartId }}">
    @if(!empty($tools) || !$error)
    <div class="box-header with-border pb-2">
        <div class="gt-toolbar">
            <div class="gt-toolbar-left">
                @if(!$error)
                <span class="gt-legend"></span>
                @endif
            </div>
            <div class="gt-toolbar-right">
                @if(empty($embed))
                @foreach($tools as $tool)
                {!! $tool !!}
                @endforeach
                @endif
            </div>
        </div>
    </div>
    @endif

    @if($error)
    <div class="box-body">
        <div class="alert alert-warning mb-0">{!! $error !!}</div>
    </div>
    @else

    @if($over_limit)
    <div class="box-body pb-0">
        <div class="alert alert-info mb-0">{!! sprintf(exmtrans('custom_view.message.gantt_over_limit'), $max_count) !!}</div>
    </div>
    @endif

    <div class="box-body pt-2">
        <div class="gt-scroll">
            <div class="gt-inner"></div>
        </div>
    </div>

    <script type="application/json" id="{{ $chartId }}-data">@json($chart)</script>
    @endif
</div>

@include('exment::widgets.gantt.style')
@if(!$error)
@include('exment::widgets.gantt.script', ['chartId' => $chartId, 'lang' => $lang])
@endif
