{{--
    ワークフロー デザイナー（フロー図を直接編集する画面）。

    この blade は入れ物と初期データだけを出力する。描画・編集・保存の呼び出しは
    workflow_design_edit.js が行う。保存先はステップ1・ステップ2と同じテーブル。
--}}
@php
    $t = $design_data['texts'];
    $tx = function ($key, $default = '') use ($t) {
        return array_get($t, $key, $default !== '' ? $default : $key);
    };
@endphp

<div class="col-md-12">
    <div id="wfd-app" class="wfd-app">
        <div class="wfd-bar">
            <a class="btn btn-sm btn-default" href="{{ $design_data['urls']['list'] }}">
                <i class="fa fa-list"></i>&nbsp;{{ $tx('to_list') }}
            </a>
            {{-- 新規作成中はまだワークフローが無いので、ステップ1・2 も開けない。 --}}
            @if($design_data['is_new'])
                <span class="btn btn-sm btn-default wfd-off" title="{{ $tx('step_new') }}">1.&nbsp;{{ $tx('label_status_name') }}</span>
                <span class="btn btn-sm btn-default wfd-off" title="{{ $tx('step_new') }}">2.&nbsp;{{ $tx('label_action_name') }}</span>
            @else
                <a class="btn btn-sm btn-default" href="{{ $design_data['urls']['step1'] }}" title="{{ $tx('to_step1') }}">1.&nbsp;{{ $tx('label_status_name') }}</a>
                <a class="btn btn-sm btn-default" href="{{ $design_data['urls']['step2'] }}" title="{{ $tx('to_step2') }}">2.&nbsp;{{ $tx('label_action_name') }}</a>
            @endif

            {{-- 「設定完了する」は番号を付けない実行ボタン。通知設定の画面が出している
                 ステップ表示（1 ステータス / 2 アクション / 3 通知 / 4 利用設定）に合わせ、
                 番号は通知設定の側に付ける。設定完了するとこのボタンは消え、
                 同じ場所に「3. 通知設定」だけが残る。 --}}
            @if($design_data['can_activate'])
                <a class="btn btn-sm btn-success" id="wfd-activate" href="javascript:void(0);" title="{{ $tx('activate_hint') }}"
                    data-widgetmodal_url="{{ $design_data['urls']['step3'] }}"
                    data-widgetmodal_method="GET">{{ $tx('label_setting_complete') }}</a>
            @else
                <span class="btn btn-sm btn-default wfd-off{{ $design_data['activated'] ? ' wfd-hide' : '' }}" id="wfd-activate"
                    title="{{ $tx('activate_wait') }}">{{ $tx('label_setting_complete') }}</span>
            @endif

            {{-- ステップ3（通知設定）は設定完了後に開ける。一覧のベルと同じ画面。 --}}
            @if($design_data['activated'])
                <a class="btn btn-sm btn-default" id="wfd-step3"
                    href="{{ $design_data['urls']['notify'] }}" title="{{ $tx('to_step3') }}">3.&nbsp;{{ $tx('label_notify') }}</a>
            @else
                <span class="btn btn-sm btn-default wfd-off" id="wfd-step3"
                    title="{{ $tx('step3_wait') }}">3.&nbsp;{{ $tx('label_notify') }}</span>
            @endif

            {{-- ステップ4（利用設定）は設定完了後だけ意味があるので、それまでは押せない見た目にする。 --}}
            @if($design_data['activated'])
                <a class="btn btn-sm btn-default" id="wfd-step4" href="{{ $design_data['urls']['step4'] }}" title="{{ $tx('to_step4') }}">4.&nbsp;{{ $tx('label_beginning') }}</a>
            @else
                <span class="btn btn-sm btn-default wfd-off" id="wfd-step4" title="{{ $tx('step4_wait') }}">4.&nbsp;{{ $tx('label_beginning') }}</span>
            @endif

            <span class="wfd-name" id="wfd-name" title="{{ $tx('label_workflow_view_name') }}"></span>
            <span class="wfd-dirty" id="wfd-dirty">{{ $tx('unsaved') }}</span>

            <span class="wfd-sp"></span>

            <button type="button" class="wfd-issues" id="wfd-issues"></button>
            <button type="button" class="btn btn-sm btn-default" id="wfd-relayout" title="{{ $tx('auto_layout') }}">
                <i class="fa fa-magic"></i>
            </button>
            <button type="button" class="btn btn-sm btn-default" id="wfd-fit" title="{{ $tx('zoom_fit') }}">⤢</button>
            <button type="button" class="btn btn-sm btn-default" id="wfd-zout" title="{{ $tx('zoom_out') }}">－</button>
            <span class="wfd-zoom" id="wfd-zoomlv">100%</span>
            <button type="button" class="btn btn-sm btn-default" id="wfd-zin" title="{{ $tx('zoom_in') }}">＋</button>
            <button type="button" class="btn btn-sm btn-primary" id="wfd-save">
                <i class="fa fa-save"></i>&nbsp;{{ $tx('label_save') }}
            </button>
        </div>

        <div class="wfd-hint" id="wfd-hint">
            <span><i class="fa fa-mouse-pointer"></i>&nbsp;{{ $tx('hint_add') }}</span>
            <span><i class="fa fa-share"></i>&nbsp;{{ $tx('hint_connect') }}</span>
            <span><i class="fa fa-cog"></i>&nbsp;{{ $tx('hint_action') }}</span>
            <button type="button" class="x" id="wfd-hint-x" title="{{ $tx('hint_close') }}">×</button>
        </div>

        <div class="wfd-notice" id="wfd-notice" style="display:none"></div>

        <div id="wfd-canvas">
            <div id="wfd-world">
                <svg id="wfd-svg" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <marker id="wfd-arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7.5" markerHeight="7.5"
                            orient="auto-start-reverse">
                            <path d="M0,0 L10,5 L0,10 z" fill="#9ab0c4"></path>
                        </marker>
                        <marker id="wfd-arr-sel" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7.5" markerHeight="7.5"
                            orient="auto-start-reverse">
                            <path d="M0,0 L10,5 L0,10 z" fill="#3c8dbc"></path>
                        </marker>
                        <marker id="wfd-arr-new" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7.5" markerHeight="7.5"
                            orient="auto-start-reverse">
                            <path d="M0,0 L10,5 L0,10 z" fill="#00a65a"></path>
                        </marker>
                    </defs>
                    <g id="wfd-edges"></g>
                    <g id="wfd-ovl"></g>
                </svg>
                <div id="wfd-labels"></div>
                <div id="wfd-nodes"></div>
            </div>

            <div class="wfd-legend">
                <span class="sw" style="background:#3a4750"></span>{{ $tx('label_start_status_name') }}&nbsp;&nbsp;
                <span class="sw" style="background:#3c8dbc"></span>{{ $tx('legend_status') }}&nbsp;&nbsp;
                <span class="sw" style="background:#00a65a"></span>{{ $tx('legend_completed') }}<br>
                <span class="ln"></span>{{ $tx('legend_action') }}&nbsp;&nbsp;
                <span class="ln dash"></span>{{ $tx('legend_ignore') }}
            </div>

            <div id="wfd-ipop">
                <div class="tit"></div>
                <div class="lst"></div>
            </div>
        </div>
    </div>
</div>

<div id="wfd-menu"></div>
<div id="wfd-modal"></div>
<div id="wfd-toast"></div>

<script>
    $(function () {
        if (typeof Exment === 'undefined' || !Exment.WorkflowDesignEdit) {
            return;     // workflow_design_edit.js が未配置（vendor:publish 忘れ）でも画面は落とさない
        }
        Exment.WorkflowDesignEdit.boot({!! json_encode($design_data, JSON_UNESCAPED_UNICODE) !!});
    });
</script>
