{{-- Diverse chart types rendered by Apache ECharts (reuses single-series chart data) --}}
<div data-echart-id="{{ $suuid }}" style="width:100%;height:{{ $chart_height }}px;"></div>

{{-- AI summary strip — hidden when insight is off / this box's data is barred from the AI. --}}
@if($ai_insight ?? true)
@include('exment::dashboard.chart._insight_strip')
@endif

<script type="text/javascript">
    (function () {
        if (typeof echarts === 'undefined') { return; }

        var el = document.querySelector('[data-echart-id="{{ $suuid }}"]');
        if (!el) { return; }

        // Drop any marker toggle left by a previous render of this box FIRST (a box reload
        // re-runs this script; a leftover entry would call setOption on a disposed chart).
        // Re-registered at the bottom only when this render still has an anomaly overlay —
        // deleting up front also covers every early-return path below.
        window.ExmentChartMarkers = window.ExmentChartMarkers || {};
        delete window.ExmentChartMarkers['{{ $suuid }}'];

        // Escape for the few CUSTOM function tooltip formatters below: ECharts injects a
        // function formatter's return value as innerHTML (unlike '{b}' string templates, which
        // it escapes). Labels are raw server-side, so an unescaped label = stored XSS on hover.
        function escHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        var labels = {!! $chart_labels !!};
        var values = {!! $chart_data !!};
        var colors = {!! $chart_colors !!};
        var type = '{{ $chart_type }}';
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        // Deterministic anomaly markers: {lower, upper, points:[{index, value, direction}]} or null.
        var anomaly = {!! $anomaly ?? 'null' !!};

        // numeric values (ECharts expects numbers)
        values = (values || []).map(function (v) {
            var n = parseFloat(v);
            return isNaN(n) ? 0 : n;
        });

        // label/value pairs for circular charts
        var pairs = labels.map(function (l, i) {
            return { name: String(l), value: values[i] };
        });

        var baseTooltip = { trigger: 'item' };
        var option;

        // render a friendly note instead of a chart (for types needing geo / extra config)
        function placeholder(msg) {
            el.innerHTML = '<div style="display:flex;height:100%;align-items:center;justify-content:center;'
                + 'text-align:center;color:#8a8a8a;font-size:13px;padding:16px;line-height:1.6;">' + msg + '</div>';
        }

        // Build the deterministic-outlier overlay for value-axis charts: amber pins on the
        // flagged points + a shaded "expected range" band with dashed edges (Power-BI style).
        // Returned as a series[0] fragment to MERGE on demand (opt-in), or null when this chart
        // type / data has nothing to mark — a band is meaningless on pie/radar/gauge.
        function buildAnomalyOverlay(chartType) {
            if (!anomaly || !anomaly.points || !anomaly.points.length) { return null; }

            // orientation: 'vcat' = category-x/value-y with data=values; 'vidx' = value-y with
            // data=[i,v] pairs (scatter family); 'h' = horizontal (value-x / category-y).
            var ORIENT = {
                area: 'vcat',
                scatter: 'vidx',
                hbar: 'h'
            };
            var orient = ORIENT[chartType];
            if (!orient) { return null; }

            var AMBER = '#e0a020';
            var horizontal = orient === 'h';

            // Expected-range band + dashed edge lines (on the value axis).
            var bandFrom = horizontal ? { xAxis: anomaly.lower } : { yAxis: anomaly.lower };
            var bandTo   = horizontal ? { xAxis: anomaly.upper } : { yAxis: anomaly.upper };

            return {
                markArea: { silent: true, itemStyle: { color: 'rgba(224,160,32,0.08)' }, data: [[bandFrom, bandTo]] },
                markLine: {
                    silent: true, symbol: 'none',
                    lineStyle: { type: 'dashed', color: AMBER, width: 1, opacity: 0.7 },
                    label: { show: false },
                    data: [bandFrom, bandTo]
                },
                markPoint: {
                    symbol: 'pin', symbolSize: 42,
                    label: { color: '#fff', fontSize: 11, fontWeight: 'bold' },
                    data: anomaly.points.map(function (p) {
                        // Address the category axis by INDEX, not label: duplicate labels (repeated
                        // names/dates in list views) resolve by name to the FIRST match, pinning the
                        // wrong bar. A numeric coord on a category axis is the axis index.
                        var coord = orient === 'vcat' ? [p.index, p.value]
                                  : orient === 'vidx' ? [p.index, p.value]
                                  : [p.value, p.index];
                        return { coord: coord, value: (p.direction === 'high' ? '▲' : '▼'), itemStyle: { color: AMBER } };
                    })
                }
            };
        }

        switch (type) {
            case 'hbar': // horizontal bar
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    grid: { left: '3%', right: '6%', bottom: '3%', top: '8%', containLabel: true },
                    xAxis: { type: 'value', name: axisYName },
                    yAxis: { type: 'category', data: labels, name: axisXName, inverse: true },
                    series: [{ type: 'bar', data: values, barMaxWidth: 28, itemStyle: { borderRadius: [0, 4, 4, 0] } }]
                };
                break;

            case 'area': // area line
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis' },
                    grid: { left: '3%', right: '5%', bottom: '3%', top: '8%', containLabel: true },
                    xAxis: { type: 'category', boundaryGap: false, data: labels, name: axisXName },
                    yAxis: { type: 'value', name: axisYName },
                    series: [{ type: 'line', data: values, smooth: true, areaStyle: { opacity: 0.25 }, lineStyle: { width: 2 } }]
                };
                break;

            case 'doughnut':
                option = {
                    color: colors,
                    tooltip: baseTooltip,
                    legend: { show: showLegend, type: 'scroll', bottom: 0 },
                    series: [{
                        type: 'pie', radius: ['42%', '70%'], center: ['50%', '46%'],
                        avoidLabelOverlap: true, data: pairs,
                        label: { formatter: '{b}: {d}%' }
                    }]
                };
                break;

            case 'radar':
                var maxVal = Math.max.apply(null, values.length ? values : [1]) || 1;
                option = {
                    color: colors,
                    tooltip: baseTooltip,
                    radar: {
                        indicator: labels.map(function (l) { return { name: String(l), max: maxVal }; }),
                        radius: '65%'
                    },
                    series: [{ type: 'radar', data: [{ value: values, name: axisYName, areaStyle: { opacity: 0.2 } }] }]
                };
                break;

            case 'funnel':
                option = {
                    color: colors,
                    tooltip: baseTooltip,
                    legend: { show: showLegend, type: 'scroll', bottom: 0 },
                    series: [{
                        type: 'funnel', left: '8%', right: '8%', top: '6%', bottom: '12%',
                        sort: 'descending', gap: 2, data: pairs,
                        label: { formatter: '{b}: {c}' }
                    }]
                };
                break;

            case 'gauge': { // single KPI gauge (uses the first data point)
                var gmax = Math.max.apply(null, values.length ? values : [1]) || 1;
                var gval = values.length ? values[0] : 0;
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
                        data: [{ value: gval, name: String(labels.length ? labels[0] : (axisYName || '')) }]
                    }]
                };
                break;
            }

            case 'scatter':
                option = {
                    color: colors,
                    tooltip: { trigger: 'item', formatter: function (p) { return escHtml(labels[p.dataIndex]) + ': ' + p.value[1]; } },
                    grid: { left: '3%', right: '5%', bottom: '3%', top: '8%', containLabel: true },
                    xAxis: { type: 'category', data: labels, name: axisXName },
                    yAxis: { type: 'value', name: axisYName },
                    series: [{ type: 'scatter', symbolSize: 14, data: values.map(function (v, i) { return [i, v]; }) }]
                };
                break;

            default:
                return;
        }

        var chart;
        try {
            // Dispose the previous instance of THIS box before re-init. A box reload replaces the
            // body HTML, so the old chart lives on a detached element — it must be found through
            // the per-box registry (not getInstanceByDom on the new element), or ECharts keeps it
            // (canvas + zrender + detached DOM) in its internal registry on every reload.
            window.ExmentECharts = window.ExmentECharts || {};
            var prior = window.ExmentECharts['{{ $suuid }}'];
            if (prior && !(prior.isDisposed && prior.isDisposed())) { try { prior.dispose(); } catch (e2) {} }
            chart = echarts.init(el);
            window.ExmentECharts['{{ $suuid }}'] = chart;
            chart.setOption(option);
        } catch (e) {
            placeholder(@json(exmtrans('dashboard.ai_insight.chart_render_error')));
            return;
        }

        // Click: drill URL first (curated story path), else cross-filter the dashboard via the
        // filter bar (see ChartItem::getChartFilter — values[] is index-aligned with labels).
        // Only chart types whose dataIndex maps 1:1 to labels participate; radar/gauge
        // aggregate the whole series into one shape, so a click there has no single data point.
        var drillUrls = {!! $chart_drill ?? '{}' !!};
        var chartFilter = {!! $chart_filter ?? 'null' !!};
        var CLICKABLE = ['hbar', 'area', 'doughnut', 'funnel', 'scatter'];
        if (CLICKABLE.indexOf(type) >= 0) {
            chart.on('click', function (params) {
                var idx = params.dataIndex;
                if (typeof idx !== 'number' || idx < 0 || idx >= labels.length) { return; }
                var label = String(labels[idx] != null ? labels[idx] : (params.name || ''));
                if (drillUrls && drillUrls[label]) { window.location.href = drillUrls[label]; return; }
                if (!chartFilter || !chartFilter.column || !window.exmentDfPick) { return; }
                var val = chartFilter.values[idx];
                val = (val === null || val === undefined) ? '' : String(val);
                if (val === '') { return; }
                // Filter bar picker (multi-select aware): click = select this value only / click
                // the sole selected value again = clear; Ctrl/⌘-click = toggle it in the selection.
                var ne = params.event && params.event.event;
                window.exmentDfPick(chartFilter.column, val, !!(ne && (ne.ctrlKey || ne.metaKey)));
            });
        }

        // resize: on window resize and when the dashboard box reloads. off() first so a reload
        // does not stack a second window listener pinning the previous (disposed) chart.
        var resize = function () { chart.resize(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () {
            setTimeout(resize, 50);
        });

        // Opt-in anomaly markers: built once, merged into series[0] only when the "AI summary"
        // strip is expanded (registered per box; the strip toggle in dashboard/insight.blade.php flips them).
        var anomalyOverlay = buildAnomalyOverlay(type);
        if (anomalyOverlay) {
            window.ExmentChartMarkers['{{ $suuid }}'] = function (on) {
                // Stale-closure no-op: the box was reloaded and this chart disposed.
                if (chart.isDisposed && chart.isDisposed()) { return; }
                chart.setOption({ series: [ on ? anomalyOverlay
                    : { markPoint: { data: [] }, markArea: { data: [] }, markLine: { data: [] } } ] });
            };
        }
    })();
</script>
