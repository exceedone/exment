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
        var colors = {!! $chart_colors !!};
        var type = '{{ $chart_type }}';
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        var pairs = labels.map(function (l, i) { return { name: String(l), value: values[i] }; });
        var grid = { left: '3%', right: '5%', bottom: '3%', top: '8%', containLabel: true };
        var option;

        switch (type) {
            case 'hbar':
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    grid: { left: '3%', right: '6%', bottom: '3%', top: '8%', containLabel: true },
                    xAxis: { type: 'value', name: axisYName },
                    yAxis: { type: 'category', data: labels, name: axisXName, inverse: true },
                    series: [{ type: 'bar', data: values, barMaxWidth: 28, itemStyle: { borderRadius: [0, 4, 4, 0] } }]
                };
                break;
            case 'area':
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis' },
                    grid: grid,
                    xAxis: { type: 'category', boundaryGap: false, data: labels, name: axisXName },
                    yAxis: { type: 'value', name: axisYName },
                    series: [{ type: 'line', data: values, smooth: true, areaStyle: { opacity: 0.25 }, lineStyle: { width: 2 } }]
                };
                break;
            case 'doughnut':
                option = {
                    color: colors,
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
                    color: colors,
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
                    color: colors,
                    tooltip: { trigger: 'item', formatter: function (p) { return escHtml(labels[p.dataIndex]) + ': ' + p.value[1]; } },
                    grid: grid,
                    xAxis: { type: 'category', data: labels, name: axisXName },
                    yAxis: { type: 'value', name: axisYName },
                    series: [{ type: 'scatter', symbolSize: 14, data: values.map(function (v, i) { return [i, v]; }) }]
                };
                break;
            default:
                return;
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

        var resize = function () { chart.resize(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () { setTimeout(resize, 50); });
    })();
</script>
