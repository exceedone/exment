{{-- Multi-series chart types (Apache ECharts): x_categories × series_names => matrix[seriesIndex][xIndex]. --}}
<div data-echart-id="{{ $suuid }}" style="width:100%;height:{{ $chart_height }}px;"></div>
<script type="text/javascript">
    // ECharts is fetched on first use (dashboard.js withECharts), not loaded with every admin
    // page; when the fetch fails, dashboard.js says so in this box
    ExmentDashboard.withECharts('{{ $suuid }}').done(function () {
        var el = document.querySelector('[data-echart-id="{{ $suuid }}"]');
        if (!el) { return; }

        var xCategories = {!! $x_categories !!};
        var seriesNames = {!! $series_names !!};
        var matrix = {!! $matrix !!};
        var colors = {!! $chart_colors !!};
        var type = '{{ $chart_type }}';
        var cartesian = ['mbar', 'sbar', 'mline', 'sarea'].indexOf(type) >= 0;
        var isLine = (type === 'mline' || type === 'sarea');
        var stacked = (type === 'sbar' || type === 'sarea');
        // a series' values as drawn: a stacked type piles the series up, and an empty cell
        // (null: a MIN / MAX cell no row fell into) adds nothing to the pile — 0, not a gap
        // that cuts every series above it; the other types keep the gap
        function cellsOf(i) {
            var row = matrix[i] || [];
            return stacked ? row.map(function (v) { return v == null ? 0 : v; }) : row;
        }
        // click-to-filter on the X category: {column, values[], selected[], highlight} when the X
        // column is a filter-bar item (else null) — ChartItem::clickFilter; cartesian series and
        // the heatmap only (treemap / sunburst / boxplot have no point per X value)
        var click = {!! $chart_click !!};
        var pickable = !!click && (cartesian || type === 'heatmap');
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        var legend = { show: showLegend, type: 'scroll', top: 0, data: seriesNames };
        var heatData = null; // heatmap cells [x, s, value]
        // a cell no row fell into is null for a MIN / MAX measure (ChartPivot::emptyCell): no
        // value to show, place in a heatmap or count into a boxplot's spread
        var fmt = function (n) { return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 10 }); };

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
                var maxVal = 0;
                heatData = [];
                for (var s = 0; s < matrix.length; s++) {
                    for (var x = 0; x < matrix[s].length; x++) {
                        if (matrix[s][x] == null) { continue; }
                        heatData.push([x, s, matrix[s][x]]);
                        if (matrix[s][x] > maxVal) { maxVal = matrix[s][x]; }
                    }
                }
                option = {
                    tooltip: { position: 'top' },
                    grid: { left: '3%', right: '7%', bottom: 30, top: '6%', containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, nameLocation: 'middle', nameGap: 26, splitArea: { show: true } },
                    yAxis: { type: 'category', data: seriesNames, splitArea: { show: true } },
                    visualMap: { min: 0, max: maxVal || 1, calculable: true, orient: 'vertical', right: 0, top: 'center', inRange: { color: ['#e0ffff', '#5b8ff9', '#1d39c4'] } },
                    series: [{ name: axisYName, type: 'heatmap', data: heatData, label: { show: true }, emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,0.3)' } } }]
                };
                break;
            }
            case 'treemap':
                option = {
                    color: colors,
                    tooltip: { formatter: '{b}: {c}' },
                    // no zoom into a tile on click (nodeClick): a series' tiles are already all shown
                    series: [{ type: 'treemap', roam: false, nodeClick: false, data: hierarchy(), label: { show: true }, levels: [{ itemStyle: { borderWidth: 3, gapWidth: 3 } }, { itemStyle: { gapWidth: 1 } }] }]
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
                    for (var bs = 0; bs < matrix.length; bs++) {
                        var cell = matrix[bs] ? matrix[bs][bx] : null;
                        if (cell != null) { col.push(cell); }
                    }
                    if (!col.length) { boxData.push([]); continue; }
                    col.sort(function (a, b) { return a - b; });
                    boxData.push([col[0], quantile(col, 0.25), quantile(col, 0.5), quantile(col, 0.75), col[col.length - 1]]);
                }
                option = {
                    color: colors,
                    tooltip: { trigger: 'item' },
                    grid: { left: '3%', right: '5%', bottom: 30, top: 36, containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, nameLocation: 'middle', nameGap: 26, boundaryGap: true },
                    yAxis: { type: 'value', name: axisYName, nameTextStyle: { align: 'left' } },
                    series: [{ type: 'boxplot', data: boxData }]
                };
                break;
            }
            default: { // mbar / sbar / mline / sarea
                var seriesType = isLine ? 'line' : 'bar';
                var series = seriesNames.map(function (name, i) {
                    var s = { name: String(name), type: seriesType, data: cellsOf(i) };
                    if (stacked) { s.stack = 'total'; }
                    if (isLine) { s.smooth = true; s.lineStyle = { width: 2 }; }
                    if (type === 'sarea') { s.areaStyle = { opacity: 0.3 }; }
                    if (seriesType === 'bar') { s.barMaxWidth = 36; }
                    return s;
                });
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', axisPointer: { type: seriesType === 'bar' ? 'shadow' : 'line' } },
                    legend: legend,
                    // px, not %: the legend row and the Y-axis name above the plot need fixed room, or they overlap on a short box;
                    // the Y name runs right from the axis line (centred on it, a long one is cut by the box's left edge)
                    grid: { left: '3%', right: '5%', bottom: 30, top: showLegend ? 64 : 36, containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, nameLocation: 'middle', nameGap: 26, boundaryGap: seriesType === 'bar' },
                    yAxis: { type: 'value', name: axisYName, nameTextStyle: { align: 'left' } },
                    series: series
                };
            }
        }

        // Cross-highlight: the bar's pick on this chart's own X item (the server leaves that
        // item out of the chart's filter, so every X category is here) — the picked categories
        // solid across every series, the rest faded, as per-point opacity; line types also grow
        // the picked symbols. `picked` is value => true; nothing picked = nothing faded.
        var picked = {}, pickedN = 0;
        function setPicked(list) {
            picked = {};
            pickedN = 0;
            (list || []).forEach(function (v) { v = String(v); if (!picked[v]) { picked[v] = true; pickedN++; } });
        }
        setPicked(pickable && click.highlight ? click.selected : null);
        var faded = function (xi) { return pickedN > 0 && !picked[String(click.values[xi])]; };
        function seriesData(i) {
            return cellsOf(i).map(function (v, xi) {
                var item = { value: v, itemStyle: { opacity: faded(xi) ? 0.18 : 1 } };
                if (isLine && pickedN > 0 && !faded(xi)) { item.symbol = 'circle'; item.symbolSize = 9; }
                return item;
            });
        }
        function heatSeriesData() {
            return heatData.map(function (d) { return { value: d, itemStyle: { opacity: faded(d[0]) ? 0.18 : 1 } }; });
        }

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

        // a point a click does nothing on shows no hand cursor (the series `cursor` option is
        // not read by every type, the treemap and the boxplot among them); the sunburst keeps
        // it, a click there zooms into the series
        if (!pickable && type !== 'sunburst') {
            chart.on('mousemove', function (p) {
                if (p.componentType === 'series') { chart.getZr().setCursorStyle('default'); }
            });
        }

        // click-to-filter on the X category (cartesian series: dataIndex; heatmap: value[0])
        if (pickable) {
            chart.on('click', function (p) {
                var xi = (p.componentSubType === 'heatmap' && Array.isArray(p.value)) ? p.value[0]
                    : ((p.componentSubType === 'bar' || p.componentSubType === 'line') ? p.dataIndex : null);
                if (typeof xi !== 'number' || xi >= click.values.length) { return; }
                var ne = p.event && p.event.event;
                ExmentDashboard.pick(click.column, click.values[xi], !!(ne && (ne.ctrlKey || ne.metaKey)));
            });
        }

        @if(!empty($chart_color_edit))
        // paint a series color (dashboard editors): right-click a bar / line of the series, or a
        // top-level tile of the treemap / sunburst
        chart.on('contextmenu', function (p) {
            var name = cartesian ? p.seriesName : ((p.treePathInfo && p.treePathInfo.length > 1) ? p.treePathInfo[1].name : null);
            var idx = name == null ? -1 : seriesNames.map(String).indexOf(String(name));
            if (idx < 0) { return; }
            var ne = p.event && p.event.event;
            if (ne && ne.preventDefault) { ne.preventDefault(); }
            ExmentDashboard.colorMenu('{{ $suuid }}', { kind: 'series', key: String(name), color: colors[idx % colors.length] }, ne);
        });
        @endif

        var resize = function () { chart.resize(); };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () { setTimeout(resize, 50); });

        // Per-box hooks for dashboard.js: `highlight` repaints the bar's pick on the chart's own X
        // item (`self`), `display` toggles the box menu's 値ラベル live (cartesian series), `data`
        // hands the pivot to the CSV export. A pivoted chart has no single-series point to mark,
        // so no `mark` hook. The series' data / label options are composed from one state.
        var display = { labels: {{ $chart_labels_on ? 'true' : 'false' }} };
        function render() {
            if (chart.isDisposed && chart.isDisposed()) { return; }
            if (cartesian) {
                var inside = (type === 'sbar' || type === 'sarea');
                chart.setOption({ series: seriesNames.map(function (name, i) {
                    var s = { label: { show: display.labels, position: inside ? 'inside' : 'top', fontSize: 10, color: '#2b2b3a', formatter: function (p) { return fmt(p.value); } }, labelLayout: { hideOverlap: true } };
                    if (pickable) { s.data = seriesData(i); }
                    return s;
                }) });
            } else if (type === 'heatmap' && pickable) {
                chart.setOption({ series: [{ data: heatSeriesData() }] });
            }
        }
        if (display.labels || pickedN > 0) { render(); }
        window.ExmentCharts = window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = {
            self: (pickable && click.highlight) ? click.column : null,
            highlight: function (list) { setPicked(list); render(); },
            display: function (opts) { $.extend(display, opts || {}); render(); },
            data: function () { return { axisx: axisXName, axisy: axisYName, categories: xCategories, series: seriesNames, matrix: matrix }; }
        };
    });
</script>
