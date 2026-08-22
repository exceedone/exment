{{-- Multi-series chart types (Apache ECharts): x_categories × series_names => matrix[seriesIndex][xIndex]. --}}
<div data-echart-id="{{ $suuid }}" style="width:100%;height:{{ $chart_height }}px;"></div>
<script type="text/javascript">
    (function () {
        if (typeof echarts === 'undefined') { return; }
        var el = document.querySelector('[data-echart-id="{{ $suuid }}"]');
        if (!el) { return; }

        var xCategories = {!! $x_categories !!};
        var seriesNames = {!! $series_names !!};
        var matrix = {!! $matrix !!};
        var colors = {!! $chart_colors !!};
        var type = '{{ $chart_type }}';
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        var legend = { show: showLegend, type: 'scroll', top: 0, data: seriesNames };

        function hierarchy() {
            return seriesNames.map(function (name, i) {
                return { name: String(name), children: xCategories.map(function (xc, xi) { return { name: String(xc), value: (matrix[i] && matrix[i][xi]) || 0 }; }) };
            });
        }
        function quantile(sorted, q) {
            var pos = (sorted.length - 1) * q, base = Math.floor(pos), rest = pos - base;
            return (sorted[base + 1] !== undefined) ? sorted[base] + rest * (sorted[base + 1] - sorted[base]) : sorted[base];
        }

        var option;
        switch (type) {
            case 'heatmap': {
                var data = [], maxVal = 0;
                for (var s = 0; s < matrix.length; s++) {
                    for (var x = 0; x < matrix[s].length; x++) {
                        data.push([x, s, matrix[s][x]]);
                        if (matrix[s][x] > maxVal) { maxVal = matrix[s][x]; }
                    }
                }
                option = {
                    tooltip: { position: 'top' },
                    grid: { left: '3%', right: '7%', bottom: '10%', top: '6%', containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, splitArea: { show: true } },
                    yAxis: { type: 'category', data: seriesNames, splitArea: { show: true } },
                    visualMap: { min: 0, max: maxVal || 1, calculable: true, orient: 'vertical', right: 0, top: 'center', inRange: { color: ['#e0ffff', '#5b8ff9', '#1d39c4'] } },
                    series: [{ name: axisYName, type: 'heatmap', data: data, label: { show: true }, emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,0.3)' } } }]
                };
                break;
            }
            case 'treemap':
                option = {
                    color: colors,
                    tooltip: { formatter: '{b}: {c}' },
                    series: [{ type: 'treemap', roam: false, data: hierarchy(), label: { show: true }, levels: [{ itemStyle: { borderWidth: 3, gapWidth: 3 } }, { itemStyle: { gapWidth: 1 } }] }]
                };
                break;
            case 'sunburst':
                option = {
                    color: colors,
                    tooltip: { trigger: 'item', formatter: '{b}: {c}' },
                    series: [{ type: 'sunburst', radius: ['15%', '90%'], data: hierarchy(), label: { minAngle: 8 } }]
                };
                break;
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
            default: { // mbar / sbar / mline / sarea
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
            }
        }

        // a pivoted chart has no single-series point to mark: drop any marker hook of a previous render
        if (window.ExmentCharts) { delete window.ExmentCharts['{{ $suuid }}']; }

        window.ExmentECharts = window.ExmentECharts || {};
        var prior = window.ExmentECharts['{{ $suuid }}'];
        if (prior && !(prior.isDisposed && prior.isDisposed())) { try { prior.dispose(); } catch (e) {} }
        var chart;
        try {
            chart = echarts.init(el);
            window.ExmentECharts['{{ $suuid }}'] = chart;
            chart.setOption(option);
        } catch (e) {
            el.innerHTML = '<div class="exment-chart-error">' + $('<i>').text(@json(exmtrans('dashboard.message.chart_render_error'))).html() + '</div>';
            return;
        }

        // click-to-filter on the X category (cartesian series: dataIndex; heatmap: value[0])
        var click = {!! $chart_click !!};
        if (click) {
            chart.on('click', function (p) {
                var xi = (p.componentSubType === 'heatmap' && Array.isArray(p.value)) ? p.value[0]
                    : ((p.componentSubType === 'bar' || p.componentSubType === 'line') ? p.dataIndex : null);
                if (typeof xi !== 'number' || xi >= click.values.length) { return; }
                var ne = p.event && p.event.event;
                ExmentDashboard.pick(click.column, click.values[xi], !!(ne && (ne.ctrlKey || ne.metaKey)));
            });
        }

        var resize = function () { chart.resize(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () { setTimeout(resize, 50); });
    })();
</script>
