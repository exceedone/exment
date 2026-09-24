{{-- Single-series chart types rendered by Apache ECharts (same labels[] + values[] as Chart.js). --}}
<div data-echart-id="{{ $suuid }}" style="width:100%;height:{{ $chart_height }}px;"></div>
<script type="text/javascript">
    (function () {
        if (typeof echarts === 'undefined') { return; }
        var el = document.querySelector('[data-echart-id="{{ $suuid }}"]');
        if (!el) { return; }

        // a function tooltip formatter is injected as innerHTML — escape labels there
        function escHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        var labels = {!! $chart_labels !!};
        var values = ({!! $chart_data !!} || []).map(function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; });
        // record count behind each point (averaged charts), else null
        var counts = {!! $chart_counts !!};
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
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        var COUNT_FMT = @json(exmtrans('dashboard.chart_menu.count_fmt'));
        var fmt = function (n) { return (Math.round(n * 10) / 10).toLocaleString(); };
        var cnt = function (i) { return (counts && counts[i] != null) ? COUNT_FMT.replace('%s', Number(counts[i]).toLocaleString()) : ''; };
        var pairs = labels.map(function (l, i) { return { name: String(l), value: values[i] }; });
        var grid = { left: '3%', right: '5%', bottom: '3%', top: '8%', containLabel: true };
        var option;

        switch (type) {
            case 'hbar':
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: function (ps) { var p = ps[0] || ps; return escHtml(p.name) + ': ' + fmt(p.value) + escHtml(cnt(p.dataIndex)); } },
                    grid: { left: '3%', right: '6%', bottom: '3%', top: '8%', containLabel: true },
                    xAxis: { type: 'value', name: axisYName },
                    yAxis: { type: 'category', data: labels, name: axisXName, inverse: true },
                    series: [{ type: 'bar', data: values, colorBy: 'data', barMaxWidth: 28, itemStyle: { borderRadius: [0, 4, 4, 0] } }]
                };
                break;
            case 'area':
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', formatter: function (ps) { var p = ps[0] || ps; return escHtml(p.name) + ': ' + fmt(p.value) + escHtml(cnt(p.dataIndex)); } },
                    grid: grid,
                    xAxis: { type: 'category', boundaryGap: false, data: labels, name: axisXName },
                    yAxis: { type: 'value', name: axisYName },
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
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'item' },
                    legend: { show: showLegend, type: 'scroll', bottom: 0 },
                    series: [{ type: 'funnel', left: '8%', right: '8%', top: '6%', bottom: '12%', sort: 'descending', gap: 2, data: pairs, label: { formatter: '{b}: {c}' } }]
                };
                break;
            case 'gauge': // single KPI gauge: the first data point
                var gmax = Math.max.apply(null, values.length ? values : [1]) || 1;
                option = {
                    color: colors,
                    tooltip: { formatter: '{b}: {c}' },
                    series: [{
                        type: 'gauge', min: 0, max: gmax, splitNumber: 5,
                        progress: { show: true, width: 14 },
                        axisLine: { lineStyle: { width: 14 } },
                        axisTick: { show: false },
                        splitLine: { length: 8 },
                        axisLabel: { fontSize: 9, distance: 12, formatter: function (v) { return Math.round(v); } },
                        detail: { valueAnimation: true, formatter: '{value}', fontSize: 22, offsetCenter: [0, '70%'] },
                        data: [{ value: values.length ? values[0] : 0, name: String(labels.length ? labels[0] : (axisYName || '')) }]
                    }]
                };
                break;
            case 'scatter':
                option = {
                    color: byPoint,
                    tooltip: { trigger: 'item', formatter: function (p) { return escHtml(labels[p.dataIndex]) + ': ' + fmt(p.value[1]) + escHtml(cnt(p.dataIndex)); } },
                    grid: grid,
                    xAxis: { type: 'category', data: labels, name: axisXName },
                    yAxis: { type: 'value', name: axisYName },
                    series: [{ type: 'scatter', symbolSize: 14, colorBy: 'data', data: values.map(function (v, i) { return [i, v]; }) }]
                };
                break;
            default:
                return;
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
                item.itemStyle = { opacity: faded(i) ? 0.18 : 1 };
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
            el.innerHTML = '<div class="exment-chart-error">' + escHtml(@json(exmtrans('dashboard.message.chart_render_error'))) + '</div>';
            return;
        }

        if (pickable) {
            chart.on('click', function (p) {
                if (typeof p.dataIndex !== 'number' || p.dataIndex >= click.values.length) { return; }
                var ne = p.event && p.event.event;
                ExmentDashboard.pick(click.column, click.values[p.dataIndex], !!(ne && (ne.ctrlKey || ne.metaKey)));
            });
        }

        @if(!empty($chart_color_edit))
        // paint a color (dashboard editors): right-click a category; the one-color types
        // (area / radar / gauge) paint their one series
        chart.on('contextmenu', function (p) {
            var perPoint = ['hbar', 'doughnut', 'funnel', 'scatter'].indexOf(type) >= 0;
            if (perPoint && (typeof p.dataIndex !== 'number' || p.dataIndex >= labels.length)) { return; }
            var ne = p.event && p.event.event;
            if (ne && ne.preventDefault) { ne.preventDefault(); }
            ExmentDashboard.colorMenu('{{ $suuid }}', perPoint
                ? { kind: 'points', key: String(labels[p.dataIndex]), color: byPoint[p.dataIndex % byPoint.length] }
                : { kind: 'series', key: '', color: colors[0] }, ne);
        });
        @endif

        var resize = function () { chart.resize(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () { setTimeout(resize, 50); });

        // Overlays composed from one state, since they all live on the series' data / label /
        // mark options: the highlight, the box menu's 値ラベル and the AI summary's anomaly
        // markers (amber pins + shaded expected range). Labels and markers: value-axis types only.
        var AMBER = '#e0a020';
        var horizontal = { hbar: true, area: false, scatter: false }[type];
        var state = { anomaly: null, labels: {{ $chart_labels_on ? 'true' : 'false' }} };
        function render() {
            if (chart.isDisposed && chart.isDisposed()) { return; }
            var s = {};
            if (pickable) { s.data = pointData(); }
            if (horizontal !== undefined) {
                s.label = { show: state.labels, position: horizontal ? 'right' : 'top', fontSize: 11, color: '#2b2b3a', formatter: function (p) { return fmt(Array.isArray(p.value) ? p.value[1] : p.value); } };
                s.labelLayout = { hideOverlap: true };
                var lines = [], band = [], pins = [];
                var a = state.anomaly;
                if (a && a.points && a.points.length) {
                    var from = horizontal ? { xAxis: a.lower } : { yAxis: a.lower }, to = horizontal ? { xAxis: a.upper } : { yAxis: a.upper };
                    var edge = { lineStyle: { type: 'dashed', color: AMBER, width: 1 }, label: { show: false } };
                    band = [[from, to]];
                    lines.push($.extend({}, edge, from));
                    lines.push($.extend({}, edge, to));
                    pins = a.points.map(function (p) { return { coord: horizontal ? [p.value, p.index] : [p.index, p.value], value: p.direction === 'high' ? '▲' : '▼' }; });
                }
                s.markLine = { silent: true, symbol: 'none', data: lines };
                s.markArea = { silent: true, itemStyle: { color: 'rgba(224,160,32,0.08)' }, data: band };
                s.markPoint = { symbol: 'pin', symbolSize: 42, itemStyle: { color: AMBER }, label: { color: '#fff', fontSize: 11, fontWeight: 'bold' }, data: pins };
            }
            chart.setOption({ series: [s] });
        }
        if (state.labels || pickedN > 0) { render(); }

        // Per-box hooks for dashboard.js: `highlight` repaints the bar's pick on the chart's own
        // X item (`self`), `mark` (AI anomalies), `display` (menu toggles, live), `data` (the
        // plotted points for the CSV export)
        window.ExmentCharts = window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = {
            self: (pickable && click.highlight) ? click.column : null,
            highlight: function (list) { setPicked(list); render(); },
            mark: function (anomaly) { state.anomaly = anomaly || null; render(); },
            display: function (opts) { $.extend(state, opts || {}); render(); },
            data: function () { return { axisx: axisXName, axisy: axisYName, labels: labels, values: values }; }
        };
    })();
</script>
