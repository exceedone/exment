@php
    $boardId = 'exment-kanban-' . uniqid();
    $features = array_get($board, 'features', []);
    $lang = [
        'search' => exmtrans('custom_view.kanban_search_placeholder'),
        'keyword' => exmtrans('custom_view.kanban_keyword'),
        'all' => exmtrans('custom_view.kanban_all'),
        'none' => exmtrans('custom_view.kanban_none'),
        'groupby' => exmtrans('custom_view.kanban_groupby'),
        'swimlane' => exmtrans('custom_view.kanban_swimlane_column'),
        'only_over' => exmtrans('custom_view.kanban_only_over'),
        'only_unassigned' => exmtrans('custom_view.kanban_only_unassigned'),
        'only_mine' => exmtrans('custom_view.kanban_only_mine'),
        'only_blocked' => exmtrans('custom_view.kanban_only_blocked'),
        'only_expedite' => exmtrans('custom_view.kanban_only_expedite'),
        'blocked' => exmtrans('custom_view.kanban_blocked'),
        'expedite' => exmtrans('custom_view.kanban_expedite'),
        'blocked_partial' => exmtrans('custom_view.kanban_blocked_partial'),
        'policy_title' => exmtrans('custom_view.kanban_policy_title'),
        'collapse' => exmtrans('custom_view.kanban_collapse'),
        'expand' => exmtrans('custom_view.kanban_expand'),
        'reset' => exmtrans('custom_view.kanban_reset'),
        'no_card' => exmtrans('custom_view.kanban_no_card'),
        'quickadd' => exmtrans('custom_view.kanban_quickadd_placeholder'),
        'unassigned' => exmtrans('custom_view.kanban_unassigned'),
        'drag_hint' => exmtrans('custom_view.kanban_drag_hint'),
        'open_record' => exmtrans('custom_view.kanban_open_record'),
        'undo' => exmtrans('custom_view.kanban_undo'),
        'day' => exmtrans('custom_view.kanban_day'),
        'sla_done' => exmtrans('custom_view.kanban_sla_done'),
        'sla_over' => exmtrans('custom_view.kanban_sla_over'),
        'sla_soon' => exmtrans('custom_view.kanban_sla_soon'),
        'sla_left' => exmtrans('custom_view.kanban_sla_left'),
        'ai_recommend' => exmtrans('custom_view.kanban_ai_recommend'),
        'ai_apply' => exmtrans('custom_view.kanban_ai_apply'),
        'ai_auto' => exmtrans('custom_view.kanban_ai_auto'),
        'ai_suggest' => exmtrans('custom_view.kanban_ai_suggest'),
        'ai_result' => exmtrans('custom_view.kanban_ai_result'),
        'moved' => exmtrans('custom_view.message.kanban_moved'),
        'created' => exmtrans('custom_view.message.kanban_created'),
        'assigned' => exmtrans('custom_view.message.kanban_assigned'),
        'bulk_moved' => exmtrans('custom_view.message.kanban_bulk_moved'),
        'save_error' => exmtrans('custom_view.message.kanban_move_error'),
        'wf_action' => exmtrans('custom_view.kanban_wf_action'),
        'wf_no_action' => exmtrans('custom_view.message.kanban_wf_no_action'),
        'wf_locked' => exmtrans('custom_view.message.kanban_wf_locked'),
        'wf_none' => exmtrans('custom_view.message.kanban_wf_none'),
        'col_age' => exmtrans('custom_view.kanban_col_age_title'),
        'move_to' => exmtrans('custom_view.kanban_move_to'),
        'bulk_move' => exmtrans('custom_view.kanban_bulk_move'),
        'col_total' => exmtrans('custom_view.kanban_col_total'),
        'more' => exmtrans('custom_view.kanban_more'),
        'more_plain' => exmtrans('custom_view.kanban_more_plain'),
        'search_more' => exmtrans('custom_view.kanban_search_more'),
        'history' => exmtrans('common.workflow_history'),
        'history_none' => exmtrans('custom_view.kanban_history_none'),
        'partial' => exmtrans('custom_view.message.kanban_partial'),
        'search_capped' => exmtrans('custom_view.message.kanban_search_capped'),
        'wip_full' => exmtrans('custom_view.message.kanban_wip_full'),
        'wip_blocked' => exmtrans('custom_view.message.kanban_wip_blocked'),
    ];
@endphp
<div class="box card p-2 exment-kanban" id="{{ $boardId }}">
    <div class="box-header with-border pb-2">
        <div class="kb-toolbar">
            <div class="kb-toolbar-left">
                @if(!$error)
                <button type="button" class="btn btn-sm btn-primary kb-filter-toggle">
                    <i class="fa fa-filter"></i><span class="hidden-xs">&nbsp;&nbsp;{{ exmtrans('custom_view.kanban_filter') }}</span>
                </button>
                <span class="input-group input-group-sm kb-search-wrap">
                    <input type="text" class="form-control kb-search" placeholder="{{ exmtrans('custom_view.kanban_search_placeholder') }}">
                    <span class="input-group-btn"><button type="button" class="btn btn-default"><i class="fa fa-search"></i></button></span>
                </span>
                {{-- the filter everyone reaches for first, given a button of its
                     own so it is one tap rather than four --}}
                @if(array_get($board, 'assignee_column') && !is_nullorempty(array_get($board, 'me')))
                <button type="button" class="btn btn-sm btn-default kb-mine-btn">
                    <i class="fa fa-user"></i><span class="hidden-xs">&nbsp;&nbsp;{{ exmtrans('custom_view.kanban_mine_button') }}</span>
                </button>
                @endif
                @if(array_get($features, 'ai'))
                <button type="button" class="btn btn-sm btn-info kb-ai-btn" title="{{ exmtrans('custom_view.help.kanban_ai_column') }}">
                    <i class="fa fa-magic"></i><span class="hidden-xs">&nbsp;&nbsp;{{ exmtrans('custom_view.kanban_ai_button') }}</span>
                </button>
                @endif
                @endif
            </div>
            <div class="kb-toolbar-right">
                @foreach($tools as $tool)
                {!! $tool !!}
                @endforeach
            </div>
        </div>
    </div>

    @if($error)
    <div class="box-body">
        <div class="alert alert-warning mb-0">{!! $error !!}</div>
    </div>
    @else

    {{-- filter panel, filled in by the script --}}
    <div class="kb-filterbox" style="display:none;"></div>

    @if($over_limit)
    <div class="box-body pb-0">
        <div class="alert alert-info mb-0">{!! sprintf(exmtrans('custom_view.message.kanban_over_limit'), $max_count) !!}</div>
    </div>
    @endif

    {{-- a board loaded per column can be rearranged into a shape the server
         cannot page or total by; the script owns up to that here --}}
    <div class="kb-partial" style="display:none;"></div>

    @if(array_get($features, 'kpi'))
    <div class="kb-kpis">
        <div class="kb-kpi" data-kpi="open">
            <div class="kb-kpi-icon" style="background:#3c8dbc"><i class="fa fa-folder-open"></i></div>
            <div><div class="kb-kpi-num">0</div><div class="kb-kpi-label">{{ exmtrans('custom_view.kanban_kpi_open') }}</div></div>
        </div>
        @if(array_get($board, 'assignee_column'))
        <div class="kb-kpi" data-kpi="unassigned">
            <div class="kb-kpi-icon" style="background:#f39c12"><i class="fa fa-user-times"></i></div>
            <div><div class="kb-kpi-num">0</div><div class="kb-kpi-label">{{ exmtrans('custom_view.kanban_kpi_unassigned') }}</div></div>
        </div>
        @endif
        @if(array_get($board, 'limit_column'))
        <div class="kb-kpi" data-kpi="breach">
            <div class="kb-kpi-icon" style="background:#dd4b39"><i class="fa fa-exclamation-triangle"></i></div>
            <div><div class="kb-kpi-num">0</div><div class="kb-kpi-label">{{ exmtrans('custom_view.kanban_kpi_breach') }}</div></div>
        </div>
        @endif
        @if(array_get($board, 'age_column'))
        <div class="kb-kpi" data-kpi="age">
            <div class="kb-kpi-icon" style="background:#00c0ef"><i class="fa fa-clock-o"></i></div>
            <div><div class="kb-kpi-num">0</div><div class="kb-kpi-label">{{ exmtrans('custom_view.kanban_kpi_age') }}</div></div>
        </div>
        @endif
        @if(!is_nullorempty(array_get($board, 'blocked')))
        <div class="kb-kpi" data-kpi="blocked">
            <div class="kb-kpi-icon" style="background:#8e44ad"><i class="fa fa-hand-paper-o"></i></div>
            <div><div class="kb-kpi-num">0</div><div class="kb-kpi-label">{{ exmtrans('custom_view.kanban_kpi_blocked') }}</div></div>
        </div>
        @endif
        @if(!is_nullorempty(array_get($board, 'done_keys')))
        <div class="kb-kpi" data-kpi="done">
            <div class="kb-kpi-icon" style="background:#00a65a"><i class="fa fa-check-circle"></i></div>
            <div><div class="kb-kpi-num">0</div><div class="kb-kpi-label">{{ exmtrans('custom_view.kanban_kpi_done') }}</div></div>
        </div>
        @endif
    </div>
    @endif

    @if(array_get($features, 'bulk'))
    @php
        $group_meta = collect(array_get($board, 'groupables', []))->firstWhere('name', array_get($board, 'group_column'));
    @endphp
    <div class="kb-bulkbar" style="display:none;">
        <span class="kb-bulk-count"><i class="fa fa-check-square-o"></i>&nbsp;
            <span class="kb-sel-count">0</span>&nbsp;{{ exmtrans('custom_view.kanban_selected') }}</span>
        @if(array_get($features, 'bulk_move'))
        <select class="kb-bulk-move">
            <option value="">{{ exmtrans('custom_view.kanban_bulk_move') }}</option>
            @foreach(array_get($group_meta, 'options', []) as $option)
            <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
        @endif
        @if(!is_nullorempty(array_get($board, 'assignees')))
        <select class="kb-bulk-assign">
            <option value="">{{ exmtrans('custom_view.kanban_bulk_assign') }}</option>
            @foreach(array_get($board, 'assignees', []) as $option)
            <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
        @endif
        <button type="button" class="btn btn-sm btn-default kb-sel-clear" style="margin-left:auto;">{{ exmtrans('custom_view.kanban_sel_clear') }}</button>
    </div>
    @endif

    <div class="box-body no-padding">
        <div class="kb-scroll"><div class="kb-board"></div></div>
    </div>
    @endif
</div>

@include('exment::widgets.kanban.style')

@if(!$error)
@include('exment::widgets.kanban.script', ['boardId' => $boardId, 'board' => $board, 'lang' => $lang])
@endif
