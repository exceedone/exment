{{-- Single-series chart types rendered by Apache ECharts (same labels[] + values[] as Chart.js). --}}
<div data-echart-id="{{ $suuid }}" style="width:100%;height:{{ $chart_height }}px;"></div>
<script type="text/javascript">
    // ECharts is fetched on first use (dashboard.js withECharts), not loaded with every admin
    // page; when the fetch fails, dashboard.js says so in this box
    ExmentDashboard.withECharts('{{ $suuid }}').done(function () {
        var el = document.querySelector('[data-echart-id="{{ $suuid }}"]');
        if (!el) { return; }

        // a function tooltip formatter is injected as innerHTML — escape labels there
        function escHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        var labels = {!! $chart_labels !!};
        // a point without a value stays null (no bar / a gap in the line), as Chart.js draws it — not a 0
        var values = ({!! $chart_data !!} || []).map(function (v) { var n = parseFloat(v); return isNaN(n) ? null : n; });
        var colors = {!! $chart_colors !!};
        // colors of the points as the runtime sort left them (types that color BY DATA use
        // these, so a category keeps its color through a re-sort); the shape types — area,
        // radar, gauge — stay on the palette, whose first entry must not follow the sort
        var pointColors = {!! $chart_point_colors ?? '[]' !!};
        var byPoint = (pointColors && pointColors.length) ? pointColors : colors;
        // click-to-filter: {column, values[], selected[], highlight} when the group column is a
        // filter-bar item (else null) — ChartItem::clickFilter; types whose dataIndex maps 1:1
        // to the labels only (radar / gauge draw one shape)
        var click = {!! $chart_click !!};
        var type = '{{ $chart_type }}';
        var pickable = !!click && ['hbar', 'area', 'doughnut', 'funnel', 'scatter'].indexOf(type) >= 0;
        // types colored point by point (byPoint) — the others draw one shape in one color
        var perPoint = ['hbar', 'doughnut', 'funnel', 'scatter'].indexOf(type) >= 0;
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        // the value as it is (0.04 stays 0.04), grouped; up to 10 decimals cut off float noise
        var fmt = function (n) { return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 10 }); };
        // px width of a label as ECharts draws it (its default font), to keep labels inside the box
        var ctx2d = document.createElement('canvas').getContext('2d');
        var textWidth = function (s, size) { ctx2d.font = size + 'px sans-serif'; return ctx2d.measureText(String(s)).width; };
        var widest = function (list, size) { return list.reduce(function (w, s) { return Math.max(w, textWidth(s, size)); }, 0); };
        // The gauge's ticks for a box of w × h px: max / n, n the most steps (up to 5) whose
        // labels stay apart on the arc — a narrow box had "2,360,0003,540,000". Each tick value
        // is shown to 2 significant digits of the step: "2.5" stays 2.5 (rounding to whole showed
        // "2" twice), "246.91356" reads 247. Labels placed as ECharts places them: on a circle
        // inside the axis line, anchored left / right / centre by the side of the dial they are on.
        function gaugeScale(w, h) {
            var radius = Math.min(w, h) / 2 * 0.75 - 14 - 8 - 12; // 75% radius − axis line − split line − label distance
            var n, tick;
            for (n = 5; n >= 2; n--) {
                var digits = Math.min(10, Math.max(0, 1 - Math.floor(Math.log10(Math.abs(gmax) / n))));
                tick = (function (d) { return function (v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: d }); }; })(digits);
                var boxes = [];
                for (var k = 0; k <= n; k++) {
                    var a = (225 - 270 * k / n) * Math.PI / 180, ux = Math.cos(a), x = ux * radius, y = -Math.sin(a) * radius;
                    var tw = textWidth(tick(gmax * k / n), 9) + 4;
                    var left = ux < -0.4 ? x : (ux > 0.4 ? x - tw : x - tw / 2);
                    boxes.push([left, y - 7, left + tw, y + 7]);
                }
                var apart = boxes.every(function (b, i) {
                    return boxes.every(function (c, j) { return j <= i || b[2] <= c[0] || c[2] <= b[0] || b[3] <= c[1] || c[3] <= b[1]; });
                });
                if (apart) { break; }
            }
            return { splitNumber: Math.max(n, 2), axisLabel: { fontSize: 9, distance: 12, formatter: tick } };
        }
        var pairs = labels.map(function (l, i) { return { name: String(l), value: values[i] }; });
        // the points a type draws, when it leaves some out: drawn[k] = the index (in labels /
        // values / byPoint / click.values) of its k-th data item; null = every point, in order
        var drawn = null;
        var pointOf = function (k) { return drawn ? drawn[k] : k; };
        // axis names kept inside the box: X's centred under its tick labels, Y's above the plot
        // running right from the axis line (centred on the line, a long one is cut by the box's
        // left edge); px, not %, so a short box still has room for them
        var grid = { left: '3%', right: '5%', bottom: 30, top: 36, containLabel: true };
        var option;

        switch (type) {
            case 'hbar':
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: function (ps) { var p = ps[0] || ps; return escHtml(p.name) + ': ' + fmt(p.value); } },
                    // the value axis's name under its ticks, the category axis's above the first bar
                    // (inverse: 'start' is the top)
                    grid: { left: '3%', right: '6%', bottom: 30, top: 34, containLabel: true },
                    // a narrow box drops the tick labels that would overlap ("1,000,0002,000,000")
                    xAxis: { type: 'value', name: axisYName, nameLocation: 'middle', nameGap: 26, axisLabel: { hideOverlap: true } },
                    yAxis: { type: 'category', data: labels, name: axisXName, inverse: true, nameLocation: 'start', nameTextStyle: { align: 'left' } },
                    series: [{ type: 'bar', data: values, colorBy: 'data', barMaxWidth: 28, itemStyle: { borderRadius: [0, 4, 4, 0] } }]
                };
                break;
            case 'area':
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', formatter: function (ps) { var p = ps[0] || ps; return escHtml(p.name) + ': ' + fmt(p.value); } },
                    grid: grid,
                    xAxis: { type: 'category', boundaryGap: false, data: labels, name: axisXName, nameLocation: 'middle', nameGap: 26 },
                    yAxis: { type: 'value', name: axisYName, nameTextStyle: { align: 'left' } },
                    series: [{ type: 'line', data: values, smooth: true, areaStyle: { opacity: 0.25 }, lineStyle: { width: 2 } }]
                };
                break;
            case 'doughnut':
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'item' },
                    legend: { show: showLegend, type: 'scroll', bottom: 0 },
                    series: [{ type: 'pie', radius: ['42%', '70%'], center: ['50%', '46%'], avoidLabelOverlap: true, data: pairs, label: { formatter: '{b}: {d}%' } }]
                };
                break;
            case 'radar':
                var maxVal = Math.max.apply(null, values.length ? values : [1]) || 1;
                option = {
                    color: colors,
                    tooltip: { trigger: 'item' },
                    radar: { indicator: labels.map(function (l) { return { name: String(l), max: maxVal }; }), radius: '65%' },
                    series: [{ type: 'radar', data: [{ value: values, name: axisYName, areaStyle: { opacity: 0.2 } }] }]
                };
                break;
            case 'funnel':
                // a point without a value is left out: ECharts draws a null funnel item as the widest
                drawn = [];
                $.each(values, function (i, v) { if (v != null) { drawn.push(i); } });
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'item' },
                    legend: { show: showLegend, type: 'scroll', bottom: 0 },
                    // the labels run right of the funnel: it leaves them room (fit() below)
                    series: [{ type: 'funnel', left: '8%', right: '8%', top: '6%', bottom: '12%', sort: 'descending', gap: 2, data: drawn.map(function (i) { return pairs[i]; }), label: { formatter: function (p) { return p.name + ': ' + fmt(p.value); } } }]
                };
                break;
            case 'gauge': // single KPI gauge: the first data point
                var gmax = Math.max.apply(null, values.length ? values : [1]) || 1;
                option = {
                    color: colors,
                    tooltip: { formatter: function (p) { return escHtml(p.name) + ': ' + fmt(p.value); } },
                    series: [$.extend({
                        type: 'gauge', min: 0, max: gmax,
                        progress: { show: true, width: 14 },
                        axisLine: { lineStyle: { width: 14 } },
                        axisTick: { show: false },
                        splitLine: { length: 8 },
                        // a point without a value reads "-" (the template '{value}' printed "NaN"); grouped as the ticks
                        detail: { valueAnimation: true, formatter: function (v) { return (v == null || isNaN(v)) ? '-' : fmt(v); }, fontSize: 22, offsetCenter: [0, '70%'] },
                        data: [{ value: values.length ? values[0] : 0, name: String(labels.length ? labels[0] : (axisYName || '')) }]
                    }, gaugeScale(el.clientWidth, el.clientHeight))]
                };
                break;
            case 'scatter':
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'item', formatter: function (p) { return escHtml(labels[p.dataIndex]) + ': ' + fmt(p.value[1]); } },
                    grid: grid,
                    xAxis: { type: 'category', data: labels, name: axisXName, nameLocation: 'middle', nameGap: 26 },
                    yAxis: { type: 'value', name: axisYName, nameTextStyle: { align: 'left' } },
                    series: [{ type: 'scatter', symbolSize: 14, colorBy: 'data', data: values.map(function (v, i) { return [i, v]; }) }]
                };
                break;
            default:
                return;
        }
        // point i drawn in byPoint[i]: ECharts' own coloring by data hands its palette out per
        // distinct NAME, so a repeated label (two rows of a list view) shifts every later color
        if (perPoint) {
            option.series[0].data = option.series[0].data.map(function (d, i) {
                var item = (d !== null && typeof d === 'object' && !Array.isArray(d)) ? $.extend({}, d) : { value: d };
                item.itemStyle = { color: byPoint[pointOf(i) % byPoint.length] };
                return item;
            });
        }
        // Cross-highlight: the bar's pick on this chart's own X item (the server leaves that
        // item out of the chart's filter, so every category is here) — the picked points solid,
        // the rest faded, as per-point opacity; area also grows the picked symbols. `picked` is
        // value => true; nothing picked = nothing faded.
        var picked = {}, pickedN = 0;
        function setPicked(list) {
            picked = {};
            pickedN = 0;
            (list || []).forEach(function (v) { v = String(v); if (!picked[v]) { picked[v] = true; pickedN++; } });
        }
        setPicked(pickable && click.highlight ? click.selected : null);
        var faded = function (i) { return pickedN > 0 && !picked[String(click.values[i])]; };
        var baseData = option.series[0].data;
        function pointData() {
            return baseData.map(function (d, i) {
                var item = (d !== null && typeof d === 'object' && !Array.isArray(d)) ? $.extend({}, d) : { value: d };
                item.itemStyle = $.extend({}, item.itemStyle, { opacity: faded(pointOf(i)) ? 0.18 : 1 });
                if (type === 'area' && pickedN > 0 && !faded(i)) { item.symbol = 'circle'; item.symbolSize = 10; }
                return item;
            });
        }

        // dispose the previous instance of THIS box (a reload replaces the body; the old
        // chart lives on a detached element and must be found through the per-box registry)
        window.ExmentECharts = window.ExmentECharts || {};
        var prior = window.ExmentECharts['{{ $suuid }}'];
        if (prior && !(prior.isDisposed && prior.isDisposed())) { try { prior.dispose(); } catch (e) {} }
        var chart;
        try {
            chart = echarts.init(el);
            window.ExmentECharts['{{ $suuid }}'] = chart;
            chart.setOption(option);
        } catch (e) {
            ExmentDashboard.chartError('{{ $suuid }}', @json(exmtrans('dashboard.message.chart_render_error')));
            return;
        }

        // a point a click does nothing on shows no hand cursor
        if (!pickable) {
            chart.on('mousemove', function (p) {
                if (p.componentType === 'series') { chart.getZr().setCursorStyle('default'); }
            });
        }
        if (pickable) {
            chart.on('click', function (p) {
                var i = typeof p.dataIndex === 'number' ? pointOf(p.dataIndex) : undefined;
                if (typeof i !== 'number' || i >= click.values.length) { return; }
                var ne = p.event && p.event.event;
                ExmentDashboard.pick(click.column, click.values[i], !!(ne && (ne.ctrlKey || ne.metaKey)));
            });
        }

        @if(!empty($chart_color_edit))
        // paint a color (dashboard editors): right-click a category; the one-color types
        // (area / radar / gauge) paint their one series
        chart.on('contextmenu', function (p) {
            var i = typeof p.dataIndex === 'number' ? pointOf(p.dataIndex) : undefined;
            if (perPoint && (typeof i !== 'number' || i >= labels.length)) { return; }
            var ne = p.event && p.event.event;
            if (ne && ne.preventDefault) { ne.preventDefault(); }
            ExmentDashboard.colorMenu('{{ $suuid }}', perPoint
                ? { kind: 'points', key: String(labels[i]), color: byPoint[i % byPoint.length] }
                : { kind: 'series', key: '', color: colors[0] }, ne);
        });
        @endif

        // Options that depend on the box's size, set again on every resize so the labels stay
        // whole and inside the box at any width (a narrow window, a 4-column row)
        function fit() {
            if (chart.isDisposed && chart.isDisposed()) { return; }
            var w = chart.getWidth(), h = chart.getHeight();
            if (type === 'doughnut') {
                // beside a narrow ring "A: 33.21%" was cut to "A: 33...": the percent goes under the
                // name, and the ring leaves the labels (+ their line) room on both sides
                // (a very narrow one, a 4-column row: the name only, the percent stays in the tooltip)
                var tiny = w < 300, narrow = w < 420;
                var lw = tiny ? widest(labels, 12)
                    : (narrow ? Math.max(widest(labels, 12), textWidth('100.00%', 12)) : widest(labels.map(function (l) { return l + ': 100.00%'; }), 12));
                var outer = Math.max(24, Math.min(Math.min(w, h) / 2 * 0.70, w / 2 - lw - 48));
                chart.setOption({ series: [{ radius: [Math.round(outer * 0.6), Math.round(outer)], label: { formatter: tiny ? '{b}' : (narrow ? '{b}\n{d}%' : '{b}: {d}%') } }] });
            } else if (type === 'funnel') {
                // the labels run right of the funnel (label line + text): it ends where they start
                var room = widest(drawn.map(function (i) { return pairs[i].name + ': ' + fmt(pairs[i].value); }), 12) + 36;
                chart.setOption({ series: [{ right: Math.min(Math.round(room), Math.round(w * 0.55)) }] });
            } else if (type === 'gauge') {
                chart.setOption({ series: [gaugeScale(w, h)] });
            }
        }
        fit();
        var resize = function () { chart.resize(); fit(); render(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () { setTimeout(resize, 50); });

        // Overlays composed from one state, since they both live on the series' data / label
        // options: the highlight and the box menu's 値ラベル (labels: value-axis types only).
        var horizontal = { hbar: true, area: false, scatter: false }[type];
        var state = { labels: {{ $chart_labels_on ? 'true' : 'false' }} };
        function render() {
            if (chart.isDisposed && chart.isDisposed()) { return; }
            var s = {}, o = { series: [s] };
            if (pickable) { s.data = pointData(); }
            if (horizontal !== undefined) {
                s.label = { show: state.labels, position: horizontal ? 'right' : 'top', fontSize: 11, color: '#2b2b3a', formatter: function (p) { return fmt(Array.isArray(p.value) ? p.value[1] : p.value); } };
                s.labelLayout = { hideOverlap: true };
            }
            if (horizontal) {
                // the value labels sit right of the bars: the plot leaves them room, or the
                // longest bar's was cut at the box's edge ("5,8")
                var room = state.labels ? widest(values.map(fmt), 11) + 12 : 0;
                o.grid = { right: Math.max(Math.round(room), Math.round(chart.getWidth() * 0.06)) };
                // as many value ticks as their labels fit side by side ("01,000,000" in a narrow box)
                var tickW = textWidth(fmt(Math.max.apply(null, values.map(function (v) { return Math.abs(v || 0); }).concat([1]))), 12) + 32;
                o.xAxis = { splitNumber: Math.max(2, Math.min(5, Math.floor((chart.getWidth() * 0.8 - room) / tickW))) };
            }
            chart.setOption(o);
        }
        // (the horizontal bar always: its room and ticks depend on the box's width)
        if (state.labels || pickedN > 0 || horizontal) { render(); }

        // Per-box hooks for dashboard.js: `highlight` repaints the bar's pick on the chart's own
        // X item (`self`), `display` (menu toggles, live), `data` (the
        // plotted points for the CSV export)
        window.ExmentCharts = window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = {
            self: (pickable && click.highlight) ? click.column : null,
            highlight: function (list) { setPicked(list); render(); },
            display: function (opts) { $.extend(state, opts || {}); render(); },
            data: function () { return { axisx: axisXName, axisy: axisYName, labels: labels, values: values }; }
        };
    });
</script>
