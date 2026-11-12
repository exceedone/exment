/**
 * Exment dashboard runtime: box loading, the dashboard filter bar, the chart toolbar
 * (chart-type switcher + chart filter).
 *
 * Entry point: ExmentDashboard.init({lang: {...}}) from DashboardController::home.
 * Re-run on every pjax render, so every binding is namespaced and re-bound idempotently.
 *
 * Request params of a box (dashboardbox/html/{suuid}):
 *   df_{column}           dashboard filter bar selection, copied from the page URL
 *   ct                    runtime chart type (per box; remembered per user)
 *   cs                    runtime sort of the points (per box; remembered per user)
 *   cd                    display option 値ラベル ("labels"; per box; remembered per user)
 *   ca                    summary condition of the measure, 集計 menu (page-lifetime, per box)
 *   sv                    the values shown as % of total / running total / difference, 表示方法 menu (page-lifetime, per box)
 *   tn                    the top N points only ("5" / "5:other": the rest as その他), 表示件数 menu (per box; remembered per user)
 *   pu                    the date on the X axis grouped by ymd / yw / ym / yq / y, 期間の単位 menu (per box; remembered per user)
 *   bf_{column}           chart filter selection (page-lifetime, per box)
 *
 * A filter bar change is applied selectively (see `navigate`): push a history entry for
 * the new URL, reload only the boxes the changed items narrow (their data-df-dims
 * attribute), refresh the bar from a partial request (?_df_bar=1) — untouched boxes keep
 * their content. The entry is one of pjax's own (see `pushEntry`), so back / forward
 * re-render the page for the entry's URL the way pjax restores any page.
 *
 * Cross-highlight: a chart never filters by its own X item (ChartItem::highlightColumn), it
 * draws the picked values solid and fades the rest. A change on that item alone — a click on
 * the chart, or the same item on the bar — is repainted in place through the chart's
 * `highlight` hook (window.ExmentCharts[suuid]) instead of a fetch: the data cannot differ.
 */
(function ($) {
    'use strict';

    var NS = '.exdash';
    var L = {};      // texts
    var A = {};      // asset base URLs (init's options.assets)
    var state = {};  // suuid => {ct: type, cs: sort, cd: "labels", ca: summary condition, sv: show values as, tn: top N, pu: period unit, bf: {column: [values] | {from, to}}, reopen: true}
    var saving = {}; // suuid => true while its toolbar choices are being saved, 'again' when they changed meanwhile
    var pagePath = ''; // path of the dashboard page, set by init: a pjax link pushes its own URL before it renders

    function esc(s) { return $('<i>').text(String(s == null ? '' : s)).html(); }
    function boxOf(suuid) { return $('[data-suuid="' + suuid + '"]'); }
    function stateOf(suuid) { return state[suuid] || (state[suuid] = {}); }

    // The toolbar choices (chart type, sort, display, 表示件数) survive the visit per user, on the server
    // (DashboardBoxController::chartState): the next entry starts the box with them (init's
    // options.charts). One save per box at a time, the last one carrying the latest choices, so
    // quick changes can never land out of order. A stale value is harmless — the server falls
    // back to the configured type / the view's order.
    function saveToolbar(suuid) {
        if (saving[suuid]) { saving[suuid] = 'again'; return; }
        saving[suuid] = true;
        var s = stateOf(suuid);
        $.ajax({
            url: admin_url('dashboardbox/chart_state/' + suuid),
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' },
            data: { ct: s.ct || '', cs: s.cs || '', cd: s.cd || '', tn: s.tn || '', pu: s.pu || '' }
        }).always(function () {
            var again = saving[suuid] === 'again';
            delete saving[suuid];
            if (again) { saveToolbar(suuid); }
        });
    }

    // ---- box request URL --------------------------------------------------------------
    function dfParams() {
        var out = [];
        new URLSearchParams(window.location.search).forEach(function (v, k) {
            if (k.indexOf('df_') === 0 && v !== '') { out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); }
        });
        return out;
    }

    // the values picked on one bar item, as the page URL carries them (df_col=v / df_col[]=v);
    // null when the item carries a from / to (df_col[from] / df_col[to]) instead: that is no
    // pick a chart could highlight, the server filters by it (DashboardFilter::styleOf)
    function dfValues(column) {
        var out = [], range = false;
        new URLSearchParams(window.location.search).forEach(function (v, k) {
            if (v === '') { return; }
            if (k === 'df_' + column || k === 'df_' + column + '[]') { out.push(v); }
            else if (k === 'df_' + column + '[from]' || k === 'df_' + column + '[to]') { range = true; }
        });
        return range ? null : out;
    }

    function boxQuery(suuid) {
        var s = stateOf(suuid), out = dfParams();
        if (s.ct) { out.push('ct=' + encodeURIComponent(s.ct)); }
        if (s.cs) { out.push('cs=' + encodeURIComponent(s.cs)); }
        if (s.cd) { out.push('cd=' + encodeURIComponent(s.cd)); }
        if (s.ca) { out.push('ca=' + encodeURIComponent(s.ca)); }
        if (s.sv) { out.push('sv=' + encodeURIComponent(s.sv)); }
        if (s.tn) { out.push('tn=' + encodeURIComponent(s.tn)); }
        if (s.pu) { out.push('pu=' + encodeURIComponent(s.pu)); }
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
        // the popover a chart-filter change came from reopens on the render of this request
        // alone: one that fails, or lands on a page rendered again meanwhile, drops it
        var reopen = !!stateOf(suuid).reopen;
        delete stateOf(suuid).reopen;
        var $body = $box.find('.box-body-inner-body');
        $body.css('height', $body.height());
        $box.find('.box-body-inneritem').html('');
        $box.find('.overlay').show();

        $.ajax({
            url: url || (admin_url('dashboardbox/html/' + suuid) + boxQuery(suuid)),
            type: 'GET',
            success: function (data) {
                if (replaced($box)) { return; }
                // the new body's script registers the chart hooks afresh: a stale hook must never answer for it
                if (window.ExmentCharts) { delete window.ExmentCharts[suuid]; }
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
                // the 集計 and the 表示方法 the server drew by (it ignores one the measure or the
                // type cannot take) — unless a newer pick is waiting to be loaded (flushPending below)
                if (!$box.data('pending')) {
                    var applied = String($box.find('[data-ca-applied]').attr('data-ca-applied') || '');
                    if (applied) { stateOf(suuid).ca = applied; } else { delete stateOf(suuid).ca; }
                    var shown = String($box.find('[data-sv-applied]').attr('data-sv-applied') || '');
                    if (shown) { stateOf(suuid).sv = shown; } else { delete stateOf(suuid).sv; }
                }
                syncTitle($box);
                if (reopen) {
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
                if (replaced($box)) { return; }
                $box.find('.overlay').hide();
                $box.removeClass('loading');
                $box.find('.box-body-inner-body').html(esc(L.error));
                flushPending($box, suuid);
            }
        });
    }

    // The page was rendered again (pjax) while the box was fetching: its suuid — the chart
    // hooks, the toolbar state — belongs to the new page's box now, which loads on its own
    function replaced($box) {
        return !$.contains(document.documentElement, $box[0]);
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
        endPreview();
        $('.ct-pop').removeClass('show');
        $('.ct-btn').removeClass('open');
        $('.ca-open').removeClass('ca-open');
        $('.ctp-open').removeClass('ctp-open');
    }

    // ---- chart type picker: preview ------------------------------------------------------
    // The picker's preview panel draws the box's own data (the chart's data() hook — the
    // plotted points) in the hovered type, the current one otherwise, as Excel's
    // Change Chart Type dialog does: the chart itself is left alone. Drawn with ECharts whatever
    // library draws the real chart; types of one switch pool share their data shape, so no
    // request is needed. Closing the picker disposes the panel's chart.
    var preview = null, previewSeq = 0;

    function endPreview() {
        previewSeq++;
        if (!preview) { return; }
        try { preview.chart.dispose(); } catch (e) {}
        preview = null;
    }

    // $tile: the thumbnail to preview — the hovered one, or the current type's (.on)
    function renderPreview($tile) {
        var $pop = $tile.closest('.ctp-pop'), $panel = $pop.find('.ctp-prev');
        var suuid = $pop.closest('[data-suuid]').data('suuid');
        var hook = window.ExmentCharts && window.ExmentCharts[suuid];
        if (!$tile.length || !hook || typeof hook.data !== 'function') { $panel.hide(); return; }
        var seq = ++previewSeq, type = String($tile.data('ctType'));
        withECharts().done(function () {
            if (seq !== previewSeq || !$pop.hasClass('show')) { return; }
            $panel.show().find('.ctp-prev-name').text($tile.attr('title'));
            placeVertically($pop);
            var el = $panel.find('.ctp-prev-chart')[0];
            try {
                if (!preview || preview.el !== el) {
                    endPreview();
                    preview = { el: el, chart: echarts.init(el) };
                }
                preview.chart.setOption(previewOption(type, previewData(hook.data(), $pop), JSON.parse($pop.attr('data-palette') || '[]')), true);
            } catch (e) {
                endPreview();
                $panel.hide();
            }
        });
    }

    // the data() of a chart with the box's 2つ目の値 (data-second of the picker, ChartItem::secondPreview)
    // while the chart drawn is not the 複合 one, so the 複合 tile previews it too
    function previewData(d, $pop) {
        var second = $pop.attr('data-second');
        if (!second || d.matrix || d.values2) { return d; }
        try { second = JSON.parse(second); } catch (e) { return d; }
        return $.extend({}, d, { axisy2: second.name, values2: second.values || [], second: { type: second.type, right: second.right } });
    }

    // ECharts option of a type for the data() of a single-series ({labels, values}) or a
    // multi-series ({categories, series, matrix}) chart — a sketch of the real renderers
    // (chart / echart / echart_multi blades) at the same data
    function previewOption(type, d, palette) {
        var P = palette.length ? palette : ['#ff6384', '#36a2eb', '#ffce56', '#339900', '#ff6633'];
        var grid = { left: 12, right: 18, top: 34, bottom: 16, containLabel: true };
        var num = function (v) { v = Number(v); return isFinite(v) ? v : 0; };
        var maxOf = function (list) { return Math.max.apply(null, list.length ? list : [1]) || 1; };
        if (d.matrix) {
            var cats = $.map(d.categories || [], String), names = $.map(d.series || [], String);
            var m = $.map(d.matrix || [], function (row) { return [$.map(row || [], num)]; });
            var cell = function (s, x) { return (m[s] && m[s][x]) || 0; };
            switch (type) {
                case 'heatmap': {
                    var heat = [], hmax = 0, th = d.thresholds;
                    if (th && th.classes) {
                        // colored by the box's thresholds, as the heatmap draws them (echart_multi colorTheCells)
                        names.forEach(function (n, s) {
                            cats.forEach(function (c, x) {
                                var k = th.classes[s] ? th.classes[s][x] : null;
                                if (k != null) { heat.push([x, s, cell(s, x), k]); }
                            });
                        });
                        return {
                            grid: { left: 12, right: 12, top: 10, bottom: 34, containLabel: true },
                            xAxis: { type: 'category', data: cats }, yAxis: { type: 'category', data: names },
                            visualMap: { type: 'piecewise', dimension: 3, orient: 'horizontal', left: 'center', bottom: 0, itemGap: 10, itemWidth: 12, itemHeight: 10, textStyle: { fontSize: 10 }, pieces: $.map(th.pieces || [], function (p) { return { value: p.value, color: p.color, label: p.label }; }) },
                            series: [{ type: 'heatmap', data: heat, itemStyle: { borderColor: '#e6e6e6', borderWidth: 1 } }]
                        };
                    }
                    // the scale from the lowest value to the highest, as the heatmap draws it (scaleOf);
                    // an empty cell is none of them
                    var hmin = Infinity;
                    hmax = -Infinity;
                    names.forEach(function (n, s) {
                        cats.forEach(function (c, x) {
                            heat.push([x, s, cell(s, x)]);
                            if (d.matrix[s] && d.matrix[s][x] != null) { hmin = Math.min(hmin, cell(s, x)); hmax = Math.max(hmax, cell(s, x)); }
                        });
                    });
                    if (hmin === Infinity) { hmin = 0; hmax = 1; } else if (hmin === hmax) { hmin = Math.min(0, hmin); hmax = hmax === hmin ? hmin + 1 : hmax; }
                    return {
                        grid: { left: 12, right: 70, top: 10, bottom: 20, containLabel: true },
                        xAxis: { type: 'category', data: cats }, yAxis: { type: 'category', data: names },
                        visualMap: { min: hmin, max: hmax, right: 0, top: 'center', calculable: true, inRange: { color: ['#e0ffff', '#5b8ff9', '#1d39c4'] } },
                        series: [{ type: 'heatmap', data: heat }]
                    };
                }
                case 'treemap':
                case 'sunburst': {
                    var tree = names.map(function (n, s) { return { name: n, children: cats.map(function (c, x) { return { name: c, value: cell(s, x) }; }) }; });
                    return type === 'treemap'
                        ? { color: P, series: [{ type: 'treemap', roam: false, breadcrumb: { show: false }, data: tree, top: 6, bottom: 6, left: 6, right: 6 }] }
                        : { color: P, series: [{ type: 'sunburst', radius: ['15%', '88%'], data: tree }] };
                }
                case 'boxplot': {
                    var q = function (a, p) { var pos = (a.length - 1) * p, b = Math.floor(pos), r = pos - b; return a[b + 1] !== undefined ? a[b] + r * (a[b + 1] - a[b]) : a[b]; };
                    var boxes = cats.map(function (c, x) {
                        var col = names.map(function (n, s) { return cell(s, x); }).sort(function (a, b) { return a - b; });
                        return [col[0], q(col, 0.25), q(col, 0.5), q(col, 0.75), col[col.length - 1]];
                    });
                    return { color: P, grid: grid, xAxis: { type: 'category', data: cats }, yAxis: { type: 'value' }, series: [{ type: 'boxplot', data: boxes }] };
                }
                case 'sbar100':
                case 'shbar100': {
                    // each category as the shares of its series, over its size (ChartShowAs::PERCENT_OF_ROW)
                    var size = cats.map(function (c, x) { return names.reduce(function (t, n, s) { return t + Math.abs(cell(s, x)); }, 0); });
                    var neg = m.some(function (row) { return row.some(function (v) { return v < 0; }); });
                    var shareAxis = { type: 'value', min: neg ? -100 : 0, max: 100, axisLabel: { formatter: '{value}%' } };
                    var catAxis = { type: 'category', data: cats, inverse: type === 'shbar100' };
                    return {
                        color: P, grid: grid, legend: { top: 0, type: 'scroll', data: names },
                        xAxis: type === 'shbar100' ? shareAxis : catAxis, yAxis: type === 'shbar100' ? catAxis : shareAxis,
                        series: names.map(function (n, s) {
                            return { name: n, type: 'bar', stack: 'total', barMaxWidth: 36, data: cats.map(function (c, x) { return size[x] ? cell(s, x) / size[x] * 100 : 0; }) };
                        })
                    };
                }
                default: { // mbar / sbar / mline / sarea
                    var line = type === 'mline' || type === 'sarea', stack = type === 'sbar' || type === 'sarea';
                    return {
                        color: P, grid: grid, legend: { top: 0, type: 'scroll', data: names },
                        xAxis: { type: 'category', data: cats, boundaryGap: !line }, yAxis: { type: 'value' },
                        series: names.map(function (n, s) {
                            var o = { name: n, type: line ? 'line' : 'bar', data: m[s] || [] };
                            if (stack) { o.stack = 'total'; }
                            if (type === 'sarea') { o.areaStyle = { opacity: 0.35 }; }
                            if (line) { o.smooth = true; } else { o.barMaxWidth = 36; }
                            return o;
                        })
                    };
                }
            }
        }
        var labels = $.map(d.labels || [], String), values = $.map(d.values || [], num);
        var pairs = labels.map(function (l, i) { return { name: l, value: values[i] }; });
        switch (type) {
            case 'pie':
                return { color: P, series: [{ type: 'pie', radius: '68%', data: pairs }] };
            case 'doughnut':
                return { color: P, series: [{ type: 'pie', radius: ['40%', '68%'], data: pairs }] };
            case 'funnel':
                return { color: P, series: [{ type: 'funnel', sort: 'descending', gap: 2, left: '8%', right: '8%', top: 10, bottom: 10, data: pairs, label: { formatter: '{b}: {c}' } }] };
            case 'radar':
                return { color: P, radar: { indicator: labels.map(function (l) { return { name: l, max: maxOf(values) }; }), radius: '62%' }, series: [{ type: 'radar', data: [{ value: values, areaStyle: { opacity: 0.2 } }] }] };
            case 'gauge':
                return { color: P, series: [{ type: 'gauge', min: 0, max: maxOf(values), progress: { show: true, width: 14 }, axisLine: { lineStyle: { width: 14 } }, axisTick: { show: false }, detail: { fontSize: 18, formatter: function (v) { return Math.round(v).toLocaleString(); } }, data: [{ name: labels[0] || '', value: values[0] || 0 }] }] };
            case 'hbar':
                return { color: P, grid: grid, xAxis: { type: 'value' }, yAxis: { type: 'category', data: labels, inverse: true }, series: [{ type: 'bar', data: values, barMaxWidth: 36 }] };
            case 'scatter':
                return { color: P, grid: grid, xAxis: { type: 'category', data: labels }, yAxis: { type: 'value' }, series: [{ type: 'scatter', symbolSize: 12, data: values }] };
            case 'combo': { // the 値 as bars on the left axis, the 2つ目の値 on a right one of its own (echart_combo)
                var second = d.second || {}, line2 = second.type !== 'bar', right = second.right !== false;
                var axes = [{ type: 'value' }];
                if (right) { axes.push({ type: 'value', scale: line2, splitLine: { show: false } }); }
                return {
                    color: P, grid: grid, xAxis: { type: 'category', data: labels }, yAxis: axes,
                    series: [{ type: 'bar', data: values, barMaxWidth: 36 },
                        { type: line2 ? 'line' : 'bar', yAxisIndex: right ? 1 : 0, data: $.map(d.values2 || [], num), barMaxWidth: 36, symbol: 'emptyCircle', z: 3 }]
                };
            }
            default: { // bar / line / area
                var isLine = type === 'line' || type === 'area';
                var s = { type: isLine ? 'line' : 'bar', data: values };
                if (type === 'area') { s.areaStyle = { opacity: 0.35 }; }
                if (!isLine) { s.barMaxWidth = 36; }
                return { color: P, grid: grid, xAxis: { type: 'category', data: labels, boundaryGap: !isLine }, yAxis: { type: 'value' }, series: [s] };
            }
        }
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
        // the type picker opens on a preview of the current type; taller than the box's scrolled
        // body, it hangs out of the box like a dropdown while it is open
        if ($pop.hasClass('ctp-pop')) {
            $pop.closest('.box-body-inner-body').addClass('ctp-open');
            renderPreview($pop.find('.ctp-tile.on'));
        }
        placeVertically($pop);
    }

    // A panel that would run past the end of the page (a box of the dashboard's last row: the
    // footer covered it) opens above its button instead, when the page has more room there —
    // hanging out of the box's scrolled body as the type picker does — and the page scrolls
    // up to show it whole. Called again when the picker's preview shows and the panel grows.
    function placeVertically($pop) {
        var item = $pop.closest('.ct-item')[0];
        if (!item || !$pop.hasClass('show')) { return; }
        $pop.removeClass('ct-pop-up');
        var footer = document.querySelector('.main-footer');
        var end = footer ? footer.getBoundingClientRect().top : document.documentElement.scrollHeight - window.scrollY;
        var p = $pop[0].getBoundingClientRect(), i = item.getBoundingClientRect();
        if (p.bottom <= end - 4) { return; }
        // the page's room above the button: down to it from the header (or the page's top)
        var header = document.querySelector('.main-header');
        var above = i.top - (header ? header.getBoundingClientRect().bottom : -window.scrollY);
        if (above < p.height && above <= end - i.bottom) { return; }
        $pop.addClass('ct-pop-up');
        $pop.closest('.box-body-inner-body').addClass('ctp-open');
        var top = $pop[0].getBoundingClientRect().top;
        if (top < 8) {
            window.scrollTo({ top: window.scrollY + top - 8, behavior: 'instant' });
        }
    }

    // A range input is rendered with the data's end (its data-end: the min for "from", the max
    // for "to") when nothing is typed on it, as a Power BI numeric range slicer shows the ends.
    // Left there — or typed back to it, "72" = "72.0" — it bounds nothing and stays off the request.
    function atDataEnd($input) {
        var v = String($input.val() || '').trim(), end = String($input.attr('data-end') || '');
        if (!v || !end) { return false; }
        if (v === end) { return true; }
        var a = Number(v), b = Number(end);
        return !isNaN(a) && !isNaN(b) && a === b;
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
            if (!column || !v || atDataEnd($(this))) { return; }
            if (!sel[column] || $.isArray(sel[column])) { sel[column] = {}; }
            sel[column][bound] = v;
        });
        return sel;
    }

    // ---- summary switch (集計) ------------------------------------------------------------
    // The measure drawn by another summary condition: the box re-renders with `ca`, the server
    // reruns the view's query with it (ChartSummarySwitch). Page-lifetime, never saved: picking
    // the view's own condition, the title's ×, or a reload of the page goes back to the view.
    function applySummary(suuid, ca) {
        if (!suuid) { return; }
        if (ca) { stateOf(suuid).ca = ca; } else { delete stateOf(suuid).ca; }
        loadBox(suuid);
    }

    // The values shown another way (表示方法): the box re-renders with `sv`, the server calculates
    // on the drawn numbers (ChartShowAs). Page-lifetime like `ca`: 計算なし, the title's × or a
    // reload of the page shows them as they are.
    function applyShowAs(suuid, sv) {
        if (!suuid) { return; }
        if (sv) { stateOf(suuid).sv = sv; } else { delete stateOf(suuid).sv; }
        loadBox(suuid);
    }

    // 表示件数: the top N points only, the rest grouped into その他 or left out (ChartTopN). Remembered
    // per user like the sort; すべて, the title's × or the menu's リセット draws every point again.
    function applyTopN(suuid, tn) {
        if (!suuid) { return; }
        if (tn) { stateOf(suuid).tn = tn; } else { delete stateOf(suuid).tn; }
        saveToolbar(suuid);
        loadBox(suuid);
    }

    // 期間の単位: the date on the X axis grouped by another unit, the server rerunning the view's query
    // grouped so (ChartPeriod). Remembered per user like the sort; the view's own unit, the title's ×
    // or the menu's リセット goes back to the view's grouping.
    function applyPeriod(suuid, pu) {
        if (!suuid) { return; }
        if (pu) { stateOf(suuid).pu = pu; } else { delete stateOf(suuid).pu; }
        saveToolbar(suuid);
        loadBox(suuid);
    }

    // the 表示件数 picked in the submenu of $row's menu: "5" / "5:other" as its checkbox says, '' = すべて
    function topNOf($fly, n) {
        if (!n) { return ''; }
        var $other = $fly.find('[data-tn-other]');
        return n + ($other.hasClass('on') && !$other.hasClass('dis') ? ':other' : '');
    }

    // The tooltip of その他 (ChartItem::restTip {index, count, items [[label, value]], more}): the
    // points it groups, largest first, as lines of text (Chart.js) or HTML (ECharts)
    function restNumber(v) {
        return (v == null || isNaN(v)) ? '-' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 10 });
    }
    function restLines(rest) {
        var lines = ['──────────'];
        $.each(rest.items || [], function (i, item) { lines.push(String(item[0]) + ': ' + restNumber(item[1])); });
        if (rest.more > 0) { lines.push(String(L.top_n_more || '').replace('{n}', rest.more)); }
        return lines;
    }
    function restTip(rest) {
        var rows = $.map(rest.items || [], function (item) {
            return '<div style="display:flex;justify-content:space-between;gap:18px;"><span>' + esc(item[0]) + '</span><b>' + esc(restNumber(item[1])) + '</b></div>';
        }).join('');
        if (rest.more > 0) { rows += '<div style="color:#8a8a8a;">' + esc(String(L.top_n_more || '').replace('{n}', rest.more)) + '</div>'; }
        return '<div style="margin-top:6px;padding-top:6px;border-top:1px solid #e6e6ee;font-size:12px;line-height:1.6;">' + rows + '</div>';
    }

    // The target line of a chart (目標線, ChartItem::targetPayload {value, name, text, diff, color}),
    // for the ECharts types: the dashed line (a markLine) named at its end — over its value right
    // of a horizontal line, beside it above a vertical one —, the room right of the plot for that
    // name, the value axis reaching it, a tooltip's line of the gap of a point to it.
    var TARGET_FONT = 'bold 11px sans-serif';
    var targetCtx = null;
    function targetWidth(text) {
        targetCtx = targetCtx || document.createElement('canvas').getContext('2d');
        targetCtx.font = TARGET_FONT;
        return targetCtx.measureText(String(text)).width;
    }
    function chartTargetLine(target, vertical) {
        var name = String(target.name), text = String(target.text);
        return {
            silent: true, symbol: 'none', animation: false,
            lineStyle: { color: target.color, type: [6, 4], width: 2 },
            // a function: a name holding "{b}" is no template. The vertical line runs down the horizontal
            // bar, whose first category is on top (an inverse axis): it starts at the top, named there
            label: { show: true, position: vertical ? 'start' : 'end', distance: 6, color: target.color, fontWeight: 'bold', fontSize: 11, lineHeight: 13,
                formatter: function () { return vertical ? name + ' ' + text : name + '\n' + text; } },
            emphasis: { disabled: true },
            data: [vertical ? { xAxis: target.value } : { yAxis: target.value }]
        };
    }
    function chartTargetRoom(target) {
        return Math.ceil(Math.max(targetWidth(target.name), targetWidth(target.text))) + 14;
    }
    // ECharts' axis spans 0 and the data (a line drawn past it is not counted): past both, the
    // axis ends on a round step beyond the target (1, 2, 3, 5 × 10^n, as ECharts' own steps), a
    // step further when it would end on the target itself, which would lie on the frame
    function chartTargetAxis(axis, value) {
        var end = function (from, to) {
            var span = Math.abs(to - from);
            if (!span) { return to; }
            var raw = span / 5, mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10)), f = raw / mag;
            var step = (f < 1.5 ? 1 : f < 2.5 ? 2 : f < 4 ? 3 : f < 7 ? 5 : 10) * mag, dir = to > from ? 1 : -1;
            var n = dir > 0 ? Math.ceil(to / step) : Math.floor(to / step);
            if (n * step === to) { n += dir; }
            return Number((n * step).toPrecision(12));
        };
        axis.max = function (e) { return value > Math.max(e.max, 0) ? end(Math.min(e.min, 0), value) : null; };
        axis.min = function (e) { return value < Math.min(e.min, 0) ? end(Math.max(e.max, 0), value) : null; };
    }
    // 合格ライン: 60（差 -3）; without a value, the target only
    function chartTargetTip(target, v, fmt) {
        var d = (v == null || v === '' || isNaN(v)) ? null : Math.round((Number(v) - target.value) * 1e10) / 1e10;
        return String(target.name) + ': ' + String(target.text) + (d === null ? '' : String(target.diff).replace('{d}', (d > 0 ? '+' : '') + fmt(d)));
    }

    // The box menu's 集計方法 row opens its submenu beside the menu, as Excel's 値の集計方法 ▸:
    // to the left, the ⋯ button sitting at the box's right edge, else to the right, else (a
    // narrow screen) under the row. Like the type picker, it hangs out of the box's scrolled
    // body while open.
    function openSummary($row) {
        if ($row.hasClass('ca-open')) { return; }
        var PAD = 8, fly = $row.find('.ca-fly-in')[0];
        $row.removeClass('ca-right ca-inline').addClass('ca-open');
        $row.closest('.box-body-inner-body').addClass('ctp-open');
        if (!fly || fly.getBoundingClientRect().left >= PAD) { return; }
        $row.addClass('ca-right');
        if (fly.getBoundingClientRect().right > document.documentElement.clientWidth - PAD) {
            $row.removeClass('ca-right').addClass('ca-inline');
        }
    }

    // While switched, the box title says what the chart draws (the toolbar's data-ca-title:
    // 教科別 最小値 ： 点数) with a × back to the view's setting; the configured title returns
    // otherwise. The configured one is kept on the title element, which a page render replaces.
    function syncTitle($box) {
        var $title = $box.find('.box-title').first(), $bar = $box.find('.exment-chart-toolbar').first();
        var text = $bar.attr('data-ca-title'), saved = $title.data('caSaved');
        if (!$title.length) { return; }
        if (text) {
            if (!saved) { $title.data('caSaved', saved = { html: $title.html(), title: $title.attr('title') }); }
            var $reset = $('<button type="button" class="ca-title-reset" data-ca-reset><i class="fa fa-times"></i></button>')
                .attr('title', $bar.attr('data-ca-reset-label') || '');
            $title.addClass('ca-titled').attr('title', text).empty()
                .append($('<span class="ca-title-text">').text(text), $reset);
        } else if (saved) {
            $title.removeClass('ca-titled').html(saved.html).attr('title', saved.title).removeData('caSaved');
        }
    }

    function applySort(suuid, cs) {
        if (!suuid) { return; }
        if (cs) { stateOf(suuid).cs = cs; } else { delete stateOf(suuid).cs; }
        saveToolbar(suuid);
        loadBox(suuid);
    }

    // display options: kept as the request value ("labels"), applied live through the
    // renderer's hook — no reload — and re-applied by the server on the next one
    function displayOf(suuid) {
        var on = {};
        $.each(String(stateOf(suuid).cd || '').split(','), function (i, k) { if (k) { on[k] = true; } });
        return on;
    }
    function applyDisplay(suuid, on) {
        var keys = [];
        $.each(['labels'], function (i, k) { if (on[k]) { keys.push(k); } });
        var cd = keys.join(',');
        if (cd) { stateOf(suuid).cd = cd; } else { delete stateOf(suuid).cd; }
        saveToolbar(suuid);
        var hook = (window.ExmentCharts || {})[suuid];
        if (hook && typeof hook.display === 'function') { try { hook.display({ labels: !!on.labels }); return; } catch (e) {} }
        loadBox(suuid);
    }
    // the box as an Excel workbook made by the server (DashboardBoxController::excel): the chart as
    // drawn now — the box's request values — its table and Excel's own chart. A box that cannot be
    // exported answers why, shown as an error; so does a response that is no workbook (a login page)
    function exportExcel(suuid) {
        var $box = boxOf(suuid);
        if (!suuid || $box.hasClass('exporting')) { return; }
        $box.addClass('exporting');
        $box.find('.overlay').show();
        var done = function () {
            $box.removeClass('exporting');
            if (!$box.hasClass('loading')) { $box.find('.overlay').hide(); }
        };
        var fail = function (message) {
            if (window.toastr) { window.toastr.error(message || L.excel_failed || L.error); }
        };
        var xhr = new XMLHttpRequest();
        xhr.open('GET', admin_url('dashboardbox/excel/' + suuid) + boxQuery(suuid));
        xhr.responseType = 'blob';
        xhr.onload = function () {
            done();
            var type = String(xhr.getResponseHeader('Content-Type') || '');
            if (xhr.status === 200 && type.indexOf('spreadsheetml') >= 0) {
                // the name the server gives it (box title_date.xlsx), UTF-8 encoded
                var disposition = String(xhr.getResponseHeader('Content-Disposition') || ''), m, name = 'chart.xlsx';
                if ((m = /filename\*=UTF-8''([^;]+)/i.exec(disposition))) {
                    try { name = decodeURIComponent(m[1]); } catch (e) {}
                } else if ((m = /filename="?([^";]+)"?/i.exec(disposition))) {
                    name = m[1];
                }
                var a = document.createElement('a');
                a.href = URL.createObjectURL(xhr.response);
                a.download = name;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
                return;
            }
            if (type.indexOf('json') < 0 || !xhr.response || typeof xhr.response.text !== 'function') { fail(); return; }
            xhr.response.text().then(function (text) {
                var message = null;
                try { message = (JSON.parse(text) || {}).message; } catch (e) {}
                fail(message);
            }, function () { fail(); });
        };
        xhr.onerror = function () { done(); fail(); };
        xhr.send();
    }

    // ---- chart colors -------------------------------------------------------------------
    // A dashboard editor right-clicks a point / slice / series and picks its color, the way
    // Excel's Fill works: the renderers call colorMenu(suuid, {kind, key, color}, event)
    // only when the server allows it (ChartItem::canEditColors). The color is stored on the
    // box for everyone (DashboardBoxController::chartColor), then the box re-renders.
    // the accents of the current Office theme (the old one's #ffc000 duplicated the standard row)
    // plus a darker shade of the first four
    var CC_THEME = ['#156082', '#e97132', '#196b24', '#0f9ed5', '#a02b93', '#4ea72e', '#0a3041', '#75391a', '#0c3612', '#084f6b'];
    var CC_STANDARD = ['#c00000', '#ff0000', '#ffc000', '#ffff00', '#92d050', '#00b050', '#00b0f0', '#0070c0', '#002060', '#7030a0'];

    function closeColorMenu() { $('.cc-pop').remove(); }

    function saveColor(suuid, data) {
        closeColorMenu();
        $.ajax({
            url: admin_url('dashboardbox/chart_color/' + suuid),
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' },
            data: data
        }).done(function () { loadBox(suuid); })
            .fail(function () { if (window.toastr) { window.toastr.error(L.error); } });
    }

    function colorMenu(suuid, target, ev) {
        closeColorMenu();
        closePops();
        var current = String(target.color || '').toLowerCase();
        var swatches = function (list) {
            return '<div class="cc-grid">' + $.map(list, function (c) {
                return '<button type="button" class="cc-swatch' + (c === current ? ' on' : '') + '" data-cc-color="' + c + '" style="background:' + c + '" title="' + c + '"></button>';
            }).join('') + '</div>';
        };
        var start = /^#[0-9a-f]{6}$/.test(current) ? current : '#4472c4';
        var $pop = $('<div class="cc-pop"></div>').html(
            '<h4>' + esc(L.color_theme) + '</h4>' + swatches(CC_THEME)
            + '<h4>' + esc(L.color_standard) + '</h4>' + swatches(CC_STANDARD)
            + '<div class="cc-row" data-cc-auto><i class="fa fa-undo"></i>' + esc(L.color_auto) + '</div>'
            + '<div class="cc-row cc-more"><i class="fa fa-eyedropper"></i>' + esc(L.color_more) + '</div>'
            + '<div class="cc-picker"></div>'
        ).appendTo(document.body);
        var pick = function (color) { saveColor(suuid, { kind: target.kind, key: target.key, color: color }); };
        $pop.on('click', '[data-cc-color]', function () { pick($(this).data('ccColor')); });
        $pop.on('click', '[data-cc-auto]', function () { pick(''); });

        // at the pointer, kept inside the window — again when the picker makes it taller, since
        // scrolling to reach it would close the menu
        var x = (ev && ev.clientX) || 0, y = (ev && ev.clientY) || 0;
        var place = function () {
            var w = $pop.outerWidth(), h = $pop.outerHeight();
            $pop.css({ left: Math.max(4, Math.min(x, window.innerWidth - w - 4)) + 'px', top: Math.max(4, Math.min(y, window.innerHeight - h - 4)) + 'px' });
        };

        // その他の色: a picker drawn in the menu — the browser's own one cannot carry a button —
        // with the same controls (saturation / brightness square, eyedropper where the browser
        // has one, preview, hue, R G B or HEX fields) and OK at its bottom right. Nothing is
        // saved before OK; Esc / a click away keeps the current color. Not `.show` for the
        // picker: Bootstrap 3 forces that class to display:block.
        var $picker = $pop.find('.cc-picker'), hsv = rgbToHsv(hexToRgb(start));
        var hex = function () { return rgbToHex(hsvToRgb(hsv)); };
        var render = function (keep) {
            var rgb = hsvToRgb(hsv);
            $picker.find('.cc-sv').css('background', 'linear-gradient(to top, #000, rgba(0, 0, 0, 0)), linear-gradient(to right, #fff, hsl(' + Math.round(hsv.h) + ', 100%, 50%))');
            $picker.find('.cc-sv-dot').css({ left: (hsv.s * 100) + '%', top: ((1 - hsv.v) * 100) + '%' });
            $picker.find('.cc-preview').css('background', hex());
            if (keep !== 'hue') { $picker.find('.cc-hue').val(Math.round(hsv.h)); }
            if (keep !== 'fields') {
                var hsl = hsvToHsl(hsv);
                $picker.find('[data-cc-ch]').each(function () { this.value = rgb[$(this).data('ccCh')]; });
                $picker.find('[data-cc-hsl]').each(function () { this.value = hsl[$(this).data('ccHsl')]; });
                $picker.find('.cc-hexin').val(hex().toUpperCase()); // with the #, as the browser's picker shows it
            }
        };
        $pop.on('click', '.cc-more', function () {
            if (!$picker.children().length) {
                $picker.html(
                    '<div class="cc-sv"><span class="cc-sv-dot"></span></div>'
                    + '<div class="cc-pick-row">' + (window.EyeDropper ? '<button type="button" class="cc-drop"><i class="fa fa-eyedropper"></i></button>' : '')
                    + '<span class="cc-preview"></span><input type="range" class="cc-hue" min="0" max="359" step="1"></div>'
                    + '<div class="cc-fields cc-m-rgb"><div class="cc-f-rgb">' + $.map(['r', 'g', 'b'], function (ch) {
                        return '<label><input type="number" min="0" max="255" data-cc-ch="' + ch + '"><span>' + ch.toUpperCase() + '</span></label>';
                    }).join('') + '</div>'
                    + '<div class="cc-f-hsl">' + $.map([['h', 359], ['s', 100], ['l', 100]], function (f) {
                        return '<label><input type="number" min="0" max="' + f[1] + '" data-cc-hsl="' + f[0] + '"><span>' + f[0].toUpperCase() + '</span></label>';
                    }).join('') + '</div>'
                    + '<div class="cc-f-hex"><label><input type="text" class="cc-hexin" maxlength="7" spellcheck="false" autocomplete="off"><span>HEX</span></label></div>'
                    + '<button type="button" class="cc-mode" title="RGB / HEX"><i class="fa fa-sort"></i></button></div>'
                    + '<div class="cc-pick-foot"><button type="button" class="cc-ok">' + esc(L.color_ok || 'OK') + '</button></div>'
                );
                render();
            }
            $picker.toggleClass('cc-shown');
            place();
        });
        $pop.on('mousedown', '.cc-sv', function (e) {
            e.preventDefault();
            var el = this;
            var move = function (m) {
                var r = el.getBoundingClientRect();
                hsv.s = Math.min(1, Math.max(0, (m.clientX - r.left) / r.width));
                hsv.v = Math.min(1, Math.max(0, 1 - (m.clientY - r.top) / r.height));
                render();
            };
            move(e);
            $(document).on('mousemove.ccsv', move).one('mouseup.ccsv', function () { $(document).off('.ccsv'); });
        });
        $pop.on('input', '.cc-hue', function () { hsv.h = Number(this.value) || 0; render('hue'); });
        $pop.on('input', '[data-cc-ch]', function () {
            var rgb = {};
            $picker.find('[data-cc-ch]').each(function () { rgb[$(this).data('ccCh')] = Math.min(255, Math.max(0, parseInt(this.value, 10) || 0)); });
            hsv = rgbToHsv(rgb);
            render('fields');
        });
        $pop.on('input', '.cc-hexin', function () {
            var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec($.trim(this.value));
            if (m) { hsv = rgbToHsv(hexToRgb(m[1])); render('fields'); }
        });
        $pop.on('input', '[data-cc-hsl]', function () {
            var hsl = {};
            $picker.find('[data-cc-hsl]').each(function () {
                var k = $(this).data('ccHsl');
                hsl[k] = Math.min(k === 'h' ? 359 : 100, Math.max(0, parseInt(this.value, 10) || 0));
            });
            hsv = hslToHsv(hsl);
            render('fields');
        });
        // the browser's picker cycles RGB / HSL / HEX, so this one does too
        $pop.on('click', '.cc-mode', function () {
            var $f = $picker.find('.cc-fields'), modes = ['cc-m-rgb', 'cc-m-hsl', 'cc-m-hex'];
            var at = 0;
            $.each(modes, function (i, m) { if ($f.hasClass(m)) { at = i; } });
            $f.removeClass(modes.join(' ')).addClass(modes[(at + 1) % modes.length]);
            render();
        });
        $pop.on('click', '.cc-drop', function () {
            new window.EyeDropper().open().then(function (r) { hsv = rgbToHsv(hexToRgb(r.sRGBHex)); render(); }).catch(function () {});
        });
        $pop.on('click', '.cc-ok', function () {
            var v = hex();
            if (v !== start) { pick(v); } else { closeColorMenu(); }
        });
        place();
    }

    // color conversions of the menu's picker: {r, g, b} 0-255, {h 0-360, s 0-1, v 0-1}
    function hexToRgb(h) {
        var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(h || '')), s = m ? m[1] : '000000';
        if (s.length === 3) { s = s.replace(/(.)/g, '$1$1'); }
        return { r: parseInt(s.slice(0, 2), 16), g: parseInt(s.slice(2, 4), 16), b: parseInt(s.slice(4, 6), 16) };
    }
    function rgbToHex(c) {
        return '#' + $.map([c.r, c.g, c.b], function (n) { var s = Math.round(n).toString(16); return s.length < 2 ? '0' + s : s; }).join('');
    }
    function rgbToHsv(c) {
        var r = c.r / 255, g = c.g / 255, b = c.b / 255, max = Math.max(r, g, b), d = max - Math.min(r, g, b), h = 0;
        if (d) { h = max === r ? ((g - b) / d) % 6 : (max === g ? (b - r) / d + 2 : (r - g) / d + 4); }
        return { h: (h * 60 + 360) % 360, s: max ? d / max : 0, v: max };
    }
    function hsvToRgb(c) {
        var f = function (n) { var k = (n + c.h / 60) % 6; return Math.round(255 * (c.v - c.v * c.s * Math.max(0, Math.min(k, 4 - k, 1)))); };
        return { r: f(5), g: f(3), b: f(1) };
    }
    // the HSL the fields show (s / l as whole percents), and back
    function hsvToHsl(c) {
        var l = c.v * (1 - c.s / 2), m = Math.min(l, 1 - l);
        return { h: Math.round(c.h), s: Math.round((m ? (c.v - l) / m : 0) * 100), l: Math.round(l * 100) };
    }
    function hslToHsv(c) {
        var l = c.l / 100, s = c.s / 100, v = l + s * Math.min(l, 1 - l);
        return { h: c.h, s: v ? 2 * (1 - l / v) : 0, v: v };
    }

    function reloadFromToolbar($el, reset) {
        var $box = $el.closest('[data-suuid]'), suuid = $box.data('suuid');
        if (!suuid) { return; }
        if (reset) { delete stateOf(suuid).bf; } else { stateOf(suuid).bf = collectChartFilter($box); }
        stateOf(suuid).reopen = true;
        loadBox(suuid);
    }

    // ---- filter bar -------------------------------------------------------------------
    function initFilterBar() {
        $('.exment-df-bar .df-select').each(function () {
            var $s = $(this);
            if ($s.hasClass('select2-hidden-accessible')) { return; }
            $s.select2({ width: '100%', closeOnSelect: false, placeholder: $s.data('placeholder') || '' });
            $s.data('applied', ($s.val() || []).slice());
        });
        initSliders();
    }

    // ---- ECharts (lazy) ---------------------------------------------------------------
    // The ECharts bundle (1 MB) is not loaded with every admin page: the first ECharts box of a
    // dashboard fetches it from the URL init passes (options.assets.echarts) and the box blades
    // render inside withECharts(suuid).done(...). Resolved at once when it is already there;
    // when the fetch fails, the box of `suuid` says so instead of staying blank.
    var echartsLoading = null;
    function withECharts(suuid) {
        var loading;
        if (window.echarts) {
            loading = $.Deferred().resolve().promise();
        } else if (echartsLoading) {
            loading = echartsLoading;
        } else if (!A.echarts) {
            loading = $.Deferred().reject().promise();
        } else {
            echartsLoading = loading = $.ajax({ url: A.echarts, dataType: 'script', cache: true })
                .fail(function () { echartsLoading = null; }); // a failed fetch is retried by the next box
        }
        if (suuid) { loading.fail(function () { chartError(suuid, L.chart_load_error); }); }
        return loading;
    }
    // Why a chart box is empty, in its chart area (the echart blades use it for a render failure too)
    function chartError(suuid, message) {
        $('[data-echart-id="' + suuid + '"]').html($('<div class="exment-chart-error">').text(String(message == null ? '' : message)));
    }

    // ---- range slider -----------------------------------------------------------------
    // A number range item carries a two-handle slider under its inputs (filter_bar.blade
    // data-slider), like a Power BI numeric range slicer. The slider is the admin layer's own
    // Ion.RangeSlider (1.8, its Slider form field's), fetched on first use from the base URL
    // init passes. Dragging moves the numbers in the inputs live; releasing applies them as
    // a typed bound would (a handle left at its end bounds nothing). A typed bound reaches
    // the slider through the bar's re-render, which rebuilds it from the inputs.
    var ionLoading = null;
    function withIonSlider() {
        if ($.fn.ionRangeSlider) { return $.Deferred().resolve().promise(); }
        if (ionLoading) { return ionLoading; }
        var base = String(A.ionslider || '').replace(/\/$/, '');
        if (!base) { return $.Deferred().reject().promise(); }
        if (!$('#exment-ionslider-css').length) {
            $('<link id="exment-ionslider-css" rel="stylesheet">').attr('href', base + '/ion.rangeSlider.css').appendTo('head');
        }
        ionLoading = $.getScript(base + '/ion.rangeSlider.min.js')
            .fail(function () { ionLoading = null; }); // a failed fetch is retried by the next bar
        return ionLoading;
    }
    // the decimals the two ends are written with give the step: 1 for integers, 0.1 for "72.5"
    function stepFor(a, b) {
        var d = 0;
        $.each([a, b], function (i, s) { var m = /\.(\d+)$/.exec(String(s)); if (m && m[1].length > d) { d = m[1].length; } });
        return { step: Math.pow(10, -d), decimals: d };
    }
    // Ion binds its drag handlers to <body> and window, which outlive the bar: a slider is
    // removed (its handlers with it) before its bar is replaced or left
    var sliders = [];
    function removeSliders() {
        $.each(sliders, function (i, $input) {
            try { $input.ionRangeSlider('remove'); } catch (e) {}
        });
        sliders = [];
    }
    // A handle held down: the bar is not replaced under it — its release applies the drag
    // from the inputs of this bar. Ion stops the mousedown from bubbling, so it is seen on
    // its way down (capture), once per document.
    var sliding = false, slidingWatched = false;
    function watchSliding() {
        if (slidingWatched) { return; }
        slidingWatched = true;
        var down = function (ev) { sliding = !!$(ev.target).closest('.exment-df-bar .irs').length; };
        var up = function () { sliding = false; };
        document.addEventListener('mousedown', down, true);
        document.addEventListener('touchstart', down, true);
        document.addEventListener('mouseup', up, true);
        document.addEventListener('touchend', up, true);
    }
    function initSliders() {
        var $ranges = $('.exment-df-bar .df-range[data-slider]').filter(function () { return !$(this).find('.irs').length; });
        if (!$ranges.length) { return; }
        withIonSlider().done(function () {
            $ranges.each(function () {
                var $r = $(this), $from = $r.find('[data-bound="from"]'), $to = $r.find('[data-bound="to"]'), $wrap = $r.find('.df-slider-wrap');
                var min = Number($from.attr('data-end')), max = Number($to.attr('data-end'));
                if (!$wrap.length || isNaN(min) || isNaN(max) || min >= max) { return; }
                var s = stepFor($from.attr('data-end'), $to.attr('data-end'));
                // an empty input keeps its end (Number('') would be 0)
                var clamp = function (v, dflt) { v = $.trim(String(v == null ? '' : v)); var n = Number(v); return (v === '' || isNaN(n)) ? dflt : Math.min(max, Math.max(min, n)); };
                var show = function (n) { return String(Number(Number(n).toFixed(s.decimals))); };
                var $input = $('<input type="hidden" class="df-slider">').appendTo($wrap.empty());
                sliders.push($input);
                // a typed bound outside today's data range sits clamped on the slider: only the
                // input of the handle being dragged takes the slider's value, the other keeps
                // what it holds (60 stays 60 while the data starts at 80)
                var held = { from: clamp($from.val(), min), to: clamp($to.val(), max) };
                $input.ionRangeSlider({
                    type: 'double', min: min, max: max, step: s.step,
                    from: held.from, to: held.to,
                    hideMinMax: true, hideFromTo: true, hasGrid: false,
                    onChange: function (o) {
                        // moved = more than half a step away (the slider rounds to its step)
                        var moved = function (a, b) { return Math.abs(Number(a) - Number(b)) > s.step / 2; };
                        if (moved(o.fromNumber, held.from)) {
                            $from.val(show(o.fromNumber)).toggleClass('active', !atDataEnd($from));
                        }
                        if (moved(o.toNumber, held.to)) {
                            $to.val(show(o.toNumber)).toggleClass('active', !atDataEnd($to));
                        }
                        held = { from: o.fromNumber, to: o.toNumber };
                    },
                    onFinish: function () { navigate(filterBarUrl()); }
                });
            });
        });
    }

    function selectChanged($s) {
        var a = ($s.data('applied') || []).slice().sort(), b = ($s.val() || []).slice().sort();
        return a.join('') !== b.join('');
    }

    function filterBarUrl() {
        var $bar = $('.exment-df-bar'), params = new URLSearchParams();
        var dashboard = $bar.data('dashboard-suuid');
        if (dashboard) { params.set('dashboard', dashboard); }
        $bar.find('.df-select').each(function () {
            var column = $(this).data('column'), values = $(this).val() || [];
            if (values.length === 1) { params.set('df_' + column, values[0]); }
            else { values.forEach(function (v) { params.append('df_' + column + '[]', v); }); }
        });
        $bar.find('.df-range').each(function () {
            var column = $(this).data('column');
            $(this).find('.df-range-input').each(function () {
                var v = String($(this).val() || '').trim();
                if (v && !atDataEnd($(this))) { params.set('df_' + column + '[' + $(this).data('bound') + ']', v); }
            });
        });
        // a bar the user emptied (every value removed) is marked dfr=1: the server remembers it
        // empty, so the next entry does not re-apply the configured defaults
        var hasDf = false;
        params.forEach(function (v, k) { if (k.indexOf('df_') === 0) { hasDf = true; } });
        if (!hasDf) { params.set('dfr', '1'); }
        var query = params.toString();
        return pagePath + (query ? '?' + query : '');
    }

    // Click-to-filter from a chart: select `value` on the bar's `column` item — click = only
    // this value (clicking the sole selected value again clears it), Ctrl/⌘-click = toggle it
    // within the current selection — then navigate like a bar change.
    function pick(column, value, toggle) {
        var $s = $('.exment-df-bar .df-select[data-column="' + column + '"]');
        value = String(value == null ? '' : value);
        // the option is found by comparing values: a value is free text, and a backslash or
        // a quote in it would break a selector
        var known = $s.find('option').filter(function () { return this.value === value; }).length > 0;
        if (!$s.length || value === '' || !known) { return; }
        var current = ($s.val() || []).slice(), values;
        if (toggle) {
            values = current.indexOf(value) >= 0 ? current.filter(function (v) { return v !== value; }) : current.concat([value]);
        } else {
            values = (current.length === 1 && current[0] === value) ? [] : [value];
        }
        $s.val(values);
        navigate(filterBarUrl());
    }

    // ---- 明細: a double click on a chart point lists the rows behind it -----------------
    // A point answers a click (pick it on the bar, above) and a double click (its 明細, Excel's
    // 詳細の表示 — ChartItem::detailPayload). Both come through the chart's own click events,
    // a mouse's and a finger's alike, so a double tap on a phone is one too. On a chart that
    // has a 明細 the click waits DBL ms for a second one on the same point: a double click
    // never toggles the pick. A chart without one clicks at once, as before.
    var DBL = 300;
    var waiting = null; // {suuid, key, single, timer}: a click waiting for its second

    function fireWaiting() {
        var p = waiting;
        waiting = null;
        if (p) { clearTimeout(p.timer); if (p.single) { p.single(); } }
    }

    // key: the point within its box; single: the click's action (null: none); detail: the
    // double click's (null: the chart has no 明細)
    function pointClick(suuid, key, single, detail) {
        if (!detail) { if (single) { single(); } return; }
        if (waiting && waiting.suuid === suuid && waiting.key === key) {
            clearTimeout(waiting.timer);
            waiting = null;
            detail();
            return;
        }
        fireWaiting(); // a click on another point: the one before was a click
        waiting = { suuid: suuid, key: key, single: single, timer: setTimeout(fireWaiting, DBL) };
    }

    // The data list of the point's rows: the box is rebuilt there from its request params,
    // the point named by the stored values of its groups (ChartDetailRequest)
    function openDetail(suuid, url, keys) {
        var param = JSON.stringify({ b: suuid, k: keys, q: boxQuery(suuid).replace(/^\?/, '') });
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'chart_detail=' + encodeURIComponent(param);
        if ($.pjax) { $.pjax({ url: url, container: '#pjax-container' }); } else { window.location.href = url; }
    }

    // the tooltip's hint line: a finger double-taps
    function detailHint() {
        var touch = window.matchMedia && window.matchMedia('(hover: none)').matches;
        return touch ? (L.detail_hint_touch || '') : (L.detail_hint || '');
    }

    // ---- selective apply --------------------------------------------------------------
    // A bar change reloads ONLY the boxes the changed items narrow (data-df-dims, written
    // per box by DashboardController): the others keep their rendered content — no spinner
    // that could read as "this chart was filtered too". The URL still updates (a history
    // entry, so F5 / back keep working) and the bar re-renders its option lists from a
    // partial request. Anything unexpected falls back to a full pjax render.
    var barReq = 0;

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
    // without a re-fetch. No-op on boxes without data-df-dims (server badge is the truth) and
    // on boxes without a table (data-df-badge="none": the server never badges them).
    function syncBadge($box) {
        if ($box.attr('data-df-dims') === undefined || $box.attr('data-df-badge') === 'none') { return; }
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
    // no longer offers stay listed so they can be removed (mirror of FilterBarView).
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
                opts.unshift($('<option>').val(v).text($old.length ? $old.text() : v));
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
        var busy = sliding || $bar.find('.df-select').toArray().some(function (el) {
            try { return $(el).data('select2') && $(el).select2('isOpen'); } catch (e) { return false; }
        }) || ($.contains($bar[0], active) && (
            $(active).hasClass('df-range-input')                                             // typing a range bound
            || ($(active).hasClass('select2-search__field') && String(active.value || '') !== '') // typed search text
        ));
        if (busy) { setTimeout(function () { applyFilterBar($fresh, token); }, 250); return; }
        $bar.find('.df-select').each(function () {
            try { $(this).select2('destroy'); } catch (e) {}
        });
        removeSliders();
        $bar.replaceWith($fresh);
        initFilterBar();
    }

    // pjax's request is sent and not answered yet (readyState 0 again once aborted)
    function rendering() {
        var xhr = $.pjax && $.pjax.xhr;
        return !!xhr && xhr.readyState > 0 && xhr.readyState < 4;
    }

    function fullNavigate(url) {
        // an open dropdown is attached to <body> and would survive the pjax swap as an orphan
        $('.exment-df-bar .df-select').each(function () {
            try { $(this).select2('close'); } catch (e) {}
        });
        if ($.pjax) { $.pjax({ url: url, container: '#pjax-container' }); } else { window.location.href = url; }
    }

    // ---- history ----------------------------------------------------------------------
    // A filter change gets a history entry of its own, written as one of pjax's: an entry
    // whose state has pjax's shape (id, url, container) is restored by pjax on back /
    // forward through a full render of its URL — fetched afresh each time, open-admin keeps
    // no pjax cache (maxCacheLength 0) — and pjax's notion of the current entry
    // ($.pjax.state) follows ours, or it would skip the entry it still believes current.
    // The entry the dashboard was rendered in becomes such an entry too when it is not one
    // yet (a full page load), and its URL says explicitly that the bar is empty (dfr) when
    // it carries no filter state: fetched again on the way back, a URL without one is
    // redirected to this user's remembered selection (DashboardController::
    // filterDefaultRedirect), and the entry would show another state than its URL.
    var lastId = 0;

    // pjax reads the navigation direction off its ids (timestamps): strictly increasing
    function entryState(url) {
        lastId = Math.max(Date.now(), lastId + 1);
        return { id: lastId, url: url, title: document.title, container: '#pjax-container' };
    }

    // `href` with its filter state made explicit: dfr=1 when no df_ value and no dfr is on it
    function explicitUrl(href) {
        var u = new URL(href), explicit = false;
        u.searchParams.forEach(function (v, k) { if (k === 'dfr' || (k.indexOf('df_') === 0 && v !== '')) { explicit = true; } });
        if (!explicit) { u.searchParams.set('dfr', '1'); }
        return u.href;
    }

    function pushEntry(url) {
        var current = explicitUrl(window.location.href), st = window.history.state;
        st = (st && st.container) ? $.extend({}, st, { url: current }) : entryState(current);
        window.history.replaceState(st, '', current);
        $.pjax.state = st;
        st = entryState(new URL(url, window.location.href).href);
        window.history.pushState(st, '', st.url);
        $.pjax.state = st;
    }

    // The entry's URL with the selection the page was rendered with (the bar's data-query),
    // when they differ: fetched for back / forward, a URL without filter state is redirected
    // to the remembered selection, and pjax keeps the entry's own URL — the boxes, which take
    // the selection off the URL (dfParams), would show unfiltered data under that bar.
    function alignEntry() {
        var query = $('.exment-df-bar').attr('data-query');
        if (query == null || !window.history.replaceState) { return; }
        if (!changedColumns(window.location.search, '?' + query).length) { return; }
        var href = new URL(pagePath + '?' + query, window.location.href).href, st = window.history.state;
        if (st && st.container) {
            st = $.extend({}, st, { url: href });
            if ($.pjax && $.pjax.state && $.pjax.state.id === st.id) { $.pjax.state = st; }
        }
        window.history.replaceState(st, '', href);
    }

    function navigate(url) {
        $('.exment-df-bar .df-select').each(function () {
            $(this).data('applied', ($(this).val() || []).slice());
            try { $(this).select2('close'); } catch (e) {}
        });
        if (url === window.location.pathname + window.location.search) { return; }
        var $boxes = $('[data-suuid]').filter(function () { return $(this).data('suuid'); });
        var canPartial = $.pjax && window.history && window.history.pushState && $boxes.length
            && $boxes.filter('[data-df-dims]').length === $boxes.length;
        // a pjax render under way (back / forward, a link) is about to replace the page this
        // change was made on: render the change instead — a new pjax request drops that one
        if (!canPartial || rendering()) { fullNavigate(url); return; }
        var changed = changedColumns(window.location.search, url.indexOf('?') >= 0 ? url.slice(url.indexOf('?')) : '');
        pushEntry(url);
        $boxes.each(function () {
            var $box = $(this), suuid = $box.data('suuid'), dims = boxDims($box), hit = [];
            $.each(changed, function (i, column) { if (dims.indexOf(column) >= 0) { hit.push(column); } });
            if (!hit.length) { syncBadge($box); return; }
            // only the chart's own X item changed, and to a pick (not a from / to, which the
            // server filters by): same data, repaint the pick in place (a box mid-fetch
            // reloads instead — its request may predate this URL)
            var hook = (window.ExmentCharts || {})[suuid], picked = hit.length === 1 ? dfValues(hit[0]) : null;
            if (picked !== null && hook && hook.self === hit[0] && typeof hook.highlight === 'function' && !$box.hasClass('loading')) {
                try {
                    hook.highlight(picked);
                    syncBadge($box);
                    return;
                } catch (e) {}
            }
            loadBox(suuid);
        });
        refreshFilterBar(url);
    }

    // Handler of a range bound input: apply($input) once a bound is entered. A number input
    // fires change once left (or on Enter). A date input fires it at every digit typed into
    // its year (2025: 0002, 0020, 0202, 2025): a typed date applies once the input is left
    // (Enter leaves it), a day picked on its calendar at once, as before.
    function rangeEdited(apply) {
        return function (ev) {
            var el = this, $el = $(el);
            if (el.type !== 'date') {
                if (ev.type === 'change') { apply($el); }
                return;
            }
            switch (ev.type) {
                case 'focusin': $el.data('entered', el.value).removeData('typing'); return;
                case 'keydown': $el.data('typing', true); return;
                case 'change': if ($el.data('typing')) { return; } break;
                case 'focusout': $el.removeData('typing'); break;
            }
            if (el.value === $el.data('entered')) { return; }
            $el.data('entered', el.value);
            apply($el);
        };
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
        $(document).on('change' + NS + ' focusin' + NS + ' focusout' + NS + ' keydown' + NS, '.exment-df-bar .df-range-input', rangeEdited(function () { navigate(filterBarUrl()); }));
        // リセット: back to the configured defaults (the bar renders their URL query)
        $(document).on('click' + NS, '.exment-df-bar .df-reset', function () {
            navigate(pagePath + '?' + String($(this).closest('.exment-df-bar').attr('data-reset-query') || ''));
        });

        // chart toolbar
        $(document).on('click' + NS, function (ev) {
            var $btn = $(ev.target).closest('[data-ct-pop], [data-cs-pop]');
            if ($btn.length) {
                if ($btn.hasClass('open')) { closePops(); } else { openPop($btn); }
                return;
            }
            if (!$(ev.target).closest('.ct-pop').length) { closePops(); }
        });
        $(document).on('change' + NS, '.exment-ct-switch', function () {
            var suuid = $(this).closest('[data-suuid]').data('suuid'), ct = String($(this).val() || '');
            // picking the box's own type drops the choice: the box follows its setting again
            if (ct && ct !== String($(this).attr('data-configured') || '')) { stateOf(suuid).ct = ct; } else { delete stateOf(suuid).ct; }
            saveToolbar(suuid);
            loadBox(suuid);
        });
        // type picker: a thumbnail drives the hidden switcher above; hover previews the type
        $(document).on('click' + NS, '[data-ct-type]', function () {
            var $tile = $(this), $switch = $tile.closest('.ctp-item').find('.exment-ct-switch');
            closePops();
            if ($tile.hasClass('on')) { return; }
            $switch.val(String($tile.data('ctType'))).trigger('change');
        });
        $(document).on('mouseenter' + NS + ' focusin' + NS, '[data-ct-type]', function () { renderPreview($(this)); });
        $(document).on('mouseleave' + NS, '.ctp-grid', function () { renderPreview($(this).find('.ctp-tile.on')); });
        // sort menu (Power BI's "Sort by"): a field row or a direction row applies at once and the
        // box re-renders (menu closed, like Power BI). A direction alone sorts by the measure (the
        // last field); a field alone takes its natural direction — high to low for the measure,
        // A to Z for a text column.
        $(document).on('click' + NS, '[data-cs-field], [data-cs-dir]', function () {
            var $pop = $(this).closest('.cs-pop');
            var field = $(this).data('csField') || $pop.find('[data-cs-field].on').data('csField') || $pop.find('[data-cs-field]').last().data('csField');
            var dir = $(this).data('csDir') || $pop.find('[data-cs-dir].on').data('csDir') || (field === 'y' ? 'desc' : 'asc');
            applySort($(this).closest('[data-suuid]').data('suuid'), field ? field + ':' + dir : '');
        });
        // 集計方法 row: the pointer opens its submenu, so does a tap (no hover on a touch screen);
        // it closes with the pointer leaving or the menu closing
        $(document).on('mouseenter' + NS + ' click' + NS, '[data-ca-open]', function () { openSummary($(this)); });
        $(document).on('mouseleave' + NS, '[data-ca-open]', function () { $(this).removeClass('ca-open'); });
        // a condition applies at once (the view's own one drops the switch)
        $(document).on('click' + NS, '[data-ca-pick]', function () {
            var $row = $(this), ca = String($row.data('caPick'));
            var configured = String($row.closest('[data-ca-configured]').attr('data-ca-configured') || '');
            closePops();
            if ($row.hasClass('on')) { return; }
            applySummary($row.closest('[data-suuid]').data('suuid'), ca === configured ? '' : ca);
        });
        // 表示方法: a way applies at once (計算なし drops it)
        $(document).on('click' + NS, '[data-sv-pick]', function () {
            var $row = $(this), sv = String($row.data('svPick'));
            closePops();
            if ($row.hasClass('on')) { return; }
            applyShowAs($row.closest('[data-suuid]').data('suuid'), sv === 'none' ? '' : sv);
        });
        // 期間の単位: a unit applies at once (the view's own one drops the switch)
        $(document).on('click' + NS, '[data-pu-pick]', function () {
            var $row = $(this), pu = String($row.attr('data-pu-pick') || '');
            var configured = String($row.closest('[data-pu-configured]').attr('data-pu-configured') || '');
            closePops();
            if ($row.hasClass('on')) { return; }
            applyPeriod($row.closest('[data-suuid]').data('suuid'), pu === configured ? '' : pu);
        });
        // 表示件数: a number applies at once (すべて drops it)
        $(document).on('click' + NS, '[data-tn-pick]', function () {
            var $row = $(this), $fly = $row.closest('.ca-fly-in');
            closePops();
            if ($row.hasClass('on')) { return; }
            applyTopN($row.closest('[data-suuid]').data('suuid'), topNOf($fly, String($row.data('tnPick') || '')));
        });
        // its checkbox: redraws a chart cut to its top N, else waits for a number to be picked
        $(document).on('click' + NS, '[data-tn-other]', function () {
            var $row = $(this), $fly = $row.closest('.ca-fly-in');
            if ($row.hasClass('dis')) { return; }
            $row.toggleClass('on');
            var n = String($fly.find('[data-tn-pick].on').data('tnPick') || '');
            if (!n) { return; }
            closePops();
            applyTopN($row.closest('[data-suuid]').data('suuid'), topNOf($fly, n));
        });
        // the title's ×: back to what the view draws, its summary type, its plain values, every point and its date grouping
        $(document).on('click' + NS, '[data-ca-reset]', function () {
            var suuid = $(this).closest('[data-suuid]').data('suuid');
            if (suuid) {
                delete stateOf(suuid).sv;
                if (stateOf(suuid).tn || stateOf(suuid).pu) { delete stateOf(suuid).tn; delete stateOf(suuid).pu; saveToolbar(suuid); }
            }
            applySummary(suuid, '');
        });
        $(document).on('click' + NS, '[data-cd-toggle]', function () {
            var $row = $(this), $box = $row.closest('[data-suuid]'), suuid = $box.data('suuid'), on = displayOf(suuid);
            on[$row.data('cdToggle')] = !on[$row.data('cdToggle')];
            $row.toggleClass('on', !!on[$row.data('cdToggle')]);
            applyDisplay(suuid, on);
        });
        $(document).on('click' + NS, '[data-cx-export]', function () {
            closePops();
            exportExcel($(this).closest('[data-suuid]').data('suuid'));
        });
        $(document).on('click' + NS, '[data-cc-reset]', function () {
            closePops();
            saveColor($(this).closest('[data-suuid]').data('suuid'), { reset: 1 });
        });
        // the color menu closes on a press outside it, Esc, a scroll or a resize
        $(document).on('mousedown' + NS, function (ev) {
            if (!$(ev.target).closest('.cc-pop').length) { closeColorMenu(); }
        });
        $(document).on('keydown' + NS, function (ev) { if (ev.key === 'Escape') { closeColorMenu(); } });
        $(window).on('scroll' + NS + ' resize' + NS, closeColorMenu);
        $(document).on('click' + NS, '.exment-cs-reset', function () {
            var suuid = $(this).closest('[data-suuid]').data('suuid');
            delete stateOf(suuid).cd;
            // the menu's 期間の単位, 集計方法, 表示方法 and 表示件数 too: back to the view's setting
            delete stateOf(suuid).pu;
            delete stateOf(suuid).ca;
            delete stateOf(suuid).sv;
            delete stateOf(suuid).tn;
            applySort(suuid, '');
        });
        $(document).on('change' + NS, '.exment-bf-check', function () { reloadFromToolbar($(this), false); });
        $(document).on('change' + NS + ' focusin' + NS + ' focusout' + NS + ' keydown' + NS, '.exment-bf-range', rangeEdited(function ($input) { reloadFromToolbar($input, false); }));
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
    }

    window.ExmentDashboard = {
        // `state` deliberately survives init(): a filter-bar change re-renders the page through
        // pjax, and the boxes must come back with the chart type / chart filter they had
        init: function (options) {
            L = (options && options.lang) || {};
            A = (options && options.assets) || {};
            pagePath = window.location.pathname;
            alignEntry();     // before any box reads the selection off the URL
            closeColorMenu(); // a menu left open on <body> across a pjax render
            barReq++;         // a bar refresh still in flight is the page before's: never swapped in here
            removeSliders();  // the page before's
            watchSliding();
            bind();
            initFilterBar();
            var $boxes = $('[data-suuid]');
            $boxes.parents('.row').addClass('row-eq-height row-dashboard');
            var charts = (options && options.charts) || {}; // suuid => {ct, cs, cd, tn, pu} remembered for this user
            $boxes.each(function () {
                var suuid = $(this).data('suuid'), saved = suuid ? charts[suuid] : null;
                if (saved) {
                    $.each(['ct', 'cs', 'cd', 'tn', 'pu'], function (i, k) {
                        if (saved[k] && !stateOf(suuid)[k]) { stateOf(suuid)[k] = saved[k]; }
                    });
                }
                loadBox(suuid);
            });
        },
        load: loadBox,
        pick: pick,
        pointClick: pointClick,
        openDetail: openDetail,
        detailHint: detailHint,
        restTip: restTip,
        restLines: restLines,
        chartTargetLine: chartTargetLine,
        chartTargetRoom: chartTargetRoom,
        chartTargetAxis: chartTargetAxis,
        chartTargetTip: chartTargetTip,
        colorMenu: colorMenu,
        withECharts: withECharts,
        chartError: chartError
    };
})(jQuery);

/**
 * Dashboard SETTING form — the filter bar's items table (FilterBarForm). init() runs on every
 * render of the form (pjax included) with what the page alone cannot know: the two linkage
 * endpoints, the dashboard id and a few texts.
 *
 *   columnsUrl  dashboard/filter_bar_columns ?q=table            → column choices of a table
 *   valuesUrl   dashboard/filter_bar_values ?table&column&dashboard → what the デフォルト値 cell
 *               renders for a column: {kind:'select', options, capped} a picker of the stored
 *               values, {kind:'range', input, min, max} from / to inputs (the data's ends as
 *               placeholders — here empty means "no default", so nothing is prefilled)
 *
 * The デフォルト値 text input keeps carrying the POSTED value (Dashboard::filter_bar_dims); the
 * widget drawn in front of it only writes into it, in the formats FilterBarConfig::defaultQuery
 * reads: picked values joined by ',' for a list column, "from~to" for a range column (either
 * side may be empty).
 */
window.ExmentDashboardForm = (function ($) {
    'use strict';

    var NS = '.exdfform';
    var TABLE = '#has-many-table-filter_bar_dims-table';
    var opt = {};

    function esc(s) { return $('<i>').text(String(s == null ? '' : s)).html(); }
    function rows() { return $(TABLE + ' tbody tr:visible'); }
    function sourceTable() { return $('select[name="filter_bar_table"]').val(); }

    // A row added with "+ new" is cloned from a template rendered before any table was picked:
    // fill its column select from a sibling row, or from the linkage endpoint.
    function fillNewRow(e) {
        if (!$(e.target).closest('.add').length) { return; }
        var empty = rows().last().find('select.column').filter(function () { return !this.value; });
        if (!empty.length) { return; }
        var fill = function (html) { empty.each(function () { $(this).html(html).val('').trigger('change.select2'); }); };
        var loaded = $(TABLE + ' select.column').not(empty).filter(function () { return this.options.length > 1; }).first();
        if (loaded.length) { fill(loaded.html()); return; }
        var table = sourceTable();
        if (!table) { return; }
        $.get(opt.columnsUrl, { q: table }, function (data) {
            var html = '<option value=""></option>';
            $.each(data, function (i, d) { html += '<option value="' + esc(d.id) + '">' + esc(d.text) + '</option>'; });
            fill(html);
        });
    }

    // The widget in front of a row's デフォルト値 input, for the column the row has now.
    function defaultInit(tr) {
        var inp = tr.find('input.default');
        if (!inp.length) { return; }
        var group = inp.closest('.input-group');
        var shell = group.length ? group : inp;
        var old = tr.find('.df-default-picker');
        if (old.length) { try { old.select2('destroy'); } catch (e) {} }
        tr.find('.df-default-picker, .df-default-range').remove();
        shell.show();
        var table = sourceTable(), column = tr.find('select.column').val();
        if (!table || !column) { return; }
        $.get(opt.valuesUrl, { table: table, column: column, dashboard: opt.dashboardId }, function (res) {
            if (tr.find('select.column').val() !== column || tr.find('.df-default-picker, .df-default-range').length) { return; }
            var current = String(inp.val() || '');
            if (res.kind === 'range') {
                // a single exact value stored before the column became a range shows as the
                // range it equals (from = to); several exact values have no range to show
                if (current !== '' && current.indexOf('~') < 0) {
                    if (current.indexOf(',') >= 0) { return; }
                    current = current + '~' + current;
                }
                rangeWidget(shell, inp, res, current);
            } else if (!res.capped) {
                pickerWidget(shell, inp, res, current);
            }
            // a text column too long to list keeps the plain input
        });
    }

    // from / to inputs; the data's ends are the placeholders (an empty side = no bound)
    function rangeWidget(shell, inp, res, current) {
        var L = opt.lang || {}, parts = current.split('~');
        var type = res.input === 'number' ? 'number' : (res.input === 'date' ? 'date' : 'text');
        var box = $('<div class="df-default-range"></div>');
        var from = $('<input class="form-control df-default-bound" data-bound="from">').attr('type', type).attr('placeholder', res.min || L.range_from || '').val($.trim(parts[0] || ''));
        var to = $('<input class="form-control df-default-bound" data-bound="to">').attr('type', type).attr('placeholder', res.max || L.range_to || '').val($.trim(parts[1] || ''));
        box.append(from, $('<span>–</span>'), to);
        shell.hide();
        shell.after(box);
        box.on('input change', 'input', function () {
            var f = $.trim(from.val() || ''), t = $.trim(to.val() || '');
            inp.val(f === '' && t === '' ? '' : f + '~' + t);
        });
    }

    // A list default as it is stored: the values joined by commas, a comma inside a value
    // written "\,", a backslash "\\" (FilterBarConfig::listDefault reads it on the server)
    function joinList(values) {
        return $.map(values, function (v) { return String(v).replace(/\\/g, '\\\\').replace(/,/g, '\\,'); }).join(',');
    }
    function splitList(text) {
        var values = [], current = '';
        for (var i = 0; i < text.length; i++) {
            var c = text.charAt(i), next = text.charAt(i + 1);
            if (c === '\\' && (next === ',' || next === '\\')) { current += next; i++; }
            else if (c === ',') { values.push(current); current = ''; }
            else { current += c; }
        }
        values.push(current);
        return $.map(values, function (v) { v = $.trim(v); return v === '' ? null : v; });
    }

    // a select2 picker of the stored values, so a default is picked by label instead of typed by id
    function pickerWidget(shell, inp, res, current) {
        var selected = splitList(current);
        var sel = $('<select multiple class="df-default-picker form-control" style="width:100%"></select>');
        var seen = {};
        $.each(res.options, function (i, o) { seen[o.id] = true; sel.append($('<option>').val(o.id).text(o.text)); });
        $.each(selected, function (i, v) { if (!seen[v]) { sel.append($('<option>').val(v).text(v)); } });
        shell.hide();
        shell.after(sel);
        sel.val(selected).select2({ width: '100%' });
        sel.on('change', function () { inp.val(joinList(sel.val() || [])); });
    }

    return {
        init: function (options) {
            opt = options || {};
            $(document).off(NS);
            $('#has-many-table-filter_bar_dims').off(NS).on('admin_hasmany_row_change' + NS, fillNewRow);
            rows().each(function () { defaultInit($(this)); });
            // another column on a row, or another source table: the stored default no longer fits
            $(document).on('change' + NS, TABLE + ' select.column', function () {
                var tr = $(this).closest('tr');
                tr.find('input.default').val('');
                defaultInit(tr);
            });
            $(document).on('change' + NS, 'select[name="filter_bar_table"]', function () {
                rows().each(function () { $(this).find('input.default').val(''); defaultInit($(this)); });
            });
        }
    };
})(jQuery);

/**
 * Dashboard BOX form — the 色分けのしきい値 table of a heatmap (ChartItem::setAdminExtraFields).
 * init() runs on every render of the form (pjax included). A row's color is its text input
 * `color` (#rrggbb), which is posted and validated as it is but hidden: a button shows the color
 * and its name (赤 / 橙 / 黄 … or カスタム) and opens a panel under it — the palette, the browser's
 * own picker (その他) and the #rrggbb as text. A pick closes the panel, so does a click outside
 * or Esc. The panel is fixed to the window, so the table and the form box do not clip it, and
 * opens above its button when the window has no room below. The upper end (B) belongs to a range
 * only: on another condition it is emptied and disabled, so not posted.
 *
 *   palette  [{name, color}]   table  the table's name (chart_thresholds)   range  the range condition
 *   labels   {title, other, custom, placeholder, picker}: the panel's title, the label of its
 *            #rrggbb line, the name of a color not in the palette, the button with no color, the
 *            title of the browser's picker
 */
window.ExmentChartThresholdForm = (function ($) {
    'use strict';

    var NS = '.exthform';
    var HEX = /^#[0-9a-f]{6}$/i;
    var GAP = 4;
    var opt = {};

    function root() { return '#has-many-table-' + opt.table; }
    // a color picked out of the table too (opt.single: its input, the 目標線's): its form group
    // stands for a row
    var SINGLE = '.th-single';
    // $(sel(' .th-btn')): the table's and the single fields' alike
    function sel(sub) { return root() + sub + ', ' + SINGLE + sub; }
    function rowOf(el) { return $(el).closest('tr, ' + SINGLE); }
    var INPUT = 'input.color, input.th-color';
    function label(key) { return (opt.labels || {})[key] || ''; }
    function colorOf(tr) { return $.trim(String(tr.find(INPUT).val() || '')).toLowerCase(); }
    // #rrggbb as typed into the panel: the # may be left out
    function typed(v) { v = $.trim(String(v || '')).toLowerCase(); return /^[0-9a-f]{6}$/.test(v) ? '#' + v : v; }

    function nameOf(color) {
        var found = null;
        $.each(opt.palette || [], function (i, p) { if (String(p.color).toLowerCase() === color) { found = p.name; return false; } });
        return found;
    }

    // a row's button and panel (once); the text input stays, hidden, as what is posted
    function decorate(tr) {
        var input = tr.find(INPUT).first();
        if (input.length && !tr.find('.th-picker').length) {
            var group = input.closest('.input-group');
            (group.length ? group : input).addClass('th-source');
            var picker = $('<div class="th-picker"></div>');
            $('<button type="button" class="th-btn" aria-haspopup="dialog" aria-expanded="false"></button>')
                .append('<span class="th-sw"></span>', '<span class="th-name"></span>', '<i class="fa fa-caret-down" aria-hidden="true"></i>')
                .appendTo(picker);
            var pop = $('<div class="th-pop" role="dialog" hidden></div>').attr('aria-label', label('title')).appendTo(picker);
            $('<div class="th-pop-title"></div>').text(label('title')).appendTo(pop);
            var grid = $('<div class="th-grid"></div>').appendTo(pop);
            $.each(opt.palette || [], function (i, p) {
                $('<button type="button" class="th-chip"><i class="fa fa-check" aria-hidden="true"></i></button>')
                    .attr({ title: p.name, 'aria-label': p.name, 'aria-pressed': 'false', 'data-color': String(p.color).toLowerCase() })
                    .css('background-color', p.color)
                    .appendTo(grid);
            });
            $('<div class="th-sep"></div>').appendTo(pop);
            var more = $('<div class="th-more"></div>').appendTo(pop);
            $('<label class="th-rainbow"></label>').attr('title', label('picker'))
                .append($('<input type="color">').attr('aria-label', label('picker')))
                .appendTo(more);
            $('<span class="th-more-label"></span>').text(label('other')).appendTo(more);
            $('<input type="text" class="th-hex" maxlength="7" placeholder="#rrggbb" spellcheck="false" autocomplete="off">')
                .attr('aria-label', label('other'))
                .appendTo(more);
            (group.length ? group : input).after(picker);
        }
        sync(tr);
    }

    function sync(tr) {
        var range = tr.find('select.condition').val() === opt.range;
        var upper = tr.find('input.value2');
        if (!range) { upper.val(''); }
        upper.prop('disabled', !range);
        var color = colorOf(tr);
        var valid = HEX.test(color);
        tr.find('.th-btn .th-sw').css('background-color', valid ? color : '').toggleClass('th-none', !valid);
        tr.find('.th-btn .th-name').text(color === '' ? label('placeholder') : (nameOf(color) || label('custom')))
            .toggleClass('th-placeholder', color === '');
        tr.find('.th-chip').each(function () {
            var on = String($(this).data('color')) === color;
            $(this).toggleClass('on', on).attr('aria-pressed', on ? 'true' : 'false');
        });
        if (valid) { tr.find('.th-rainbow input').val(color); }
        var hex = tr.find('.th-hex');
        if (!hex.is(':focus')) { hex.val(color); }
    }

    function pick(tr, color) {
        tr.find(INPUT).val(color).trigger('change');
    }

    // ---- the panel ----
    function picker(el) { return $(el).closest('.th-picker'); }
    function openOne() { return $(sel(' .th-picker.open')); }

    // under its button, or above it when the window has no room below and more above
    function place(p) {
        var pop = p.find('.th-pop'), btn = p.find('.th-btn')[0];
        if (!btn || pop.prop('hidden')) { return; }
        var r = btn.getBoundingClientRect();
        var h = pop.outerHeight(), w = pop.outerWidth();
        var below = window.innerHeight - r.bottom - GAP, above = r.top - GAP;
        var up = h > below && above > below;
        pop.toggleClass('th-pop-up', up).css({
            top: up ? Math.max(0, r.top - GAP - h) : r.bottom + GAP,
            left: Math.max(0, Math.min(r.left, window.innerWidth - w - GAP))
        });
    }

    function open(p) {
        close(openOne().not(p), false);
        p.addClass('open').find('.th-btn').attr('aria-expanded', 'true');
        p.find('.th-pop').prop('hidden', false);
        place(p);
        var on = p.find('.th-chip.on');
        (on.length ? on : p.find('.th-chip').first()).trigger('focus');
    }

    function close(p, focus) {
        if (!p.length) { return; }
        p.removeClass('open').find('.th-btn').attr('aria-expanded', 'false');
        p.find('.th-pop').prop('hidden', true);
        if (focus) { p.find('.th-btn').trigger('focus'); }
    }

    function decorateAll() { $(root() + ' tbody tr, ' + SINGLE).each(function () { decorate($(this)); }); }

    // the open panel follows its button as the page (or a box in it) scrolls
    function follow() { openOne().each(function () { place($(this)); }); }

    return {
        init: function (options) {
            opt = options || {};
            $(document).off(NS);
            $(window).off(NS);
            document.removeEventListener('scroll', follow, true);
            if (opt.single) { $(opt.single).addClass('th-color').closest('.form-group').addClass(SINGLE.slice(1)); }
            decorateAll();
            // a "+ new" row
            $(root()).off(NS).on('admin_hasmany_row_change' + NS, decorateAll);
            $(document).on('change' + NS, root() + ' select.condition', function () { sync($(this).closest('tr')); });
            $(document).on('change' + NS + ' input' + NS, root() + ' input.color, ' + SINGLE + ' input.th-color', function () { sync(rowOf(this)); });

            $(document).on('click' + NS, sel(' .th-btn'), function () {
                var p = picker(this);
                if (p.hasClass('open')) { close(p, false); } else { open(p); }
            });
            $(document).on('click' + NS, sel(' .th-chip'), function () {
                pick(rowOf(this), String($(this).data('color')));
                close(picker(this), true);
            });
            // the browser's picker: the color as it moves, closed with it
            $(document).on('input' + NS, sel(' .th-rainbow input'), function () {
                pick(rowOf(this), String(this.value).toLowerCase());
            });
            $(document).on('change' + NS, sel(' .th-rainbow input'), function () {
                pick(rowOf(this), String(this.value).toLowerCase());
                close(picker(this), true);
            });
            // #rrggbb typed: a whole color as it is typed, anything else when it is left (the
            // save reports it); Enter takes it and closes, without submitting the form
            $(document).on('input' + NS, sel(' .th-hex'), function () {
                var v = typed(this.value);
                if (HEX.test(v)) { pick(rowOf(this), v); }
            });
            $(document).on('change' + NS, sel(' .th-hex'), function () {
                pick(rowOf(this), typed(this.value));
            });
            $(document).on('keydown' + NS, sel(' .th-hex'), function (e) {
                if (e.key !== 'Enter') { return; }
                e.preventDefault();
                pick(rowOf(this), typed(this.value));
                close(picker(this), true);
            });
            $(document).on('keydown' + NS, sel(' .th-picker'), function (e) {
                if (e.key === 'Escape') {
                    if (!$(this).hasClass('open')) { return; }
                    e.preventDefault();
                    e.stopPropagation();
                    close($(this), true);
                    return;
                }
                // ← → between the palette's colors
                if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && $(e.target).hasClass('th-chip')) {
                    e.preventDefault();
                    var chips = $(this).find('.th-chip'), i = chips.index(e.target) + (e.key === 'ArrowRight' ? 1 : -1);
                    chips.eq((i + chips.length) % chips.length).trigger('focus');
                }
            });
            // Tab out of the panel (to the next cell) closes it
            $(document).on('focusout' + NS, sel(' .th-picker'), function (e) {
                var p = $(this);
                if (e.relatedTarget && !$.contains(this, e.relatedTarget)) { close(p, false); }
            });
            // a click anywhere else
            $(document).on('mousedown' + NS + ' touchstart' + NS, function (e) {
                var p = openOne();
                if (p.length && !$(e.target).closest(p).length) { close(p, false); }
            });
            $(document).on('pjax:start' + NS, function () { close(openOne(), false); });
            $(window).on('resize' + NS, follow);
            document.addEventListener('scroll', follow, true);
        }
    };
})(jQuery);
