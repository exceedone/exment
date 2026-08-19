{{-- Multi-series charts rendered by Apache ECharts. --}}
{{-- Data is pivoted from a 2-column aggregate view: x_categories (X axis) x series_names (legend) => matrix[seriesIndex][xIndex]. --}}
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

        var xCategories = {!! $x_categories !!};
        var seriesNames = {!! $series_names !!};
        var matrix = {!! $matrix !!};        // matrix[seriesIndex][xIndex]
        var colors = {!! $chart_colors !!};
        var type = '{{ $chart_type }}';
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);

        var legend = { show: showLegend, type: 'scroll', top: 0, data: seriesNames };

        // helper: build hierarchical data [{name: series, children:[{name: x, value}]}]
        function buildHierarchy() {
            return seriesNames.map(function (name, i) {
                return {
                    name: String(name),
                    children: xCategories.map(function (xc, xi) {
                        return { name: String(xc), value: (matrix[i] && matrix[i][xi]) || 0 };
                    })
                };
            });
        }

        function quantile(sorted, q) {
            var pos = (sorted.length - 1) * q, base = Math.floor(pos), rest = pos - base;
            return (sorted[base + 1] !== undefined) ? sorted[base] + rest * (sorted[base + 1] - sorted[base]) : sorted[base];
        }

        function placeholder(msg) {
            el.innerHTML = '<div style="display:flex;height:100%;align-items:center;justify-content:center;'
                + 'text-align:center;color:#8a8a8a;font-size:13px;padding:16px;line-height:1.6;">' + msg + '</div>';
        }

        var option;

        switch (type) {
            case 'heatmap': {
                var data = [], maxVal = 0;
                for (var s = 0; s < matrix.length; s++) {
                    for (var x = 0; x < matrix[s].length; x++) {
                        var v = matrix[s][x];
                        data.push([x, s, v]);
                        if (v > maxVal) { maxVal = v; }
                    }
                }
                option = {
                    tooltip: { position: 'top' },
                    grid: { left: '3%', right: '7%', bottom: '10%', top: '6%', containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, splitArea: { show: true } },
                    yAxis: { type: 'category', data: seriesNames, splitArea: { show: true } },
                    visualMap: {
                        min: 0, max: maxVal || 1, calculable: true,
                        orient: 'vertical', right: 0, top: 'center',
                        inRange: { color: ['#e0ffff', '#5b8ff9', '#1d39c4'] }
                    },
                    series: [{
                        name: axisYName, type: 'heatmap', data: data,
                        label: { show: true },
                        emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,0.3)' } }
                    }]
                };
                break;
            }

            case 'treemap': {
                option = {
                    color: colors,
                    tooltip: { formatter: '{b}: {c}' },
                    series: [{
                        type: 'treemap', roam: false, data: buildHierarchy(),
                        label: { show: true },
                        levels: [{ itemStyle: { borderWidth: 3, gapWidth: 3 } }, { itemStyle: { gapWidth: 1 } }]
                    }]
                };
                break;
            }

            case 'sunburst': {
                option = {
                    color: colors,
                    tooltip: { trigger: 'item', formatter: '{b}: {c}' },
                    series: [{ type: 'sunburst', radius: ['15%', '90%'], data: buildHierarchy(), label: { minAngle: 8 } }]
                };
                break;
            }

            case 'boxplot': {
                var boxData = [];
                for (var bx = 0; bx < xCategories.length; bx++) {
                    var col = [];
                    for (var bs = 0; bs < matrix.length; bs++) { col.push((matrix[bs] && matrix[bs][bx]) || 0); }
                    col.sort(function (a, b) { return a - b; });
                    boxData.push([col[0], quantile(col, 0.25), quantile(col, 0.5), quantile(col, 0.75), col[col.length - 1]]);
                }
                option = {
                    color: colors,
                    tooltip: { trigger: 'item' },
                    grid: { left: '3%', right: '5%', bottom: '3%', top: '8%', containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, boundaryGap: true },
                    yAxis: { type: 'value', name: axisYName },
                    series: [{ type: 'boxplot', data: boxData }]
                };
                break;
            }

            default: {
                // grouped bar (mbar) / stacked bar (sbar) / multi-line (mline) / stacked area (sarea)
                var isLine = (type === 'mline' || type === 'sarea');
                var seriesType = isLine ? 'line' : 'bar';
                var stack = (type === 'sbar' || type === 'sarea') ? 'total' : undefined;

                var series = seriesNames.map(function (name, i) {
                    var s = { name: String(name), type: seriesType, data: matrix[i] || [] };
                    if (stack) { s.stack = stack; }
                    if (isLine) { s.smooth = true; s.lineStyle = { width: 2 }; }
                    if (type === 'sarea') { s.areaStyle = { opacity: 0.3 }; }
                    if (seriesType === 'bar') { s.barMaxWidth = 36; }
                    return s;
                });

                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', axisPointer: { type: seriesType === 'bar' ? 'shadow' : 'line' } },
                    legend: legend,
                    grid: { left: '3%', right: '5%', bottom: '3%', top: showLegend ? '14%' : '8%', containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, boundaryGap: seriesType === 'bar' },
                    yAxis: { type: 'value', name: axisYName },
                    series: series
                };
                break;
            }
        }

        var chart;
        try {
            // Dispose the previous instance of THIS box before re-init (a box reload replaces the
            // body HTML, so the old chart lives on a detached element — see echart.blade.php).
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

        // Click-to-filter (see ChartItem::getChartFilter): values[] is index-aligned with
        // xCategories. Only chart shapes where a click resolves to ONE x category participate:
        // the cartesian family (dataIndex = x index within a series) and heatmap (value[0] = x).
        var chartFilter = {!! $chart_filter ?? 'null' !!};
        if (chartFilter && chartFilter.column) {
            chart.on('click', function (params) {
                var xi = null;
                if (params.componentSubType === 'heatmap' && Array.isArray(params.value)) {
                    xi = params.value[0];
                } else if ((params.componentSubType === 'bar' || params.componentSubType === 'line')
                        && typeof params.dataIndex === 'number') {
                    xi = params.dataIndex;
                }
                if (xi === null || xi < 0 || xi >= xCategories.length) { return; }
                var val = chartFilter.values[xi];
                val = (val === null || val === undefined) ? '' : String(val);
                if (val === '' || !window.exmentDfPick) { return; }
                // Filter bar picker (multi-select aware): click = select this value only / click
                // the sole selected value again = clear; Ctrl/⌘-click = toggle it in the selection.
                var ne = params.event && params.event.event;
                window.exmentDfPick(chartFilter.column, val, !!(ne && (ne.ctrlKey || ne.metaKey)));
            });
        }

        // resize on window resize and when the dashboard box reloads. off() first so reloads
        // don't stack window listeners pinning previous (disposed) instances.
        var resize = function () { chart.resize(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () {
            setTimeout(resize, 50);
        });
    })();
</script>
