<div>
    <canvas data-canvas-id="{{$suuid}}"></canvas>
</div>
{{-- Drill affordance: filled by JS with the drillable labels present in THIS render's data,
     so it disappears when the dashboard filter narrows those bars away. --}}
<div data-drill-hint="{{$suuid}}" style="display:none; text-align:right; margin:2px 8px 0; font-size:11px; color:#999;"></div>

{{-- AI summary strip — hidden when the insight feature is off or this box's data is
     barred from the AI (blocked table / dashboard opt-out); server enforces the same. --}}
@if($ai_insight ?? true)
@include('exment::dashboard.chart._insight_strip')
@endif
<script type="text/javascript">
    $(function () {
        // Drop any marker toggle left by a previous render of this box FIRST: a box AJAX
        // reload re-runs this script, and a leftover entry would point at a destroyed
        // Chart.js instance (the strip toggle would throw before loading the insight).
        // Re-registered at the bottom only when this render actually has an anomaly.
        window.ExmentChartMarkers = window.ExmentChartMarkers || {};
        delete window.ExmentChartMarkers['{{ $suuid }}'];

        var ctx = $('[data-canvas-id="{{$suuid}}"]')[0].getContext('2d');
        ctx.canvas.height = {!! $chart_height !!};

        var chartData = {!! $chart_data !!};
        var baseColor = {!! $chart_color !!};
        // Deterministic anomaly markers: {lower, upper, points:[{index, value, direction}]} or null.
        var anomaly = {!! $anomaly !!};
        // Drill-down: x-axis label => URL. Clicking that bar/point navigates there.
        var drillUrls = {!! $chart_drill ?? '{}' !!};
        var hasDrill = Object.keys(drillUrls).length > 0;
        // Cross-filter: {column, values[]} — values[i] is the raw stored value behind data
        // point i (see ChartItem::getChartFilter). Clicking a point (when it has no drill URL)
        // selects that value on the dashboard filter bar through the bar's own picker
        // (window.exmentDfPick, defined by filter_bar.blade.php — the payload exists only when
        // the column is a dim of that bar).
        var chartFilter = {!! $chart_filter ?? 'null' !!};
        // Peer-average reference line (ChartItem passes the mean of the plotted values when
        // the box opts in via chart_benchmark) — dashed, with a small right-aligned label.
        // The caption is formatted server-side so it matches the KPI tiles' number format and
        // names what is being averaged (the bars, not the whole scope).
        var benchmark = {!! $chart_benchmark ?? 'null' !!};
        var benchmarkLabel = {!! $chart_benchmark_label ?? 'null' !!};
        // Active chart-level filters as "label: value" lines (ChartItem::boxFilterContext), shown
        // in every tooltip so a bar filtered down to one child says which child.
        var filterContext = {!! $chart_filter_context ?? '[]' !!};
        // click-to-filter is available when the picker is on the page and the dim is on the bar
        // (an enabled select, or a range dim the picker edits through the URL)
        var hasFilter = false;
        if (window.exmentDfPick && chartFilter && chartFilter.column) {
            var dfSel = document.querySelector('.df-select[data-column="' + chartFilter.column + '"]');
            hasFilter = !!((dfSel && !dfSel.disabled)
                || document.querySelector('.df-range[data-column="' + chartFilter.column + '"]'));
        }
        // click = select this value only / click the sole selected value again = clear;
        // Ctrl/⌘-click = toggle it in the selection (multi-select aware picker)
        function applyChartFilter(index, evt) {
            if (!window.exmentDfPick || !chartFilter || !chartFilter.column) { return; }
            var val = chartFilter.values[index];
            val = (val === null || val === undefined) ? '' : String(val);
            if (val === '') { return; }
            window.exmentDfPick(chartFilter.column, val, !!(evt && (evt.ctrlKey || evt.metaKey)));
        }

        // Index → direction of every flagged outlier, so the matching bar/point is drawn amber.
        var anomAt = {};
        if (anomaly && anomaly.points) {
            anomaly.points.forEach(function (p) { anomAt[p.index] = p.direction; });
        }
        var hasAnom = Object.keys(anomAt).length > 0;
        var ANOM_COLOR = '#e0a020';
        // Opt-in: markers stay hidden until the "AI summary" strip is expanded (see the
        // applyAnomaly registration below), so a freshly loaded dashboard is clean.
        var markersOn = false;
        // base may be a per-bar color ARRAY when category shading is on — index into it.
        function perPoint(base, hit) {
            return chartData.map(function (_, i) {
                return anomAt[i] !== undefined ? hit : (Array.isArray(base) ? base[i] : base);
            });
        }

        // Ordinal category shading (chart_category_shade_views): tint each bar light→dark of
        // the base color (mixed toward white, staying opaque so gridlines don't show through).
        // The throwaway canvas fillStyle round-trip is the browser's own CSS color parser —
        // it normalizes any config color ('red', '#e83e8c', rgba(...)) to hex/rgba.
        var useShades = {!! $chart_shades ?? 'false' !!};
        if (useShades && chartData.length > 1) {
            var cctx = document.createElement('canvas').getContext('2d');
            cctx.fillStyle = baseColor;
            var parsed = cctx.fillStyle;
            var rgb = null;
            var mh = /^#([0-9a-f]{6})$/i.exec(parsed);
            if (mh) {
                rgb = [parseInt(mh[1].substr(0, 2), 16), parseInt(mh[1].substr(2, 2), 16), parseInt(mh[1].substr(4, 2), 16)];
            } else {
                var mr = /^rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(parsed);
                if (mr) { rgb = [+mr[1], +mr[2], +mr[3]]; }
            }
            if (rgb) {
                baseColor = chartData.map(function (_, i) {
                    var f = 0.45 + 0.55 * (i / (chartData.length - 1));
                    return 'rgb(' + rgb.map(function (c) { return Math.round(255 - (255 - c) * f); }).join(',') + ')';
                });
            }
        }

        var dataset = {
            label: {!! json_encode($chart_axisy ?? '') !!},
            data: chartData,
            borderWidth: 1
        };
        @if($chart_type != 'line')
        dataset.backgroundColor = baseColor;
        dataset.fill = true;
        @else
        dataset.lineTension = 0; // draw straightline
        dataset.borderColor = baseColor;
        dataset.fill = false;
        dataset.pointBackgroundColor = baseColor;
        dataset.pointBorderColor = baseColor;
        dataset.pointRadius = 3;
        dataset.pointHoverRadius = 4;
        @endif

        // Destroy any prior Chart.js instance on this canvas before re-creating it. A dashboard
        // box reload replaces its HTML and re-runs this script; Chart.js 2 keeps instances in
        // Chart.instances with their own resize plumbing, so skipping destroy() leaks them.
        window.ExmentCharts = window.ExmentCharts || {};
        if (window.ExmentCharts['{{ $suuid }}']) {
            try { window.ExmentCharts['{{ $suuid }}'].destroy(); } catch (e) {}
        }

        var chartLabels = {!! $chart_labels !!};

        // Inline Chart.js-2 plugin drawing the benchmark line over the bars/points. The value
        // axis is y (vertical bar / line charts — the only types the server sends benchmark for).
        var benchPlugins = [];
        if (benchmark !== null && isFinite(benchmark)) {
            benchPlugins.push({
                afterDatasetsDraw: function (chart) {
                    var yScale = chart.scales['y-axis-0'];
                    if (!yScale) { return; }
                    var y = yScale.getPixelForValue(benchmark);
                    if (!isFinite(y) || y < chart.chartArea.top || y > chart.chartArea.bottom) { return; }
                    var c = chart.ctx;
                    c.save();
                    c.strokeStyle = '#8a8aa0';
                    c.setLineDash([5, 4]);
                    c.lineWidth = 1.2;
                    c.beginPath();
                    c.moveTo(chart.chartArea.left, y);
                    c.lineTo(chart.chartArea.right, y);
                    c.stroke();
                    c.setLineDash([]);
                    c.font = '10px sans-serif';
                    c.fillStyle = '#77778c';
                    c.textAlign = 'right';
                    c.textBaseline = 'bottom';
                    c.fillText(benchmarkLabel || benchmark.toLocaleString(), chart.chartArea.right - 4, y - 2);
                    c.restore();
                }
            });
        }

        var myChart = new Chart(ctx, {
            type: '{{ $chart_type }}',
            plugins: benchPlugins,
            data: {
                labels: chartLabels,
                datasets: [dataset]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: function (evt) {
                    if (!hasDrill && !hasFilter) { return; }
                    var el = myChart.getElementAtEvent(evt)[0];
                    if (!el) { return; }
                    var label = myChart.data.labels[el._index];
                    // Drill URL wins (curated story path); otherwise cross-filter the dashboard.
                    if (drillUrls[label]) { window.location.href = drillUrls[label]; return; }
                    applyChartFilter(el._index, evt);
                },
                hover: {
                    onHover: function (e, els) {
                        if (!hasDrill && !hasFilter) { return; }
                        e.target.style.cursor = (els && els.length) ? 'pointer' : 'default';
                    }
                },
                @if(!$chart_legend)
                legend : {
                    display: false
                },
                @endif
                tooltips: {
                    callbacks: {
                        // Extra lines under the value: the box's active chart-level filters
                        // ("児童: 竹内 湊") so a filtered bar says what it is made of — the Power BI
                        // tooltip cue — then, on an outlier bar/point, the expected range.
                        afterBody: function (items) {
                            var lines = (filterContext || []).slice();
                            if (markersOn && hasAnom && items && items.length) {
                                var idx = items[0].index;
                                if (anomAt[idx] !== undefined) {
                                    var arrow = anomAt[idx] === 'high' ? '▲' : '▼';
                                    var r1 = Math.round(anomaly.lower * 100) / 100;
                                    var r2 = Math.round(anomaly.upper * 100) / 100;
                                    lines.push('⚠ ' + arrow + ' ' + {!! json_encode(exmtrans('dashboard.ai_insight.expected_range')) !!}
                                        + ': ' + r1 + ' – ' + r2);
                                }
                            }
                            return lines.length ? lines : '';
                        }
                    }
                },
                @if($chart_type != 'pie')
                scales: {
                    xAxes: [{
                        ticks: {
                            @if(!$chart_axisx_label)
                            display: false,
                            @endif
                            // Show EVERY category label: Chart.js auto-skips alternate labels
                            // when the box is narrow, so a 25-student ranking looked like 13
                            // students. Long lists rotate steeply instead; the skip only comes
                            // back past 60 labels, where per-label text stops being readable.
                            autoSkip: chartLabels.length > 60,
                            maxRotation: 90,
                            minRotation: chartLabels.length > 12 ? 60 : 0,
                        },
                        @if($chart_axisx_name)
                        scaleLabel: {
							display: true,
							labelString: {!! json_encode($chart_axisx) !!}
						}
                        @endif
                    }],
                    yAxes: [{
                        ticks: {
                            @if(!$chart_axisy_label)
                            display: false,
                            @endif
                            @if($chart_begin_zero)
                            beginAtZero: true,
                            @endif
                        },
                        @if($chart_axisy_name)
                        scaleLabel: {
							display: true,
							labelString: {!! json_encode($chart_axisy) !!}
						}
                        @endif
                    }]
                },
                @endif
            }
        });

        // Opt-in anomaly markers: hidden until the "AI summary" strip is expanded. Registered
        // per box so the strip toggle (dashboard/insight.blade.php) can flip them on/off.
        function applyAnomalyMarkers(on) {
            // Stale-closure no-op: after a box reload a newer render owns this canvas.
            if (!hasAnom || window.ExmentCharts['{{ $suuid }}'] !== myChart) { return; }
            markersOn = !!on;
            var ds = myChart.data.datasets[0];
            @if($chart_type != 'line')
            ds.backgroundColor = markersOn ? perPoint(baseColor, ANOM_COLOR) : baseColor;
            @else
            ds.pointBackgroundColor = markersOn ? perPoint(baseColor, ANOM_COLOR) : baseColor;
            ds.pointBorderColor = markersOn ? perPoint(baseColor, ANOM_COLOR) : baseColor;
            ds.pointRadius = markersOn ? chartData.map(function (_, i) { return anomAt[i] !== undefined ? 6 : 3; }) : 3;
            ds.pointHoverRadius = markersOn ? chartData.map(function (_, i) { return anomAt[i] !== undefined ? 8 : 4; }) : 4;
            @endif
            myChart.update();
        }
        if (hasAnom) {
            window.ExmentChartMarkers['{{ $suuid }}'] = applyAnomalyMarkers;
        }

        // Drill hint: list the labels that navigate somewhere, as links. Built from the
        // rendered data ∩ drillUrls, so a filtered-out drill target leaves no stale hint.
        // When the chart is cross-filterable, append a "click to filter" note on the same line.
        var drillHintEl = $('[data-drill-hint="{{ $suuid }}"]');
        if (drillHintEl.length && (hasDrill || hasFilter)) {
            var hintShown = false;
            drillHintEl.empty();
            var drillableLabels = (myChart.data.labels || []).filter(function (l) { return !!drillUrls[l]; });
            if (drillableLabels.length) {
                drillHintEl.append(document.createTextNode({!! json_encode(exmtrans('dashboard.filter_bar.drill_hint')) !!} + ' '));
                drillableLabels.forEach(function (label, i) {
                    if (i > 0) { drillHintEl.append(document.createTextNode(', ')); }
                    drillHintEl.append($('<a></a>').attr('href', drillUrls[label]).text(label));
                });
                hintShown = true;
            }
            if (hasFilter) {
                if (hintShown) { drillHintEl.append(document.createTextNode(' ・ ')); }
                drillHintEl.append(document.createTextNode({!! json_encode(exmtrans('dashboard.filter_bar.click_filter_hint')) !!}));
                hintShown = true;
            }
            if (hintShown) { drillHintEl.show(); }
        }

        window.ExmentCharts['{{ $suuid }}'] = myChart;
    });
</script>
