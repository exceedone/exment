<div>
    <canvas data-canvas-id="{{$suuid}}"></canvas>
</div>
<script type="text/javascript">
    $(function () {
        var ctx = $('[data-canvas-id="{{$suuid}}"]')[0].getContext('2d');
        ctx.canvas.height = {!! $chart_height !!};
        // click-to-filter: {column, values[], selected[], highlight} when the group column is a
        // filter-bar item (else null) — ChartItem::clickFilter
        var click = {!! $chart_click !!};
        var labels = {!! $chart_labels !!};
        var values = {!! $chart_data !!};
        // record count behind each point (averaged charts), else null
        var counts = {!! $chart_counts !!};
        // display option of the box menu (値ラベル), live-toggled through the hook below
        var display = { labels: {{ $chart_labels_on ? 'true' : 'false' }} };
        var COUNT_FMT = @json(exmtrans('dashboard.chart_menu.count_fmt'));
        var num = function (v) { var n = parseFloat(v); return isNaN(n) ? null : n; };
        var fmt = function (n) { return (Math.round(n * 10) / 10).toLocaleString(); };

        // Cross-highlight: the bar's pick on this chart's own X item (the server leaves that
        // item out of the chart's filter, so every category is here) — the picked points
        // solid, the rest faded. `picked` is value => true; nothing picked = nothing faded.
        var picked = {}, pickedN = 0;
        function setPicked(list) {
            picked = {};
            pickedN = 0;
            (list || []).forEach(function (v) { v = String(v); if (!picked[v]) { picked[v] = true; pickedN++; } });
        }
        setPicked(click && click.highlight ? click.selected : null);
        var faded = function (i) { return pickedN > 0 && !picked[String(click.values[i])]; };

        // `color` at `alpha` — any CSS color, normalized through a canvas. Defined here and not
        // in dashboard.js on purpose: the box body is server-rendered on every load while that
        // file is a cached static asset, and a chart that paints its own fade cannot end up half
        // highlighted (faded labels, solid bars) when the two are out of step.
        var fadeCtx = null;
        function fade(color, alpha) {
            try {
                fadeCtx = fadeCtx || document.createElement('canvas').getContext('2d');
                fadeCtx.fillStyle = '#000';
                fadeCtx.fillStyle = color;
                var s = fadeCtx.fillStyle, m = /^#([0-9a-f]{6})$/i.exec(s);
                if (m) { var n = parseInt(m[1], 16); return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + alpha + ')'; }
                m = /^rgba?\(([^)]+)\)$/.exec(s);
                if (m) { var p = m[1].split(',').map(parseFloat); return 'rgba(' + p[0] + ',' + p[1] + ',' + p[2] + ',' + alpha + ')'; }
            } catch (e) {}
            return color;
        }

        // The point colors, composed from one state: the AI summary's anomalies (amber) and
        // the highlight (faded when not picked). base is one color for line, a per-point
        // palette array for the other types.
        var base = {!! $chart_color !!}, AMBER = '#e0a020', anomaly = null, flagged = {};
        var baseOf = function (i) { return $.isArray(base) ? base[i % base.length] : base; };
        var paint = function (i) {
            var color = flagged[i] ? AMBER : baseOf(i);
            return faded(i) ? fade(color, 0.18) : color;
        };
        var pointColors = function () { return values.map(function (v, i) { return paint(i); }); };
        var pointRadii = function () { return values.map(function (v, i) { return flagged[i] ? 6 : ((pickedN > 0 && !faded(i)) ? 5 : 3); }); };

        // px: gap between the point and its label, the label's line height, and the room the
        // layout keeps above the plot so a label on a full-height bar still fits in the canvas
        var LABEL_GAP = 3, LABEL_H = 11, LABEL_ROOM = LABEL_GAP + LABEL_H + 2;

        // Chart.js 2.x plugin: the value on every bar / point / slice (faded along with its point)
        var displayPlugin = {
            afterDatasetsDraw: function (chart) {
                var c = chart.chart.ctx, meta = chart.getDatasetMeta(0), data = chart.data.datasets[0].data;
                if (!meta || !meta.data) { return; }
                var area = chart.chartArea;
                c.save();
                c.font = '600 11px sans-serif';
                if (display.labels) {
                    c.fillStyle = '#2b2b3a';
                    c.strokeStyle = 'rgba(255,255,255,0.85)';
                    c.lineWidth = 3;
                    c.textAlign = 'center';
                    meta.data.forEach(function (el, i) {
                        var n = num(data[i]);
                        if (n === null || el.hidden) { return; }
                        var text = fmt(n), x, y;
                        @if($chart_type == 'pie')
                        var pos = el.tooltipPosition();
                        c.textBaseline = 'middle';
                        x = pos.x;
                        y = pos.y;
                        @else
                        var m = el._model;
                        c.textBaseline = 'bottom';
                        y = m.y - LABEL_GAP;
                        // keep a label centered on an edge point from spilling out sideways
                        var half = c.measureText(text).width / 2;
                        x = area ? Math.min(Math.max(m.x, area.left + half), area.right - half) : m.x;
                        @endif
                        c.globalAlpha = faded(i) ? 0.35 : 1;
                        c.strokeText(text, x, y);
                        c.fillText(text, x, y);
                    });
                }
                c.restore();
            }
        };

        var myChart = new Chart(ctx, {
            type: '{{ $chart_type }}',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    @if($chart_type != 'line')
                    backgroundColor: pointColors(),
                    fill: true,
                    @else
                    lineTension: 0, // draw straightline
                    borderColor: base,
                    pointBackgroundColor: pointColors(),
                    pointRadius: pointRadii(),
                    fill: false,
                    @endif
                    borderWidth: 1
                }]
            },
            plugins: [displayPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                @if($chart_type != 'pie')
                // a bar that reaches the top tick would push its value label off the canvas:
                // the plot gives up LABEL_ROOM px while the labels are on
                layout: { padding: { top: display.labels ? LABEL_ROOM : 0 } },
                @endif
                onClick: function (evt, elements) {
                    if (click && elements.length) { ExmentDashboard.pick(click.column, click.values[elements[0]._index], evt.ctrlKey || evt.metaKey); }
                },
                hover: { onHover: function (evt, elements) { evt.target.style.cursor = (click && elements.length) ? 'pointer' : 'default'; } },
                // an averaged chart names the record count behind the point
                tooltips: { callbacks: { label: function (item, data) {
                    var v = data.datasets[item.datasetIndex].data[item.index], n = counts ? counts[item.index] : null;
                    var text = (@json($chart_type == 'pie') && data.labels ? data.labels[item.index] + ': ' : '') + (v == null ? '' : String(v));
                    return (n == null) ? text : text + COUNT_FMT.replace('%s', Number(n).toLocaleString());
                } } },
                @if(!$chart_legend)
                legend : {
                    display: false
                },
                @endif
                @if($chart_type != 'pie')
                scales: {
                    xAxes: [{
                        ticks: {
                            @if(!$chart_axisx_label)
                            display: false,
                            @endif
                        },
                        @if($chart_axisx_name)
                        scaleLabel: {
                            display: true,
                            labelString: @json($chart_axisx)
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
                            labelString: @json($chart_axisy)
                        }
                        @endif
                    }]
                },
                @endif
            }
        });

        @if(!empty($chart_color_edit))
        // paint a color (dashboard editors): right-click a bar / slice; a line is one series
        ctx.canvas.addEventListener('contextmenu', function (evt) {
            var el = myChart.getElementAtEvent(evt)[0];
            if (!el) { return; }
            evt.preventDefault();
            @if($chart_type == 'line')
            ExmentDashboard.colorMenu('{{ $suuid }}', { kind: 'series', key: '', color: base }, evt);
            @else
            ExmentDashboard.colorMenu('{{ $suuid }}', { kind: 'points', key: String(labels[el._index]), color: baseOf(el._index) }, evt);
            @endif
        });
        @endif

        // the points repainted from the current state (anomalies + highlight)
        function repaint() {
            var ds = myChart.data.datasets[0];
            @if($chart_type == 'line')
            ds.pointBackgroundColor = pointColors();
            ds.pointRadius = pointRadii();
            @else
            ds.backgroundColor = pointColors();
            @endif
            myChart.update();
        }

        // Per-box hooks for dashboard.js: `highlight` repaints the bar's pick on the chart's own
        // X item (`self`), `mark` paints the AI summary's anomalies (amber bars / points, expected
        // range in the tooltip; value-axis types only), `display` toggles the box menu's 値ラベル
        // live, `data` hands the plotted points to the CSV export.
        window.ExmentCharts = window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = {
            self: (click && click.highlight) ? click.column : null,
            highlight: function (list) { setPicked(list); repaint(); },
            display: function (opts) {
                display = $.extend(display, opts || {});
                @if($chart_type != 'pie')
                myChart.options.layout.padding.top = display.labels ? LABEL_ROOM : 0;
                @endif
                myChart.update();
            },
            data: function () { return { axisx: @json($chart_axisx), axisy: @json($chart_axisy), labels: labels, values: values }; },
            mark: function (a) {
            @if($chart_type == 'pie')
            return;
            @else
            anomaly = a || null;
            flagged = {};
            ((anomaly && anomaly.points) || []).forEach(function (p) { flagged[p.index] = true; });
            var rng = function (n) { return Math.round(n * 10) / 10; };
            myChart.options.tooltips = myChart.options.tooltips || {};
            myChart.options.tooltips.callbacks = myChart.options.tooltips.callbacks || {};
            myChart.options.tooltips.callbacks.afterLabel = function (item) {
                return (anomaly && flagged[item.index]) ? @json(exmtrans('dashboard.ai.expected_range')) + ': ' + rng(anomaly.lower) + ' – ' + rng(anomaly.upper) : '';
            };
            repaint();
            @endif
            }
        };
    });
</script>
