{{--
    Dashboard FILTER BAR — a generic per-dashboard "global filter" (Exment has none natively).

    Fully config-driven: nothing here knows about any specific domain. A dashboard opts in via
    options.filter_bar (source_table + an ordered list of dims); DashboardController::
    buildDashboardFilterContext turns that into the $dims below. Changing a select reloads the
    dashboard with df_{column} query params; the box-loading JS forwards them to every box's AJAX
    request, and ChartItem::applyDashboardFilter ANDs an equality where onto each box whose table
    has the matching column. Cascade is rendered SERVER-SIDE (each dim's options already scoped to
    the selected ancestors), so the JS only has to navigate.

    RELEVANT-VALUES cascade (Power-BI-slicer style): every dim is selectable from the start, its
    options scoped to the currently-selected ancestors — the user can jump straight to any level.
    NARROWING a dim (a value replaced / removed) resets everything cascading under it (the old
    child selection may not exist under the new parent); WIDENING it (a value added) or CLEARING
    it (×) keeps the descendants (a wider scope never invalidates them). A dim over the
    cardinality cap (options.filter_bar.max_options, default 500 — e.g. the student dim near the
    top of the tree) renders disabled with a "narrow a higher filter first" tooltip until the
    selection narrows it enough.

    MULTI-SELECT + RANGE (the Power BI slicer pair): a select dim is a select2 MULTI-select —
    several values are picked while the dropdown is open and applied once when it closes
    (df_{column}=v for one value, df_{column}[]=v… for several); a number / date dim renders as
    a from / to RANGE (df_{column}[from]=… / [to]=…). Both shapes travel to every box through
    FilterState (IN / BETWEEN), so the boxes, the badge and the cascade all agree.

    Optional second group: a dim explicitly flagged `advanced` in the config (admin switch) moves
    to a collapsible ADVANCED row behind a header toggle; the row opens itself whenever one of its
    dims is filtered. With nothing flagged (the default) the bar is one flat row — no toggle.

    Vars:
        $dims            ordered [ ['column'=>, 'label'=>, 'options'=>[['id'=>,'name'=>]],
                                    'selected'=>?id, 'selected_values'=>[id…], 'style'=>'select'|'range',
                                    'kind'=>'number'|'date'|'datetime'|'text', 'range'=>['from'=>,'to'=>],
                                    'enabled'=>bool, 'capped'=>bool, 'advanced'=>bool], ... ]
        $has_selection   bool — any dim currently filtered (controls the reset button)
        $advanced_count  int  — advanced dims currently filtered (toggle badge)
        $advanced_open   bool — advanced row initially expanded
        $dashboard_suuid current dashboard suuid (kept across reloads)
--}}
<style>
/* Pane layout: row 1 = title + actions, row 2 = hierarchy fields, row 3 (collapsible) = advanced. */
.df-bar {
    display:flex; flex-direction:column; gap:9px;
    margin:-5px 0 10px; padding:10px 14px; background:#fff;
    border:1px solid #ecebf5; border-radius:8px;
}
.df-head { display:flex; align-items:center; gap:8px; }
.df-head-actions { margin-left:auto; display:flex; gap:8px; }
.df-fields { display:flex; flex-wrap:wrap; gap:10px 14px; }
.df-bar-title { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:#5b6ef5; }
.df-bar-title .fa { color:#7c4dff; }
.df-field { display:flex; flex-direction:column; gap:3px; width:190px; min-width:150px; }
.df-field label { font-size:11px; color:#9a9ab0; font-weight:600; }
.df-field select {
    height:32px; border:1px solid #d7d5e6; border-radius:6px; padding:0 8px;
    font-size:13px; background:#fbfaff; color:#2b2b3a; min-width:150px; max-width:230px;
}
.df-field select:disabled { background:#f3f2f7; color:#b6b6c8; cursor:not-allowed; }
/* select2 multi: only the box itself is aligned to the bar's 32px rhythm / rounded corners;
   the chips keep Exment's default AdminLTE skin (blue chip, white text and ×) untouched. */
.df-field .select2-container--default .select2-selection--multiple {
    min-height:32px; border:1px solid #d7d5e6; border-radius:6px; background:#fbfaff; padding:0 4px;
}
.df-field .select2-container--default .select2-search--inline .select2-search__field { margin-top:5px; font-size:13px; }
.df-field .select2-container--default.select2-container--disabled .select2-selection--multiple { background:#f3f2f7; cursor:not-allowed; }
/* range dim: from – to inputs on one row */
.df-field-range { width:230px; }
.df-range { display:flex; align-items:center; gap:4px; }
.df-range-input {
    flex:1 1 0; min-width:0; height:32px; border:1px solid #d7d5e6; border-radius:6px; padding:0 8px;
    font-size:13px; background:#fbfaff; color:#2b2b3a;
}
.df-range-input:disabled { background:#f3f2f7; color:#b6b6c8; cursor:not-allowed; }
.df-range-input.active { border-color:#5b6ef5; background:#eef1fd; }
.df-range-sep { color:#b6b6c8; font-size:12px; }
/* Advanced (cross-cut) group: collapsible last row behind a header toggle, Power-BI pane style. */
.df-adv { display:flex; flex-direction:column; gap:11px; padding-top:9px; border-top:1px dashed #e3e1f0; }
.df-adv-toggle {
    height:28px; border:1px solid #d7d5e6; border-radius:6px;
    background:#fff; color:#7a7a90; font-size:12px; padding:0 10px; cursor:pointer;
}
.df-adv-toggle:hover { background:#f6f4ff; color:#5b4bd6; border-color:#c9c6e6; }
.df-adv-toggle.open { background:#f6f4ff; color:#5b4bd6; border-color:#c9c6e6; }
.df-adv-count {
    display:inline-block; min-width:16px; text-align:center; margin-left:5px;
    background:#5b6ef5; color:#fff; border-radius:9px; font-size:10px; padding:1px 5px;
}
.df-adv-dims { display:flex; flex-wrap:wrap; gap:10px 14px; }
/* Detailed Filter: the condition builder (field / operator / value), Power-BI style. */
.df-cond { display:flex; flex-direction:column; gap:7px; }
.df-cond-title { font-size:12px; font-weight:600; color:#5b6ef5; }
.df-cond-rows { display:flex; flex-direction:column; gap:6px; }
.df-cond-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:8px; }
.df-cond-row label { display:flex; flex-direction:column; gap:3px; font-size:11px; color:#9a9ab0; font-weight:600; margin:0; }
.df-cond-row select, .df-cond-row input[type="text"] {
    height:30px; border:1px solid #d7d5e6; border-radius:6px; padding:0 8px;
    font-size:13px; background:#fbfaff; color:#2b2b3a; min-width:150px;
}
.df-cond-row input[type="text"]:disabled { background:#f3f2f7; color:#b6b6c8; }
.df-cond-del {
    height:30px; width:30px; border:1px solid #eceaf6; border-radius:6px;
    background:#fff; color:#b6b6c8; cursor:pointer;
}
.df-cond-del:hover { color:#d9534f; border-color:#f0c9c7; }
.df-cond-actions { display:flex; gap:8px; }
.df-cond-add, .df-cond-apply, .df-cond-clear {
    height:30px; border:1px solid #d7d5e6; border-radius:6px;
    background:#fff; color:#7a7a90; font-size:12px; padding:0 12px; cursor:pointer;
}
.df-cond-add:hover, .df-cond-clear:hover { background:#f6f4ff; color:#5b4bd6; border-color:#c9c6e6; }
.df-cond-apply { background:#5b6ef5; border-color:#5b6ef5; color:#fff; font-weight:600; }
.df-cond-apply:hover { background:#4a5ce0; border-color:#4a5ce0; }
/* select2 measures 0 width inside the initially-hidden advanced row — pin it to the field. */
.df-field .select2-container { width:100% !important; }
/* select2 itself keeps Exment's default AdminLTE look — no custom skin. */
.df-reset {
    height:32px; align-self:flex-end; border:1px solid #d7d5e6; border-radius:6px;
    background:#fff; color:#7a7a90; font-size:12px; padding:0 12px; cursor:pointer;
}
.df-reset:hover { background:#f6f4ff; color:#5b4bd6; border-color:#c9c6e6; }
/* Drill breadcrumb: root › region › ... — ancestors clickable, current level bold. */
.df-crumbs { display:flex; align-items:center; flex-wrap:wrap; gap:7px; font-size:13px; }
.df-crumbs a { color:#5b6ef5; text-decoration:none; font-weight:500; cursor:pointer; }
.df-crumbs a:hover { text-decoration:underline; }
.df-crumbs .fa-angle-right { color:#b6b6c8; }
.df-crumb-current { color:#23233b; font-weight:700; }
/* Caution notes (per-dim config `note`, shown while that dim is filtered). */
.df-notes { display:flex; flex-wrap:wrap; gap:8px; }
.df-note {
    font-size:12px; color:#8a6d1a; background:#fdf6e3;
    border:1px solid #f0e2b6; border-radius:6px; padding:4px 10px;
}
</style>

@php
    $hierDims = array_filter($dims, function ($d) { return empty($d['advanced']); });
    $advDims  = array_filter($dims, function ($d) { return !empty($d['advanced']); });
    // Detailed Filter panel: shown whenever the source table has columns to build conditions
    // on. Rows already active are rendered filled; one empty row is always offered last.
    $advColumns    = $adv_columns ?? [];
    $advOperators  = $adv_operators ?? [];
    $advValueless  = $adv_valueless ?? [];
    $advConditions = $adv_conditions ?? [];
    // Open only when something in here is ACTIVE (an advanced dim is filtered, or a condition
    // is set) — otherwise the area stays collapsed to the single "Detailed Filter" button.
    $advOpen       = !empty($adv_open) || ($advanced_count ?? 0) > 0;
    $advBadge      = count($advConditions);
    $hasAdvPanel   = count($advColumns) > 0;
@endphp
<div class="df-bar" data-dashboard-suuid="{{ $dashboard_suuid }}">
    <div class="df-head">
        <div class="df-bar-title"><i class="fa fa-filter"></i>{{ exmtrans('dashboard.header') }}</div>
        @if(count($advDims) || $hasAdvPanel)
            <div class="df-head-actions">
                <button type="button" class="df-adv-toggle {{ $advOpen ? 'open' : '' }}" data-df-adv-toggle>
                    <i class="fa fa-sliders"></i>&nbsp;{{ exmtrans('dashboard.filter_bar.advanced') }}@php $badge = $advanced_count + $advBadge; @endphp@if($badge)<span class="df-adv-count">{{ $badge }}</span>@endif&nbsp;<i class="fa fa-caret-down"></i>
                </button>
            </div>
        @endif
    </div>

    {{-- Drill breadcrumb: always-visible orientation anchor. Root alone at the top level;
         each ACTIVE hierarchy selection appends a crumb. Ancestors climb (keeping cross-cut
         filters), the last crumb is the current position. --}}
    @if(($breadcrumb ?? null) !== null)
        <div class="df-crumbs" data-df-crumbs>
            @if(count($breadcrumb))
                <a data-df-crumb data-column=""><i class="fa fa-home"></i>&nbsp;{{ $root_label }}</a>
            @else
                <span class="df-crumb-current"><i class="fa fa-home"></i>&nbsp;{{ $root_label }}</span>
            @endif
            @foreach($breadcrumb as $i => $crumb)
                <i class="fa fa-angle-right"></i>
                @if($i === count($breadcrumb) - 1)
                    <span class="df-crumb-current">{{ $crumb['label'] }}</span>
                @else
                    <a data-df-crumb data-column="{{ $crumb['column'] }}">{{ $crumb['label'] }}</a>
                @endif
            @endforeach
        </div>
    @endif

    <div class="df-fields">
        @foreach($hierDims as $dim)
            @include('exment::dashboard.filter_bar_field', ['dim' => $dim])
        @endforeach
        @if($has_selection)
            {{-- reset sits inline at the END of the filter row, aligned with the selects --}}
            <button type="button" class="df-reset" data-df-reset><i class="fa fa-times"></i>&nbsp;{{ trans('admin.reset') }}</button>
        @endif
    </div>

    {{-- DETAILED FILTER — the collapsible advanced area (Power-BI "advanced filtering"
         organisation): any dim explicitly flagged `advanced` in the config keeps rendering
         here as a plain select, and below them the condition builder lets the viewer add
         free conditions (field + operator + value) on ANY column of the source table.
         Collapsed by default; opens itself when a condition is active. --}}
    @if(count($advDims) || $hasAdvPanel)
        <div class="df-adv" data-df-adv @if(!$advOpen) style="display:none" @endif>
            @if(count($advDims))
                <div class="df-adv-dims">
                    @foreach($advDims as $dim)
                        @include('exment::dashboard.filter_bar_field', ['dim' => $dim])
                    @endforeach
                </div>
            @endif

            @if($hasAdvPanel)
                <div class="df-cond" data-df-cond>
                    <div class="df-cond-title">{{ exmtrans('dashboard.filter_bar.detailed_filter') }}</div>
                    <div class="df-cond-rows" data-df-cond-rows>
                        {{-- one row per active condition, plus a blank row to add the next --}}
                        @foreach(array_merge($advConditions, [['column' => '', 'operator' => 'contains', 'value' => '']]) as $cond)
                            <div class="df-cond-row" data-df-cond-row>
                                <label>{{ exmtrans('dashboard.filter_bar.cond_field') }}
                                    <select class="df-cond-col">
                                        <option value=""></option>
                                        @foreach($advColumns as $col)
                                            <option value="{{ $col['name'] }}" {{ $cond['column'] === $col['name'] ? 'selected' : '' }}>{{ $col['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>{{ exmtrans('dashboard.filter_bar.cond_condition') }}
                                    <select class="df-cond-op">
                                        @foreach($advOperators as $op)
                                            <option value="{{ $op }}" {{ $cond['operator'] === $op ? 'selected' : '' }}>{{ exmtrans('dashboard.filter_bar.cond_op.' . $op) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>{{ exmtrans('dashboard.filter_bar.cond_value') }}
                                    <input type="text" class="df-cond-val" value="{{ $cond['value'] }}"
                                        placeholder="{{ exmtrans('dashboard.filter_bar.cond_value_placeholder') }}"
                                        @if(in_array($cond['operator'], $advValueless, true)) disabled @endif>
                                </label>
                                <button type="button" class="df-cond-del" data-df-cond-del title="{{ trans('admin.delete') }}"><i class="fa fa-times"></i></button>
                            </div>
                        @endforeach
                    </div>
                    <div class="df-cond-actions">
                        <button type="button" class="df-cond-add" data-df-cond-add><i class="fa fa-plus"></i>&nbsp;{{ exmtrans('dashboard.filter_bar.cond_add') }}</button>
                        <button type="button" class="df-cond-apply" data-df-cond-apply><i class="fa fa-check"></i>&nbsp;{{ exmtrans('dashboard.filter_bar.cond_apply') }}</button>
                        @if($advBadge)
                            <button type="button" class="df-cond-clear" data-df-cond-clear>{{ exmtrans('dashboard.filter_bar.cond_clear') }}</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Caution notes: a dim config may declare `note`, rendered while that dim is filtered
         (e.g. the score-band dim warning that averages describe only the filtered range). --}}
    @if(!empty($notes ?? []))
        <div class="df-notes">
            @foreach($notes as $note)
                <span class="df-note"><i class="fa fa-exclamation-triangle"></i>&nbsp;{{ $note }}</span>
            @endforeach
        </div>
    @endif
</div>

<script type="text/javascript">
(function () {
    var bar = document.querySelector('.df-bar');
    if (!bar) { return; }
    var DASH = bar.getAttribute('data-dashboard-suuid') || '';
    // Base = current dashboard home URL with the query string stripped.
    var BASE = location.origin + location.pathname;
    // The dims in DOM order + their parent links (the cascade chain) + control kind. Column
    // names and parents come from the config, not hard-coded.
    var COLUMNS = [], PARENT = {}, KIND = {}, FIELD = {};
    Array.prototype.forEach.call(bar.querySelectorAll('.df-select, .df-range'), function (el) {
        var c = el.getAttribute('data-column');
        COLUMNS.push(c);
        PARENT[c] = el.getAttribute('data-parent') || '';
        KIND[c] = el.classList.contains('df-range') ? 'range' : 'select';
        FIELD[c] = el;
    });

    // ---- reading / writing one dim's selection ----------------------------------------
    // select → array of selected values; range → {from, to} (strings, '' = open side)
    function selectedOf(sel) {
        var out = [];
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].selected && sel.options[i].value !== '') { out.push(sel.options[i].value); }
        }
        return out;
    }
    function rangeOf(wrap) {
        var f = wrap.querySelector('.df-range-input[data-bound="from"]');
        var t = wrap.querySelector('.df-range-input[data-bound="to"]');
        return { from: (f && f.value) ? String(f.value).trim() : '', to: (t && t.value) ? String(t.value).trim() : '' };
    }
    function isActive(col) {
        var el = FIELD[col];
        if (!el) { return false; }
        if (KIND[col] === 'range') { var r = rangeOf(el); return !!(r.from || r.to); }
        return selectedOf(el).length > 0;
    }
    // Emit one dim onto the URL params — the SAME shapes FilterState::spec reads:
    //   one value   → df_col=v          (the legacy shape, so single-value links look as before)
    //   several     → df_col[]=v (each)
    //   range       → df_col[from]=x / df_col[to]=y
    function putParam(params, col) {
        var el = FIELD[col];
        if (!el) { return; }
        if (KIND[col] === 'range') {
            var r = rangeOf(el);
            if (r.from) { params.set('df_' + col + '[from]', r.from); }
            if (r.to) { params.set('df_' + col + '[to]', r.to); }
            return;
        }
        var vals = selectedOf(el);
        if (vals.length === 1) { params.set('df_' + col, vals[0]); }
        else { vals.forEach(function (v) { params.append('df_' + col + '[]', v); }); }
    }
    // What the server rendered as selected — the reference for "did this change narrow?"
    var INITIAL = {};
    COLUMNS.forEach(function (c) {
        INITIAL[c] = KIND[c] === 'range' ? rangeOf(FIELD[c]) : selectedOf(FIELD[c]);
    });

    // All dims cascading (transitively) under `col` — these become invalid when `col` narrows.
    // `seen` guards against a mis-configured parent chain that loops back on itself (A under B,
    // B under A): without it the recursion never ends and every select change dies silently,
    // leaving the bar unresponsive. A config written straight to the DB can still contain one.
    function descendantsOf(col, acc, seen) {
        seen = seen || {};
        if (seen[col]) { return acc; }
        seen[col] = true;
        for (var i = 0; i < COLUMNS.length; i++) {
            if (PARENT[COLUMNS[i]] === col) {
                if (acc.indexOf(COLUMNS[i]) < 0) { acc.push(COLUMNS[i]); }
                descendantsOf(COLUMNS[i], acc, seen);
            }
        }
        return acc;
    }

    // Navigation: hand the new URL to the dashboard's selective refresh (exmentDfNavigate) —
    // it re-renders THIS bar fragment (cascade + breadcrumb are server-side) and then reloads
    // only the boxes whose output the change can affect, so a chart no filter reaches never
    // blinks. Falls back to laravel-admin's pjax (whole #pjax-container) and finally to a
    // normal navigation, so the bar keeps working wherever those helpers are absent.
    function go(url) {
        // Close any open select2 dropdown BEFORE navigating: select2 appends its dropdown to
        // <body>, outside the replaced markup, so the swap would orphan it — a "stuck"
        // dropdown floating over the reloaded content until an extra click dismisses it.
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.df-select').each(function () {
                try { jQuery(this).select2('close'); } catch (e) {}
            });
        }
        if (window.exmentDfNavigate) {
            window.exmentDfNavigate(url);
        } else if (window.jQuery && jQuery.pjax) {
            jQuery.pjax({ url: url, container: '#pjax-container' });
        } else {
            window.location.href = url;
        }
    }

    // Carry the Detailed Filter conditions (dfa[...]) across an ordinary filter change —
    // they are a separate, cross-cutting selection and must not vanish when a select moves.
    // Only the reset button clears them (it clears everything).
    function keepAdv(params) {
        var current = new URLSearchParams(window.location.search);
        current.forEach(function (v, k) {
            if (k.indexOf('dfa[') === 0) { params.set(k, v); }
        });
    }

    // Did the change NARROW the dim? Only then do its descendants become invalid.
    //   cleared                    → no  (widening — the existing "× keeps the children" rule)
    //   a value added to a selection → no  (superset of what the children were scoped to)
    //   nothing → some value(s)    → YES ("all" shrank to a subset: a child picked under
    //                                    "all grades" may not belong to the grade just picked)
    //   replaced / removed         → yes
    //   range edited               → yes (a range rarely parents anything; be safe)
    function narrowed(col) {
        var el = FIELD[col];
        if (KIND[col] === 'range') {
            var r = rangeOf(el);
            return !!(r.from || r.to);
        }
        var now = selectedOf(el);
        if (now.length === 0) { return false; }
        var before = INITIAL[col] || [];
        if (before.length === 0) { return true; }
        for (var i = 0; i < before.length; i++) {
            if (now.indexOf(before[i]) < 0) { return true; }
        }
        return false;
    }

    function navigate(changedCol) {
        var drop = narrowed(changedCol) ? descendantsOf(changedCol, []) : [];
        // Mutual exclusion: selecting a dim that declares data-disables clears the listed
        // dims immediately (the server keeps them disabled while this dim stays selected).
        var changedEl = FIELD[changedCol];
        if (changedEl && isActive(changedCol) && changedEl.getAttribute('data-disables')) {
            changedEl.getAttribute('data-disables').split(',').forEach(function (c) {
                if (c && drop.indexOf(c) < 0) { drop.push(c); }
            });
        }
        var params = new URLSearchParams();
        if (DASH) { params.set('dashboard', DASH); }
        for (var i = 0; i < COLUMNS.length; i++) {
            if (drop.indexOf(COLUMNS[i]) >= 0) { continue; }
            putParam(params, COLUMNS[i]);
        }
        keepAdv(params);
        go(BASE + '?' + params.toString());
    }

    // ---- select dims: select2 multi, applied once per open/close -----------------------
    // Several values are usually picked in one go, so a change while the dropdown is OPEN
    // only marks the dim dirty; the navigation happens when it closes. A change while it is
    // CLOSED (the chip ×, the clear ×, a chart's click-to-filter) navigates at once.
    var OPEN = {}, DIRTY = {};
    function commit(sel) {
        var col = sel.getAttribute('data-column');
        DIRTY[col] = false;
        navigate(col);
    }
    function onDimChange() {
        var col = this.getAttribute('data-column');
        if (OPEN[col]) { DIRTY[col] = true; } else { commit(this); }
    }
    // select2 normally reaches every admin page through laravel-admin's field-asset collection
    // (Admin::bootstrap → Form::collectFieldAssets), loaded at the end of <body> — so wait for
    // DOM-ready on a full page load (immediate on the fragment re-render). Safety net: should a
    // page come without it, load it here on demand, once (CSS link + script), instead of
    // registering it as a base asset in the middleware.
    var SELECT2_CSS = {!! json_encode(admin_asset('/vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css')) !!};
    var SELECT2_JS  = {!! json_encode(admin_asset('/vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js')) !!};
    function ensureSelect2(cb) {
        if (!window.jQuery) { return; }
        jQuery(function () {
            if (jQuery.fn.select2) { cb(); return; }
            if (!document.getElementById('exment-select2-css')) {
                var link = document.createElement('link');
                link.id = 'exment-select2-css'; link.rel = 'stylesheet'; link.href = SELECT2_CSS;
                document.head.appendChild(link);
            }
            window.__exmentSelect2 = window.__exmentSelect2 || jQuery.getScript(SELECT2_JS);
            window.__exmentSelect2.done(cb);
        });
    }
    bar.querySelectorAll('.df-select').forEach(function (sel) {
        // jQuery binding: select2 fires its change through jQuery, which a native listener
        // would never see; native listener only when jQuery is absent
        if (window.jQuery) { jQuery(sel).on('change', onDimChange); } else { sel.addEventListener('change', onDimChange); }
    });
    // select2 init is owned here (not CommonEvent.addSelect2) so the multi-select can keep
    // its dropdown open across picks; the same options otherwise (allowClear = the ×,
    // placeholder = （すべて）). Runs when select2 is available: at once on the fragment
    // re-render (already loaded), after the on-demand load on the first full page load.
    function setupSelect2() {
        jQuery(bar).find('.df-select').each(function () {
            var $s = jQuery(this);
            if ($s.hasClass('added-select2')) { return; }
            var sel = this;
            $s.on('select2:open', function () { OPEN[sel.getAttribute('data-column')] = true; })
                .on('select2:close', function () {
                    var col = sel.getAttribute('data-column');
                    OPEN[col] = false;
                    if (DIRTY[col]) { commit(sel); }
                });
            $s.select2({
                allowClear: true,
                placeholder: $s.data('placeholder') || '',
                width: '100%',
                closeOnSelect: false
            }).addClass('added-select2');
        });
        // The clear × also OPENS the dropdown right after clearing — select2's default. Here
        // clearing triggers a reload, so that just-opened dropdown would linger over the swapping
        // page and demand an extra click. Suppress the open that follows a clear: select2 fires
        // select2:unselecting on the × press, then select2:opening — veto that one.
        jQuery(bar).off('select2:unselecting.dfbar select2:opening.dfbar')
            .on('select2:unselecting.dfbar', '.df-select', function () {
                jQuery(this).data('df-unselecting', true);
            }).on('select2:opening.dfbar', '.df-select', function (e) {
                if (jQuery(this).data('df-unselecting')) {
                    jQuery(this).removeData('df-unselecting');
                    e.preventDefault();
                }
            });
    }
    ensureSelect2(setupSelect2);

    // ---- range dims: from / to inputs, applied on change (blur / Enter / date pick) ------
    bar.querySelectorAll('.df-range-input').forEach(function (inp) {
        inp.addEventListener('change', function () {
            navigate(this.closest('.df-range').getAttribute('data-column'));
        });
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); this.blur(); }
        });
    });

    // ---- click-to-filter entry point for the charts --------------------------------------
    // exmentDfPick(column, value, additive): a plain click SELECTS that one value (clicking the
    // only selected value again clears it); Ctrl/⌘-click TOGGLES the value in the current
    // multi-selection (Power BI's click / Ctrl-click). Goes through the dim's select when it
    // is on screen and enabled, else edits the URL directly (a capped dim, a range dim).
    window.exmentDfPick = function (column, val, additive) {
        val = (val === null || val === undefined) ? '' : String(val);
        if (val === '') { return; }
        var sel = FIELD[column];
        var next;
        function nextOf(cur) {
            if (additive) {
                return cur.indexOf(val) >= 0 ? cur.filter(function (v) { return v !== val; }) : cur.concat([val]);
            }
            return (cur.length === 1 && cur[0] === val) ? [] : [val];
        }
        if (sel && KIND[column] === 'select' && !sel.disabled) {
            next = nextOf(selectedOf(sel));
            // The value came from the chart's own (already-filtered) data, so it belongs to the
            // dim's domain — but the cascaded select may not list it yet; add it so it sticks.
            next.forEach(function (v) {
                var present = false;
                for (var i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === v) { present = true; break; }
                }
                if (!present) {
                    var opt = document.createElement('option');
                    opt.value = v;
                    opt.textContent = v;
                    sel.appendChild(opt);
                }
            });
            for (var j = 0; j < sel.options.length; j++) {
                sel.options[j].selected = next.indexOf(sel.options[j].value) >= 0;
            }
            if (window.jQuery) { jQuery(sel).trigger('change'); }
            else { sel.dispatchEvent(new Event('change')); }
            return;
        }
        // URL fallback: same semantics, straight on the query string
        var params = new URLSearchParams(location.search);
        var cur = params.getAll('df_' + column + '[]');
        if (params.has('df_' + column)) { cur = [params.get('df_' + column)]; }
        ['', '[]', '[from]', '[to]'].forEach(function (s) { params.delete('df_' + column + s); });
        next = nextOf(cur);
        if (next.length === 1) { params.set('df_' + column, next[0]); }
        else { next.forEach(function (v) { params.append('df_' + column + '[]', v); }); }
        go(BASE + (params.toString() ? '?' + params.toString() : ''));
    };

    var reset = bar.querySelector('[data-df-reset]');
    if (reset) {
        reset.addEventListener('click', function () {
            go(BASE + (DASH ? ('?dashboard=' + encodeURIComponent(DASH)) : ''));
        });
    }

    // Breadcrumb: climbing to an ancestor keeps chain dims up to (and including) the clicked
    // one, drops the deeper chain dims, and keeps every independent cross-cut filter. The
    // home crumb clears the whole chain. Chain membership derives from the same parent links
    // the cascade uses (a dim with a parent, or one that IS a parent).
    var IS_PARENT = {};
    COLUMNS.forEach(function (c) { if (PARENT[c]) { IS_PARENT[PARENT[c]] = true; } });
    function isChainDim(c) { return !!PARENT[c] || !!IS_PARENT[c]; }
    bar.querySelectorAll('[data-df-crumb]').forEach(function (crumb) {
        crumb.addEventListener('click', function () {
            var targetCol = this.getAttribute('data-column') || '';
            var params = new URLSearchParams();
            if (DASH) { params.set('dashboard', DASH); }
            var pastTarget = (targetCol === '');
            for (var i = 0; i < COLUMNS.length; i++) {
                var c = COLUMNS[i];
                if (!isActive(c)) { continue; }
                if (isChainDim(c)) {
                    if (!pastTarget) { putParam(params, c); }
                    if (c === targetCol) { pastTarget = true; }
                } else {
                    putParam(params, c);
                }
            }
            keepAdv(params);
            go(BASE + '?' + params.toString());
        });
    });

    // Advanced-group toggle: pure show/hide — the server decides the initial state (open whenever
    // an advanced dim is filtered, so an active filter is never hidden behind the collapse).
    var advToggle = bar.querySelector('[data-df-adv-toggle]');
    var advRow = bar.querySelector('[data-df-adv]');
    if (advToggle && advRow) {
        advToggle.addEventListener('click', function () {
            var open = advRow.style.display === 'none';
            advRow.style.display = open ? '' : 'none';
            advToggle.classList.toggle('open', open);
        });
    }

    // ---- Detailed Filter: the condition builder -------------------------------------
    // Rows are pure UI until Apply; Apply rewrites the URL with dfa[i][c|o|v] params and
    // navigates through the same go() the selects use, so deep links, back/forward and the
    // selective box refresh all behave identically to an ordinary filter change.
    var condBox = bar.querySelector('[data-df-cond]');
    if (condBox) {
        var VALUELESS = {!! json_encode($adv_valueless ?? []) !!};
        var rowsWrap = condBox.querySelector('[data-df-cond-rows]');

        // an operator that tests presence takes no value — grey the box out (and clear it,
        // so an invisible leftover value cannot travel into the URL)
        function syncRow(row) {
            var op = row.querySelector('.df-cond-op');
            var val = row.querySelector('.df-cond-val');
            if (!op || !val) { return; }
            var off = VALUELESS.indexOf(op.value) >= 0;
            val.disabled = off;
            if (off) { val.value = ''; }
        }
        Array.prototype.forEach.call(condBox.querySelectorAll('[data-df-cond-row]'), syncRow);

        condBox.addEventListener('change', function (ev) {
            var row = ev.target.closest('[data-df-cond-row]');
            if (row) { syncRow(row); }
        });

        condBox.addEventListener('click', function (ev) {
            var del = ev.target.closest('[data-df-cond-del]');
            if (del) {
                var row = del.closest('[data-df-cond-row]');
                if (row && rowsWrap.querySelectorAll('[data-df-cond-row]').length > 1) { row.remove(); }
                else if (row) {                       // keep one blank row on screen
                    row.querySelector('.df-cond-col').value = '';
                    row.querySelector('.df-cond-val').value = '';
                }
                return;
            }
            if (ev.target.closest('[data-df-cond-add]')) {
                var last = rowsWrap.querySelector('[data-df-cond-row]:last-child');
                var clone = last.cloneNode(true);
                clone.querySelector('.df-cond-col').value = '';
                clone.querySelector('.df-cond-val').value = '';
                clone.querySelector('.df-cond-val').disabled = false;
                rowsWrap.appendChild(clone);
                return;
            }
            if (ev.target.closest('[data-df-cond-apply]')) { applyConditions(); }
            if (ev.target.closest('[data-df-cond-clear]')) { applyConditions(true); }
        });

        function applyConditions(clear) {
            var params = new URLSearchParams();
            if (DASH) { params.set('dashboard', DASH); }
            // keep every ordinary filter selection exactly as it is
            for (var i = 0; i < COLUMNS.length; i++) { putParam(params, COLUMNS[i]); }
            if (!clear) {
                var n = 0;
                Array.prototype.forEach.call(condBox.querySelectorAll('[data-df-cond-row]'), function (row) {
                    var col = row.querySelector('.df-cond-col').value;
                    var op = row.querySelector('.df-cond-op').value;
                    var val = row.querySelector('.df-cond-val').value;
                    if (!col) { return; }                                   // row not filled in
                    if (VALUELESS.indexOf(op) < 0 && val === '') { return; } // needs a value
                    params.set('dfa[' + n + '][c]', col);
                    params.set('dfa[' + n + '][o]', op);
                    if (VALUELESS.indexOf(op) < 0) { params.set('dfa[' + n + '][v]', val); }
                    n++;
                });
            }
            go(BASE + '?' + params.toString());
        }
    }
})();
</script>
