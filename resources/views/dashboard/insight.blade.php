{{--
    🧠 AI summary strip support — CSS + fetch/render JS for the collapsible AI insight
    under each chart box. The per-box markup is _insight_strip.blade.php (included by the
    chart renderers); this file is injected ONCE per dashboard page by
    DashboardController::home and carries the shared, document-delegated handlers.

    Lazy by design: the insight is fetched only when a user first expands a strip, so a
    dashboard load never triggers an LLM call. Endpoint: POST webapi/ai-insight
    (AiChatController::insight — server-side gates: site switch, per-dashboard opt-in,
    blocked tables, rate limit).
--}}
<style>
/* ===== AI summary strip (under each chart) ===== */
.exment-chart-insight { margin-top:10px; border-top:1px dashed #e3e0f5; padding-top:6px; }
.exment-chart-insight-toggle {
    background:none; border:none; padding:4px 2px; cursor:pointer;
    color:#7c4dff; font-size:12.5px; font-weight:600; display:flex; align-items:center; gap:6px;
}
.exment-chart-insight-toggle:hover { color:#5b6ef5; }
.exment-chart-insight-caret { transition:transform .2s; font-size:12px; }
.exment-chart-insight.open .exment-chart-insight-caret { transform:rotate(180deg); }
.exment-chart-insight-panel { padding:4px 2px 2px; font-size:13px; color:#2b2b3a; }
.exment-chart-insight-loading { color:#8a8aa0; padding:8px 2px; font-size:12.5px; }
.exment-chart-insight-text { margin:2px 2px 9px; color:#444; line-height:1.68; font-size:13px; }
.exment-chart-insight-text:last-child { margin-bottom:2px; }
/* Key-figures strip: scannable stat tiles above the insight prose. Responsive — 2 up on a
   narrow chart box, up to 4 up when the box is wide. */
.exment-chart-insight-stats {
    display:grid; grid-template-columns:repeat(auto-fit, minmax(116px, 1fr));
    gap:8px; margin:4px 2px 13px;
}
.exment-insight-stat {
    background:#faf9ff; border:1px solid #ece7fb; border-radius:9px;
    padding:8px 11px; display:flex; flex-direction:column; gap:2px; min-width:0;
}
.exment-insight-stat-label {
    font-size:11px; color:#9a9ab0; font-weight:600;
    display:flex; align-items:center; gap:5px; line-height:1.2;
}
.exment-insight-stat-label .fa { font-size:10px; color:#7c4dff; }
.exment-insight-stat-value { font-size:16px; font-weight:600; color:#2b2b3a; line-height:1.25; }
.exment-insight-stat-sub {
    font-size:11.5px; color:#7a7a90; line-height:1.3;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
/* Numbers emphasised inside the flowing insight prose. */
.exment-insight-num { color:#5b4bd6; font-weight:600; }
/* Anomaly callout — amber accent (attention, not "bad"; never red). Points that fall
   outside the deterministic IQR expected range. */
.exment-chart-insight-anomalies {
    margin:8px 0 4px; padding:8px 10px; background:#fff8ec;
    border:1px solid #f4e2bd; border-radius:7px;
}
.exment-insight-anomaly-head {
    display:flex; align-items:center; flex-wrap:wrap; gap:6px;
    font-size:12.5px; font-weight:600; color:#9a6a12;
}
.exment-insight-anomaly-head .fa-exclamation-triangle { color:#e0a020; }
.exment-insight-anomaly-count {
    display:inline-block; min-width:17px; padding:0 5px; border-radius:9px;
    background:#e0a020; color:#fff; font-size:11px; text-align:center; line-height:17px;
}
.exment-insight-anomaly-range { margin-left:auto; font-weight:400; font-size:11px; color:#a98a4a; white-space:nowrap; }
.exment-insight-anomaly-list { list-style:none; margin:7px 0 0; padding:0; }
.exment-insight-anomaly {
    display:flex; align-items:center; gap:7px; padding:3px 0; font-size:12.5px; color:#5a4a20;
}
.exment-insight-anomaly + .exment-insight-anomaly { border-top:1px solid #f4e8cf; }
.exment-insight-anomaly-icon { width:14px; text-align:center; color:#c98a1a; }
.exment-insight-anomaly-label { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.exment-insight-anomaly-value { font-weight:600; color:#2b2b3a; }
/* "Stable" note when the IQR check ran but found no outlier. */
.exment-chart-insight-stable {
    margin:8px 0 4px; padding:6px 10px; font-size:12px; color:#5a8a5a;
    background:#f2faf2; border:1px solid #d8ecd8; border-radius:7px;
}
.exment-chart-insight-stable .fa-check-circle { color:#67aa67; margin-right:3px; }
.exment-chart-insight-stable .exment-insight-anomaly-range { margin-left:0; color:#8aad8a; }
.exment-chart-insight-meta {
    display:flex; align-items:center; justify-content:space-between;
    margin-top:8px; padding-top:6px; border-top:1px dashed #e3e0f5; font-size:11.5px; color:#9a9ab0;
}
.exment-chart-insight-meta .fa-magic { color:#7c4dff; }
.exment-chart-insight-error { padding:10px; background:#faf9ff; border-radius:6px; color:#8a8aa0; font-size:12.5px; }
.exment-chart-insight-error .fa-info-circle { color:#b0a8e0; margin-right:4px; }
.exment-chart-insight-error a { color:#7c4dff; cursor:pointer; margin-left:6px; }
</style>

<script>
(function ($) {
    {{-- i18n — built server-side in a PHP block, json_encode'd with hex flags so the
         characters survive Exment's pjax HTML-decode. --}}
    @php
        $aiLang = [
            'error_generic'          => exmtrans('dashboard.ai_chat.error_generic'),
            'error_connect'          => exmtrans('dashboard.ai_chat.error_connect'),
            'insight_generating'     => exmtrans('dashboard.ai_insight.generating'),
            'insight_regenerate'     => exmtrans('dashboard.ai_insight.regenerate'),
            'insight_stat_highest'   => exmtrans('dashboard.ai_insight.stat_highest'),
            'insight_stat_lowest'    => exmtrans('dashboard.ai_insight.stat_lowest'),
            'insight_stat_average'   => exmtrans('dashboard.ai_insight.stat_average'),
            'insight_stat_range'     => exmtrans('dashboard.ai_insight.stat_range'),
            'insight_anomaly_title'  => exmtrans('dashboard.ai_insight.anomaly_title'),
            'insight_expected_range' => exmtrans('dashboard.ai_insight.expected_range'),
            'insight_stable'         => exmtrans('dashboard.ai_insight.stable'),
        ];
    @endphp
    var LANG = {!! json_encode($aiLang, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};

    var csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

    /* Exment re-injects this widget on every pjax reload, which re-runs this script.
       Document-delegated handlers would otherwise stack up and fire N times — strip any
       handler bound by a previous run before re-binding, and namespace every binding. */
    var NS = '.exmentInsight';
    $(document).off(NS);

    /* Dashboard filter: the URL carries df_{column} params when the filter bar is active. Fold
       them into the request payload so the insight analyses EXACTLY the rows the (filtered)
       chart shows. Column-agnostic; empty object when no filter is active. */
    function dfParams() {
        // The payload is JSON, so PHP's query-string array syntax (df_col[]=…, df_col[from]=…,
        // dfa[0][c]=…) must be rebuilt as real nested arrays/objects here — a literal key
        // "df_col[]" would reach request()->all() unparsed and filter nothing.
        var p = new URLSearchParams(window.location.search), out = {};
        p.forEach(function (v, k) {
            if (!(k.indexOf('df_') === 0 || k.indexOf('dfa[') === 0) || !v) { return; }
            var m = k.match(/^([^\[]+)((?:\[[^\]]*\])*)$/);
            if (!m) { return; }
            var path = [m[1]].concat((m[2].match(/\[[^\]]*\]/g) || []).map(function (s) { return s.slice(1, -1); }));
            var node = out;
            for (var i = 0; i < path.length; i++) {
                var last = (i === path.length - 1), key = path[i];
                if (key === '') {                       // [] → push
                    if (!Array.isArray(node)) { return; }
                    if (last) { node.push(v); } else { var n = {}; node.push(n); node = n; }
                    continue;
                }
                if (last) { node[key] = v; continue; }
                var nextIsList = (path[i + 1] === '');
                if (node[key] === undefined || typeof node[key] !== 'object') { node[key] = nextIsList ? [] : {}; }
                node = node[key];
            }
        });
        return out;
    }

    /* Chart-level filter (bf_{column}): lives on the box's AJAX request only, never in the
       URL — read the current selection off the box's own filter fields (the re-rendered
       body carries the selected state), so the insight analyses the SAME narrowed rows the
       chart is showing. Empty object when the box has no chart-level filter. Same value
       shapes as the box AJAX: one value / a list / {from, to}. */
    function bfParams($wrap) {
        var out = {};
        var $box = $wrap.closest('[data-suuid]');
        $box.find('.exment-bf-list').each(function () {
            var col = $(this).data('column'), vals = [];
            $(this).find('.exment-bf-check:checked').each(function () { vals.push(String($(this).val())); });
            if (col && vals.length) { out['bf_' + col] = vals.length === 1 ? vals[0] : vals; }
        });
        $box.find('.exment-bf-range').each(function () {
            var col = $(this).data('column'), bound = $(this).data('bound'), v = String($(this).val() || '').trim();
            if (!col || !v) { return; }
            if (!out['bf_' + col] || typeof out['bf_' + col] !== 'object' || Array.isArray(out['bf_' + col])) { out['bf_' + col] = {}; }
            out['bf_' + col][bound] = v;
        });
        return out;
    }

    /* Lazy + collapsible: fetch only on first expand; server cache keeps later expands free. */
    $(document).on('click' + NS, '[data-insight-toggle]', function () {
        var $wrap  = $(this).closest('[data-chart-insight]');
        var $panel = $wrap.find('[data-insight-panel]');
        var open   = $(this).attr('aria-expanded') === 'true';
        if (open) {
            $panel.attr('hidden', true);
            $(this).attr('aria-expanded', 'false');
            $wrap.removeClass('open');
            toggleChartMarkers($wrap, false);   // hide the amber markers on the chart above
            return;
        }
        $panel.removeAttr('hidden');
        $(this).attr('aria-expanded', 'true');
        $wrap.addClass('open');
        toggleChartMarkers($wrap, true);        // reveal anomaly markers together with the insight
        if (!$wrap.data('loaded')) { fetchInsight($wrap); }
    });

    // Opt-in anomaly markers on the chart: each chart renderer registers a per-suuid toggle in
    // window.ExmentChartMarkers; expanding/collapsing the AI summary strip flips it. No-op when
    // the chart has no outlier (nothing registered) or the type isn't a value-axis chart.
    function toggleChartMarkers($wrap, on) {
        var suuid = ($wrap.closest('[data-suuid]').data('suuid') || '').toString();
        var reg = window.ExmentChartMarkers || {};
        if (suuid && typeof reg[suuid] === 'function') {
            // Never let a stale toggle (chart destroyed by a box reload between registration
            // and this click) throw — drop the dead entry instead.
            try { reg[suuid](on); }
            catch (e) { delete reg[suuid]; }
        }
    }

    // Retry after a FAILED generation — the link lives inside insightError() only (a failed
    // generation is never cached, so a plain re-fetch regenerates).
    $(document).on('click' + NS, '[data-insight-regen]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        fetchInsight($(this).closest('[data-chart-insight]'));
    });

    function fetchInsight($wrap) {
        var suuid = ($wrap.closest('[data-suuid]').data('suuid') || '').toString();
        if (!suuid) { return; }
        // In-flight guard: a collapse+re-expand would otherwise fire concurrent LLM calls.
        if ($wrap.data('insightLoading')) { return; }
        $wrap.data('insightLoading', true);
        var $panel = $wrap.find('[data-insight-panel]');
        $panel.html('<div class="exment-chart-insight-loading"><i class="fa fa-circle-o-notch fa-spin"></i> ' +
            escHtml(LANG.insight_generating) + '</div>');

        $.ajax({
            url:         admin_url('webapi/ai-insight'),
            type:        'POST',
            headers:     { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(Object.assign({ suuid: suuid }, dfParams(), bfParams($wrap))),
            success: function (res) {
                $wrap.data('insightLoading', false);
                $wrap.data('loaded', true);
                if (res && res.success && res.insight) {
                    $panel.html(renderInsight(res));
                } else {
                    $panel.html(insightError((res && res.message) || LANG.error_generic));
                }
            },
            error: function (xhr) {
                $wrap.data('insightLoading', false);
                var msg = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message : LANG.error_connect;
                $panel.html(insightError(msg));
            }
        });
    }

    // Strip markdown emphasis a model sometimes adds despite the prompt (**bold**, *italic*,
    // `code`, leading # heading), so it never shows as literal asterisks in a bullet.
    function stripMd(s) {
        return String(s)
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            .replace(/__([^_]+)__/g, '$1')
            .replace(/(^|\s)\*([^*]+)\*/g, '$1$2')
            .replace(/`([^`]+)`/g, '$1')
            .replace(/^#{1,6}\s*/, '')
            .trim();
    }

    // The LLM returns a short prose paragraph. Render as: key-figures tiles, the anomaly
    // callout, then the prose with numbers emphasised. Be defensive: if the model still
    // emits bullet lines, strip the markers and fold them into paragraphs.
    function renderInsight(res) {
        var BULLET = /^\s*(?:[•\-*·–—]|\d+[.)])\s+/;
        var paras = [];
        String(res.insight).split(/\n\s*\n/).forEach(function (block) {
            var text = block.split(/\r\n|\r|\n/).map(function (raw) {
                return stripMd(raw.replace(BULLET, '').trim());
            }).filter(Boolean).join(' ');
            if (text) { paras.push(text); }
        });
        if (!paras.length) {
            var whole = stripMd(String(res.insight).replace(/\s+/g, ' '));
            if (whole) { paras.push(whole); }
        }

        var statsHtml = renderInsightStats(res.stats);
        var anomaliesHtml = renderInsightAnomalies(res.anomalies);
        var body = paras.map(function (t) {
            return '<p class="exment-chart-insight-text">' + highlightNums(escHtml(t)) + '</p>';
        }).join('');
        var meta = '<div class="exment-chart-insight-meta">' +
            '<span><i class="fa fa-magic"></i> AI' + (res.generated_at ? ' · ' + escHtml(res.generated_at) : '') + '</span>' +
            '</div>';

        return '<div class="exment-chart-insight-content">' + statsHtml + anomaliesHtml + body + '</div>' + meta;
    }

    // Anomaly callout from the server-computed (deterministic IQR) anomalies object. Three
    // states: null → nothing; 0 points → subtle "stable" note with the expected range;
    // 1+ outliers → amber list (amber, never red: attention, not necessarily "bad").
    function renderInsightAnomalies(a) {
        if (!a) { return ''; }

        var range = (a.lower !== undefined && a.upper !== undefined)
            ? escHtml(LANG.insight_expected_range) + ': ' + escHtml(fmtNum(a.lower)) + ' – ' + escHtml(fmtNum(a.upper))
            : '';

        if (!a.points || !a.points.length) {
            return '<div class="exment-chart-insight-stable">' +
                '<i class="fa fa-check-circle"></i> ' + escHtml(LANG.insight_stable) +
                (range ? ' <span class="exment-insight-anomaly-range">(' + range + ')</span>' : '') +
                '</div>';
        }

        var items = a.points.map(function (p) {
            var high = p.direction === 'high';
            var icon = high ? '<i class="fa fa-arrow-up"></i>' : '<i class="fa fa-arrow-down"></i>';
            return '<li class="exment-insight-anomaly exment-insight-anomaly-' + (high ? 'high' : 'low') + '">' +
                '<span class="exment-insight-anomaly-icon">' + icon + '</span>' +
                '<span class="exment-insight-anomaly-label" title="' + escAttr(p.label) + '">' + escHtml(p.label) + '</span>' +
                '<span class="exment-insight-anomaly-value">' + escHtml(fmtNum(p.value)) + '</span>' +
                '</li>';
        }).join('');

        return '<div class="exment-chart-insight-anomalies">' +
            '<div class="exment-insight-anomaly-head">' +
            '<i class="fa fa-exclamation-triangle"></i> ' + escHtml(LANG.insight_anomaly_title) +
            ' <span class="exment-insight-anomaly-count">' + a.points.length + '</span>' +
            (range ? '<span class="exment-insight-anomaly-range">' + range + '</span>' : '') +
            '</div>' +
            '<ul class="exment-insight-anomaly-list">' + items + '</ul>' +
            '</div>';
    }

    // "Key figures" tile strip from the server-computed stats. '' when stats is null.
    // Highest/lowest use a neutral accent arrow (rank is NOT good/bad — never green/red).
    function renderInsightStats(stats) {
        if (!stats) { return ''; }
        function tile(icon, label, value, sub) {
            return '<div class="exment-insight-stat">' +
                '<div class="exment-insight-stat-label">' + icon + escHtml(label) + '</div>' +
                '<div class="exment-insight-stat-value">' + escHtml(value) + '</div>' +
                (sub ? '<div class="exment-insight-stat-sub" title="' + escAttr(sub) + '">' + escHtml(sub) + '</div>'
                     : '<div class="exment-insight-stat-sub">&nbsp;</div>') +
                '</div>';
        }
        var up   = '<i class="fa fa-arrow-up"></i>';
        var down = '<i class="fa fa-arrow-down"></i>';
        var hi = stats.highest || {}, lo = stats.lowest || {};
        var tiles =
            tile(up,   LANG.insight_stat_highest, fmtNum(hi.value), hi.label) +
            tile(down, LANG.insight_stat_lowest,  fmtNum(lo.value), lo.label) +
            tile('',   LANG.insight_stat_average, fmtNum(stats.average)) +
            tile('',   LANG.insight_stat_range,   fmtNum(stats.range));
        return '<div class="exment-chart-insight-stats">' + tiles + '</div>';
    }

    // Compact, locale-grouped number for the stat tiles (1,284 / 12.9K / 3.4M).
    function fmtNum(v) {
        if (v === null || v === undefined || v === '' || isNaN(v)) { return '–'; }
        var n = Number(v), abs = Math.abs(n);
        function trim(x) { return String(Math.round(x * 10) / 10); }
        if (abs >= 1e9) { return trim(n / 1e9) + 'B'; }
        if (abs >= 1e6) { return trim(n / 1e6) + 'M'; }
        if (abs >= 1e5) { return trim(n / 1e3) + 'K'; }
        var rounded = Math.round(n * 10) / 10;
        try { return rounded.toLocaleString(undefined, { maximumFractionDigits: 1 }); }
        catch (e) { return String(rounded); }
    }

    // Emphasise numeric tokens inside already-escaped insight text so figures pop out of
    // the prose. Safe on escaped html: numbers never appear inside escHtml's entities.
    function highlightNums(escaped) {
        return String(escaped).replace(/\d[\d,]*(?:\.\d+)?%?/g, '<strong class="exment-insight-num">$&</strong>');
    }

    function insightError(msg) {
        return '<div class="exment-chart-insight-error"><i class="fa fa-info-circle"></i>' + escHtml(msg) +
            '<a href="javascript:void(0)" data-insight-regen>' + escHtml(LANG.insight_regenerate) + '</a></div>';
    }

    // NOTE: entity strings are split (e.g. '&'+'amp;') on purpose. Exment's pjax
    // pipeline HTML-decodes the injected fragment, so a literal entity in the source
    // would be decoded and break this script.
    function escHtml(s) {
        return String(s).replace(/&/g,'&'+'amp;').replace(/</g,'&'+'lt;').replace(/>/g,'&'+'gt;')
                        .replace(/"/g,'&'+'quot;').replace(/\n/g,'<br>');
    }
    function escAttr(s) { return String(s).replace(/&/g, '&'+'amp;').replace(/"/g, '&'+'quot;').replace(/'/g, '&#'+'39;'); }

}(jQuery));
</script>
