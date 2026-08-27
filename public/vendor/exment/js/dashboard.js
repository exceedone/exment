/**
 * Exment dashboard runtime: box loading, the dashboard filter bar, the chart toolbar
 * (chart-type switcher + chart filter) and the AI summary strip.
 *
 * Entry point: ExmentDashboard.init({lang: {...}}) from DashboardController::home.
 * Re-run on every pjax render, so every binding is namespaced and re-bound idempotently.
 *
 * Request params of a box (dashboardbox/html/{suuid}):
 *   df_{column}           dashboard filter bar selection, copied from the page URL
 *   ct                    runtime chart type (page-lifetime, per box)
 *   bf_{column}           chart filter selection (page-lifetime, per box)
 *
 * A filter bar change is applied selectively (see `navigate`): pushState the new URL,
 * reload only the boxes the changed items narrow (their data-df-dims attribute), refresh
 * the bar from a partial request (?_df_bar=1) — untouched boxes keep their content.
 */
(function ($) {
    'use strict';

    var NS = '.exdash';
    var L = {};      // texts
    var state = {};  // suuid => {ct: type, bf: {column: [values] | {from, to}}, reopen: true}

    function esc(s) { return $('<i>').text(String(s == null ? '' : s)).html(); }
    function boxOf(suuid) { return $('[data-suuid="' + suuid + '"]'); }
    function stateOf(suuid) { return state[suuid] || (state[suuid] = {}); }

    // ---- box request URL --------------------------------------------------------------
    function dfParams() {
        var out = [];
        new URLSearchParams(window.location.search).forEach(function (v, k) {
            if (k.indexOf('df_') === 0 && v !== '') { out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); }
        });
        return out;
    }

    function boxQuery(suuid) {
        var s = stateOf(suuid), out = dfParams();
        if (s.ct) { out.push('ct=' + encodeURIComponent(s.ct)); }
        $.each(s.bf || {}, function (column, v) {
            var key = 'bf_' + encodeURIComponent(column);
            if ($.isArray(v)) {
                if (v.length === 1) { out.push(key + '=' + encodeURIComponent(v[0])); }
                else { $.each(v, function (i, x) { out.push(key + '[]=' + encodeURIComponent(x)); }); }
            } else {
                if (v.from) { out.push(key + '[from]=' + encodeURIComponent(v.from)); }
                if (v.to) { out.push(key + '[to]=' + encodeURIComponent(v.to)); }
            }
        });
        return out.length ? '?' + out.join('&') : '';
    }

    // ---- box loading ------------------------------------------------------------------
    function loadBox(suuid, url) {
        if (!suuid) { return; }
        var $box = boxOf(suuid);
        if ($box.hasClass('loading')) {
            // a newer state arrived while fetching: re-run once this request completes
            $box.data('pending', url || true);
            return;
        }
        $box.removeData('pending').addClass('loading');
        var $body = $box.find('.box-body-inner-body');
        $body.css('height', $body.height());
        $box.find('.box-body-inneritem').html('');
        $box.find('.overlay').show();

        $.ajax({
            url: url || (admin_url('dashboardbox/html/' + suuid) + boxQuery(suuid)),
            type: 'GET',
            success: function (data) {
                if (data.header) { $box.find('.box-body-inner-header').html(data.header); }
                if (data.body) { $box.find('.box-body-inner-body').html(data.body); }
                if (data.footer) { $box.find('.box-body-inner-footer').html(data.footer); }
                $body.css('height', '');
                $box.find('.overlay').hide();
                // the server may have dropped chart-filter values the new scope no longer
                // offers: the rendered popover is the truth, mirror it
                if ($box.find('.exment-bf-list, .exment-bf-range').length) {
                    stateOf(suuid).bf = collectChartFilter($box);
                }
                if (stateOf(suuid).reopen) {
                    delete stateOf(suuid).reopen;
                    openPop($box.find('[data-ct-pop]').first());
                }
                // the request may have been built before a pushState'd filter change:
                // recompute the badge against the current URL (same result as the server's)
                syncBadge($box);
                $box.trigger('exment:dashboard_loaded');
                $box.removeClass('loading');
                Exment.CommonEvent.tableHoverLink();
                flushPending($box, suuid);
            },
            error: function () {
                $box.find('.overlay').hide();
                $box.removeClass('loading');
                $box.find('.box-body-inner-body').html(esc(L.error));
                flushPending($box, suuid);
            }
        });
    }

    function flushPending($box, suuid) {
        var pending = $box.data('pending');
        if (pending) {
            $box.removeData('pending');
            loadBox(suuid, pending === true ? null : pending);
        }
    }

    // ---- chart toolbar ----------------------------------------------------------------
    function closePops() {
        $('.ct-pop').removeClass('show');
        $('.ct-btn').removeClass('open');
    }

    function openPop($btn) {
        if (!$btn.length) { return; }
        closePops();
        $btn.addClass('open');
        var $pop = $btn.closest('.ct-item').find('.ct-pop').first().addClass('show');
        // keep the panel inside its card: cap its width and pull an overflowing edge back
        var card = $pop.closest('.box')[0], item = $pop.closest('.ct-item')[0];
        if (!card || !item) { return; }
        var PAD = 8, c = card.getBoundingClientRect(), room = c.width - PAD * 2;
        $pop.css({ left: '', right: '', 'max-width': room + 'px' });
        if ($pop[0].getBoundingClientRect().width > room) {
            $pop.css('min-width', 0); // the fields' own min-width beats max-width: let them wrap instead
        }
        var p = $pop[0].getBoundingClientRect(), i = item.getBoundingClientRect();
        if (p.left < c.left + PAD) { $pop.css({ left: (c.left + PAD - i.left) + 'px', right: 'auto' }); }
        else if (p.right > c.right - PAD) { $pop.css({ left: 'auto', right: (i.right - (c.right - PAD)) + 'px' }); }
    }

    function collectChartFilter($box) {
        var sel = {};
        $box.find('.exment-bf-list').each(function () {
            var column = $(this).data('column'), values = [];
            $(this).find('.exment-bf-check:checked').each(function () { values.push(String($(this).val())); });
            if (column && values.length) { sel[column] = values; }
        });
        $box.find('.exment-bf-range').each(function () {
            var column = $(this).data('column'), bound = $(this).data('bound'), v = String($(this).val() || '').trim();
            if (!column || !v) { return; }
            if (!sel[column] || $.isArray(sel[column])) { sel[column] = {}; }
            sel[column][bound] = v;
        });
        return sel;
    }

    function reloadFromToolbar($el, reset) {
        var $box = $el.closest('[data-suuid]'), suuid = $box.data('suuid');
        if (!suuid) { return; }
        if (reset) { delete stateOf(suuid).bf; } else { stateOf(suuid).bf = collectChartFilter($box); }
        stateOf(suuid).reopen = true;
        loadBox(suuid);
    }

    // ---- filter bar -------------------------------------------------------------------
    // A selected value the current scope no longer offers (data-missing, tagged by
    // FilterBarView) renders dimmed with a note — in the list and on its chip — so a
    // combination that yields no rows explains itself.
    function dfOptionLabel(state) {
        if (!state.id || !$(state.element).attr('data-missing')) { return state.text; }
        return $('<span class="df-nomatch">').text(state.text)
            .append($('<small>').text('(' + (L.filter_no_match || '') + ')'));
    }

    function initFilterBar() {
        $('.exment-df-bar .df-select').each(function () {
            var $s = $(this);
            if ($s.hasClass('select2-hidden-accessible')) { return; }
            $s.select2({
                width: '100%',
                closeOnSelect: false,
                placeholder: $s.data('placeholder') || '',
                templateResult: dfOptionLabel,
                templateSelection: dfOptionLabel
            });
            $s.data('applied', ($s.val() || []).slice());
        });
    }

    function selectChanged($s) {
        var a = ($s.data('applied') || []).slice().sort(), b = ($s.val() || []).slice().sort();
        return a.join('') !== b.join('');
    }

    function filterBarUrl(reset) {
        var $bar = $('.exment-df-bar'), params = new URLSearchParams();
        var dashboard = $bar.data('dashboard-suuid');
        if (dashboard) { params.set('dashboard', dashboard); }
        if (!reset) {
            $bar.find('.df-select').each(function () {
                var column = $(this).data('column'), values = $(this).val() || [];
                if (values.length === 1) { params.set('df_' + column, values[0]); }
                else { values.forEach(function (v) { params.append('df_' + column + '[]', v); }); }
            });
            $bar.find('.df-range').each(function () {
                var column = $(this).data('column');
                $(this).find('.df-range-input').each(function () {
                    var v = String($(this).val() || '').trim();
                    if (v) { params.set('df_' + column + '[' + $(this).data('bound') + ']', v); }
                });
            });
        }
        var query = params.toString();
        return window.location.pathname + (query ? '?' + query : '');
    }

    // Click-to-filter from a chart: select `value` on the bar's `column` item — click = only
    // this value (clicking the sole selected value again clears it), Ctrl/⌘-click = toggle it
    // within the current selection — then navigate like a bar change.
    function pick(column, value, toggle) {
        var $s = $('.exment-df-bar .df-select[data-column="' + column + '"]');
        value = String(value == null ? '' : value);
        if (!$s.length || value === '' || !$s.find('option[value="' + value.replace(/"/g, '\\"') + '"]').length) { return; }
        var current = ($s.val() || []).slice(), values;
        if (toggle) {
            values = current.indexOf(value) >= 0 ? current.filter(function (v) { return v !== value; }) : current.concat([value]);
        } else {
            values = (current.length === 1 && current[0] === value) ? [] : [value];
        }
        $s.val(values);
        navigate(filterBarUrl());
    }

    // ---- selective apply --------------------------------------------------------------
    // A bar change reloads ONLY the boxes the changed items narrow (data-df-dims, written
    // per box by DashboardController): the others keep their rendered content — no spinner
    // that could read as "this chart was filtered too". The URL still updates (pushState,
    // so F5 / back keep working) and the bar re-renders its option lists from a partial
    // request. Anything unexpected falls back to a full pjax render.
    var barReq = 0, pushedState = false;

    function dfColumnOf(key) {
        if (key.indexOf('df_') !== 0) { return null; }
        var column = key.slice(3), bracket = column.indexOf('[');
        return bracket >= 0 ? column.slice(0, bracket) : column;
    }

    // active selection of a URL query string, as column => sorted ["key=value", ...]
    function dfSelection(search) {
        var sel = {};
        new URLSearchParams(search).forEach(function (v, k) {
            var column = dfColumnOf(k);
            if (column && v !== '') { (sel[column] = sel[column] || []).push(k + '=' + v); }
        });
        $.each(sel, function (column, parts) { parts.sort(); });
        return sel;
    }

    function changedColumns(oldSearch, newSearch) {
        var a = dfSelection(oldSearch), b = dfSelection(newSearch), out = [];
        $.each(a, function (column, parts) {
            if (!b[column] || b[column].join('&') !== parts.join('&')) { out.push(column); }
        });
        $.each(b, function (column) {
            if (!a[column]) { out.push(column); }
        });
        return out;
    }

    function boxDims($box) {
        return String($box.attr('data-df-dims') || '').split(',').filter(Boolean);
    }

    // client mirror of DashboardBoxController::filterBadge, for boxes that skip the reload:
    // the active items minus the ones this box honours — so unfiltered numbers stay disclosed
    // without a re-fetch. No-op on boxes without data-df-dims (server badge is the truth).
    function syncBadge($box) {
        if ($box.attr('data-df-dims') === undefined) { return; }
        var active = Object.keys(dfSelection(window.location.search));
        var dims = boxDims($box);
        var ignored = active.filter(function (column) { return dims.indexOf(column) < 0; });
        var $body = $box.find('.box-body-inner-body'), $badge = $body.find('.exment-filter-badge').first();
        if (!$body.length || !ignored.length) { $badge.remove(); return; }
        var labels = {};
        $('.exment-df-bar .df-field').each(function () {
            var column = $(this).find('[data-column]').first().data('column');
            if (column) { labels[column] = $.trim($(this).find('label').first().text()); }
        });
        var text = ignored.length === active.length
            ? L.filter_not_affected
            : L.filter_partially_affected + ': ' + ignored.map(function (column) { return labels[column] || column; }).join(', ');
        if (!$badge.length) { $badge = $('<div class="exment-filter-badge"><span></span></div>').prependTo($body); }
        $badge.find('span').text(text);
    }

    function refreshFilterBar(url) {
        var token = ++barReq;
        $.get(url + (url.indexOf('?') >= 0 ? '&' : '?') + '_df_bar=1').done(function (html) {
            if (token !== barReq) { return; }
            var $fresh = $('<div>').append($.parseHTML(String(html))).find('.exment-df-bar').first();
            syncOpenSelects($fresh);
            applyFilterBar($fresh, token);
        });
    }

    // While a dropdown is open the fresh bar cannot be swapped in (applyFilterBar defers):
    // narrow the OPEN select's option list in place instead, so picking fast never picks
    // from a stale list. The user's selection is preserved; selected values the fresh scope
    // no longer offers stay as data-missing options (mirror of FilterBarView).
    function syncOpenSelects($fresh) {
        $('.exment-df-bar .df-select').each(function () {
            var $s = $(this), open = false;
            try { open = $s.data('select2') && $s.select2('isOpen'); } catch (e) {}
            if (!open) { return; }
            var $freshSelect = $fresh.find('.df-select[data-column="' + $s.data('column') + '"]');
            if (!$freshSelect.length) { return; }
            var selected = ($s.val() || []).map(String), seen = {}, opts = [];
            $freshSelect.find('option').each(function () {
                seen[String($(this).val())] = true;
                opts.push($(this).clone());
            });
            $.each(selected.slice().reverse(), function (i, v) {
                if (seen[v]) { return; }
                var $old = $s.find('option').filter(function () { return String($(this).val()) === v; }).first();
                opts.unshift($('<option>').val(v).text($old.length ? $old.text() : v).attr('data-missing', '1'));
            });
            $s.empty().append(opts).val(selected).trigger('change.select2');
            try {
                var s2 = $s.data('select2');
                var term = s2.$container ? String(s2.$container.find('.select2-search__field').val() || '') : '';
                s2.trigger('query', { term: term }); // re-render the open results list
            } catch (e) {}
        });
    }

    // Swap in the freshly rendered bar — but never while the user is in it: replacing the
    // DOM under an open select2 strands its dropdown at the page corner (the dropdown is
    // attached to <body> and loses its anchor). Retry until free; a newer refresh (token
    // bump) obsoletes this one.
    function applyFilterBar($fresh, token) {
        if (token !== barReq) { return; }
        var $bar = $('.exment-df-bar').first();
        if (!$fresh.length || !$bar.length) { return; }
        var active = document.activeElement;
        var busy = $bar.find('.df-select').toArray().some(function (el) {
            try { return $(el).data('select2') && $(el).select2('isOpen'); } catch (e) { return false; }
        }) || ($.contains($bar[0], active) && (
            $(active).hasClass('df-range-input')                                             // typing a range bound
            || ($(active).hasClass('select2-search__field') && String(active.value || '') !== '') // typed search text
        ));
        if (busy) { setTimeout(function () { applyFilterBar($fresh, token); }, 250); return; }
        $bar.find('.df-select').each(function () {
            try { $(this).select2('destroy'); } catch (e) {}
        });
        $bar.replaceWith($fresh);
        initFilterBar();
    }

    function fullNavigate(url) {
        // an open dropdown is attached to <body> and would survive the pjax swap as an orphan
        $('.exment-df-bar .df-select').each(function () {
            try { $(this).select2('close'); } catch (e) {}
        });
        if ($.pjax) { $.pjax({ url: url, container: '#pjax-container' }); } else { window.location.href = url; }
    }

    function navigate(url) {
        $('.exment-df-bar .df-select').each(function () {
            $(this).data('applied', ($(this).val() || []).slice());
            try { $(this).select2('close'); } catch (e) {}
        });
        if (url === window.location.pathname + window.location.search) { return; }
        var $boxes = $('[data-suuid]').filter(function () { return $(this).data('suuid'); });
        var canPartial = window.history && window.history.pushState && $boxes.length
            && $boxes.filter('[data-df-dims]').length === $boxes.length;
        if (!canPartial) { fullNavigate(url); return; }
        var changed = changedColumns(window.location.search, url.indexOf('?') >= 0 ? url.slice(url.indexOf('?')) : '');
        window.history.pushState({ exdf: true }, '', url);
        pushedState = true;
        $boxes.each(function () {
            var dims = boxDims($(this)), hit = false;
            $.each(changed, function (i, column) { if (dims.indexOf(column) >= 0) { hit = true; } });
            if (hit) { loadBox($(this).data('suuid')); } else { syncBadge($(this)); }
        });
        refreshFilterBar(url);
    }

    // ---- AI summary -------------------------------------------------------------------
    // The chart renderers register a per-box marker hook (window.ExmentCharts[suuid].mark);
    // the anomalies of the summary are painted on the chart while the strip is open.
    function markChart($wrap, anomalies) {
        var suuid = $wrap.closest('[data-suuid]').data('suuid'), hook = (window.ExmentCharts || {})[suuid];
        if (hook && typeof hook.mark === 'function') {
            try { hook.mark(anomalies || null); } catch (e) {}
        }
    }

    function fetchAi($wrap) {
        var $box = $wrap.closest('[data-suuid]'), suuid = $box.data('suuid'), $panel = $wrap.find('[data-ai-panel]');
        if (!suuid || $wrap.data('loading')) { return; }
        $wrap.data('loading', true);
        $panel.html('<div class="ai-loading"><i class="fa fa-circle-o-notch fa-spin"></i> ' + esc(L.ai_generating) + '</div>');
        $.ajax({
            url: admin_url('webapi/ai-summary') + boxQuery(suuid),
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' },
            contentType: 'application/json',
            data: JSON.stringify({ suuid: suuid }),
            success: function (res) {
                $wrap.data('loading', false).data('loaded', true).data('anomalies', (res && res.anomalies) || null);
                $panel.html(res && res.success ? renderAi(res) : aiError(res && res.message));
                markChart($wrap, $wrap.data('anomalies'));
            },
            error: function (xhr) {
                $wrap.data('loading', false);
                $panel.html(aiError(xhr.responseJSON && xhr.responseJSON.message));
            }
        });
    }

    function fmtNum(v) {
        if (v === null || v === undefined || v === '' || isNaN(v)) { return '–'; }
        var n = Number(v), abs = Math.abs(n);
        var trim = function (x) { return String(Math.round(x * 10) / 10); };
        if (abs >= 1e9) { return trim(n / 1e9) + 'B'; }
        if (abs >= 1e6) { return trim(n / 1e6) + 'M'; }
        if (abs >= 1e5) { return trim(n / 1e3) + 'K'; }
        var rounded = Math.round(n * 10) / 10;
        try { return rounded.toLocaleString(undefined, { maximumFractionDigits: 1 }); } catch (e) { return String(rounded); }
    }

    function renderAi(res) {
        var html = '';
        if (res.stats) {
            var tile = function (icon, label, value, sub) {
                return '<div class="ai-stat"><div class="ai-stat-label">' + icon + esc(label) + '</div>'
                    + '<div class="ai-stat-value">' + esc(value) + '</div>'
                    + '<div class="ai-stat-sub" title="' + esc(sub || '') + '">' + (sub ? esc(sub) : '&nbsp;') + '</div></div>';
            };
            html += '<div class="ai-stats">'
                + tile('<i class="fa fa-arrow-up"></i>', L.ai_highest, fmtNum(res.stats.highest.value), res.stats.highest.label)
                + tile('<i class="fa fa-arrow-down"></i>', L.ai_lowest, fmtNum(res.stats.lowest.value), res.stats.lowest.label)
                + tile('', L.ai_average, fmtNum(res.stats.average))
                + tile('', L.ai_range, fmtNum(res.stats.range))
                + '</div>';
        }
        if (res.anomalies) {
            var a = res.anomalies;
            var range = esc(L.ai_expected_range) + ': ' + esc(fmtNum(a.lower)) + ' – ' + esc(fmtNum(a.upper));
            if (!a.points || !a.points.length) {
                html += '<div class="ai-stable"><i class="fa fa-check-circle"></i>' + esc(L.ai_stable) + ' <span class="ai-anomaly-range">(' + range + ')</span></div>';
            } else {
                html += '<div class="ai-anomalies"><div class="ai-anomaly-head"><i class="fa fa-exclamation-triangle"></i>' + esc(L.ai_anomalies)
                    + ' <span class="ct-cnt">' + a.points.length + '</span><span class="ai-anomaly-range">' + range + '</span></div><ul class="ai-anomaly-list">';
                $.each(a.points, function (i, p) {
                    html += '<li class="ai-anomaly"><i class="fa fa-arrow-' + (p.direction === 'high' ? 'up' : 'down') + '"></i>'
                        + '<span class="ai-anomaly-label" title="' + esc(p.label) + '">' + esc(p.label) + '</span>'
                        + '<span class="ai-anomaly-value">' + esc(fmtNum(p.value)) + '</span></li>';
                });
                html += '</ul></div>';
            }
        }
        // the model answers in prose; emphasise the figures, strip any stray markdown
        var text = String(res.text || '').replace(/\*\*([^*]+)\*\*/g, '$1').replace(/^#{1,6}\s*/gm, '').trim();
        $.each(text.split(/\n\s*\n/), function (i, para) {
            para = para.replace(/\s+/g, ' ').trim();
            if (para) { html += '<p class="ai-text">' + esc(para).replace(/\d[\d,]*(?:\.\d+)?%?/g, '<strong>$&</strong>') + '</p>'; }
        });
        html += '<div class="ai-meta"><span><i class="fa fa-magic"></i> AI' + (res.generated_at ? ' · ' + esc(res.generated_at) : '') + '</span>'
            + '<a href="javascript:void(0)" data-ai-regen>' + esc(L.ai_regenerate) + '</a></div>';
        return html;
    }

    function aiError(message) {
        return '<div class="ai-error"><i class="fa fa-info-circle"></i>' + esc(message || L.ai_error)
            + '<a href="javascript:void(0)" data-ai-regen>' + esc(L.ai_regenerate) + '</a></div>';
    }

    // ---- bindings ---------------------------------------------------------------------
    function bind() {
        $(document).off(NS);
        $(window).off(NS);

        // box widgets
        $(document).on('click' + NS, '[data-exment-widget="delete"]', function (ev) {
            var suuid = $(ev.target).closest('[data-suuid]').data('suuid');
            Exment.CommonEvent.ShowSwal(admin_url('dashboardbox/delete/' + suuid), { title: L.delete_confirm, confirm: L.confirm, method: 'delete', cancel: L.cancel });
        });
        $(document).on('click' + NS, '[data-exment-widget="reload"]', function (ev) {
            loadBox($(ev.target).closest('[data-suuid]').data('suuid'));
        });
        $(document).on('click' + NS, '[data-ajax-link]', function (ev) {
            var $link = $(ev.target).closest('[data-ajax-link]');
            loadBox($link.closest('[data-suuid]').data('suuid'), $link.data('ajax-link'));
        });

        // filter bar: a select applies when its dropdown closes (several values picked in one
        // go); a change while it is closed (chip ×) applies at once; a range applies on change
        $(document).on('select2:close' + NS, '.exment-df-bar .df-select', function () {
            if (selectChanged($(this))) { navigate(filterBarUrl()); }
        });
        $(document).on('change' + NS, '.exment-df-bar .df-select', function () {
            if (!$(this).select2('isOpen') && selectChanged($(this))) { navigate(filterBarUrl()); }
        });
        $(document).on('change' + NS, '.exment-df-bar .df-range-input', function () { navigate(filterBarUrl()); });
        $(document).on('click' + NS, '.exment-df-bar .df-reset', function () { navigate(filterBarUrl(true)); });
        // back/forward across pushState'd filter states: pjax restores its own entries
        // (state.container), everything else re-renders in full
        $(window).on('popstate' + NS, function (ev) {
            var st = ev.originalEvent.state;
            if (!pushedState || (st && st.container)) { return; }
            fullNavigate(window.location.href);
        });

        // chart toolbar
        $(document).on('click' + NS, function (ev) {
            var $btn = $(ev.target).closest('[data-ct-pop]');
            if ($btn.length) {
                if ($btn.hasClass('open')) { closePops(); } else { openPop($btn); }
                return;
            }
            if (!$(ev.target).closest('.ct-pop').length) { closePops(); }
        });
        $(document).on('change' + NS, '.exment-ct-switch', function () {
            var suuid = $(this).closest('[data-suuid]').data('suuid');
            stateOf(suuid).ct = $(this).val();
            loadBox(suuid);
        });
        $(document).on('change' + NS, '.exment-bf-check, .exment-bf-range', function () { reloadFromToolbar($(this), false); });
        $(document).on('click' + NS, '.exment-bf-reset', function () { reloadFromToolbar($(this), true); });
        $(document).on('keydown' + NS, '.exment-bf-range, .exment-bf-search, .exment-df-bar .df-range-input', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); $(this).blur(); }
        });
        $(document).on('input' + NS, '.exment-bf-search', function () {
            var q = String($(this).val() || '').toLowerCase();
            $(this).closest('.exment-bf-list').find('label').each(function () {
                var hit = q === '' || $(this).text().toLowerCase().indexOf(q) >= 0 || $(this).find('input').prop('checked');
                $(this).toggleClass('miss', !hit);
            });
        });

        // AI summary strip: fetched on first expand only
        $(document).on('click' + NS, '[data-ai-toggle]', function () {
            var $wrap = $(this).closest('[data-ai-summary]'), $panel = $wrap.find('[data-ai-panel]');
            var open = $(this).attr('aria-expanded') === 'true';
            $(this).attr('aria-expanded', open ? 'false' : 'true');
            $wrap.toggleClass('open', !open);
            if (open) { $panel.attr('hidden', true); markChart($wrap, null); return; }
            $panel.removeAttr('hidden');
            if ($wrap.data('loaded')) { markChart($wrap, $wrap.data('anomalies')); } else { fetchAi($wrap); }
        });
        $(document).on('click' + NS, '[data-ai-regen]', function (ev) {
            ev.preventDefault();
            fetchAi($(this).closest('[data-ai-summary]'));
        });
    }

    window.ExmentDashboard = {
        // `state` deliberately survives init(): a filter-bar change re-renders the page through
        // pjax, and the boxes must come back with the chart type / chart filter they had
        init: function (options) {
            L = (options && options.lang) || {};
            bind();
            initFilterBar();
            var $boxes = $('[data-suuid]');
            $boxes.parents('.row').addClass('row-eq-height row-dashboard');
            $boxes.each(function () { loadBox($(this).data('suuid')); });
        },
        load: loadBox,
        pick: pick
    };
})(jQuery);
