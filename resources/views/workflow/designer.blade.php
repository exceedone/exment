{{--
    ワークフロー設定 Step2「アクション設定」のフロー プレビュー（右ペイン）。

    この blade は入れ物と初期データだけを出力する。DOM の組み立て（左テーブルを
    .wd-left へ移す・分割バーを置く）と描画は workflow_designer.js が行う。
    ステータスの完了/ロック区分は画面のどこにも出ていないため、ここから渡す。
--}}
<div class="wd-right" id="wd-right">
    <div class="wd-head">
        <b><i class="fa fa-sitemap"></i>&nbsp;{{ $wd_texts['title'] }}</b>
        <small>{{ $wd_texts['hint'] }}</small>
        <span class="wd-sp"></span>
        <span id="wd-issues" class="wd-issues"></span>
        <button type="button" id="wd-add" class="btn btn-sm btn-success">
            <i class="fa fa-plus"></i>&nbsp;{{ $wd_texts['add_action'] }}
        </button>
    </div>

    <div class="wd-canvasarea">
        <svg id="wd-svg" class="wd-svg" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="wd-dots" width="22" height="22" patternUnits="userSpaceOnUse">
                    <circle cx="1.5" cy="1.5" r="1.2" fill="#dde5ec"></circle>
                </pattern>
                <marker id="wd-arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7"
                    orient="auto-start-reverse">
                    <path d="M0,0 L10,5 L0,10 z" fill="#9ab0c4"></path>
                </marker>
                <marker id="wd-arr-sel" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7"
                    orient="auto-start-reverse">
                    <path d="M0,0 L10,5 L0,10 z" fill="#3c8dbc"></path>
                </marker>
                <marker id="wd-arr-bad" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7"
                    orient="auto-start-reverse">
                    <path d="M0,0 L10,5 L0,10 z" fill="#dd4b39"></path>
                </marker>
            </defs>
            <g id="wd-world"></g>
        </svg>

        <div class="wd-zoombar">
            <button type="button" id="wd-zin" title="{{ $wd_texts['zoom_in'] }}">＋</button>
            <button type="button" id="wd-zout" title="{{ $wd_texts['zoom_out'] }}">－</button>
            <button type="button" id="wd-fit" title="{{ $wd_texts['zoom_fit'] }}">⤢</button>
            <button type="button" id="wd-relayout" title="{{ $wd_texts['auto_layout'] }}">
                <i class="fa fa-magic"></i>
            </button>
            <span class="wd-zsep"></span>
            {{-- 配置を保存するかの切り替え。押した状態を workflow_designer.js が
                 hidden（options[designer_layout]）へ書き、画面の「保存」で DB に入る。 --}}
            <button type="button" id="wd-savepos" class="wd-pin" aria-pressed="false"
                aria-label="{{ $wd_texts['save_pos'] }}" title="{{ $wd_texts['save_pos_off'] }}">
                <i class="fa fa-thumb-tack"></i>
            </button>
        </div>

        <div class="wd-legend">
            <span class="wd-sw" style="background:#3c8dbc"></span>{{ $wd_texts['legend_status'] }}&nbsp;&nbsp;
            <span class="wd-sw" style="background:#00a65a"></span>{{ $wd_texts['legend_completed'] }}<br>
            <span class="wd-ln"></span>{{ $wd_texts['legend_action'] }}&nbsp;&nbsp;
            <span class="wd-ln wd-dash"></span>{{ $wd_texts['legend_ignore'] }}<br>
            <span class="wd-br">◆n</span>{{ $wd_texts['legend_branch'] }}&nbsp;／
            <span class="wd-cn">※</span>{{ $wd_texts['legend_cond'] }}
        </div>

        <div class="wd-issuepop" id="wd-issuepop">
            <div class="wd-tit"></div>
            <div class="wd-issuelist"></div>
        </div>
    </div>
</div>

<button type="button" class="btn btn-xs btn-default wd-toggle" id="wd-toggle">{{ $wd_texts['hide'] }}</button>

<script>
    $(function () {
        if (typeof Exment === 'undefined' || !Exment.WorkflowDesigner) {
            return;     // workflow_designer.js が未配置（vendor:publish 忘れ）でも画面は動かす
        }
        Exment.WorkflowDesigner.boot({
            statuses: {!! json_encode($wd_statuses, JSON_UNESCAPED_UNICODE) !!},
            start_name: {!! json_encode($wd_start_name, JSON_UNESCAPED_UNICODE) !!},
            texts: {!! json_encode($wd_texts, JSON_UNESCAPED_UNICODE) !!}
        });
    });
</script>
