<div>
    <canvas data-canvas-id="{{$suuid}}"></canvas>
</div>
<script type="text/javascript">
    $(function () {
        // jQuery 3 runs this a tick later: the box may have been emptied for its next load meanwhile
        var canvas = $('[data-canvas-id="{{$suuid}}"]')[0];
        if (!canvas) { return; }
        var ctx = canvas.getContext('2d');
        ctx.canvas.height = {!! $chart_height !!};
        // click-to-filter: {column, values[], selected[], highlight} when the group column is a
        // filter-bar item (else null) — ChartItem::clickFilter
        var click = {!! $chart_click !!};
        // 明細: a double click on a point lists the rows behind it — {url, points[i] = the point's
        // group values} (ChartItem::detailPayload), else null
        var detail = {!! $chart_detail ?? 'null' !!};
        var detailOf = function (i) {
            return (detail && detail.points && detail.points[i]) ? function () { ExmentDashboard.openDetail('{{ $suuid }}', detail.url, detail.points[i]); } : null;
        };
        var labels = {!! $chart_labels !!};
        var values = {!! $chart_data !!};
        // the その他 point of 表示件数 (ChartItem::restTip): {index, count, items, more}, else null
        var rest = {!! $chart_rest ?? 'null' !!};
        // display option of the box menu (値ラベル), live-toggled through the hook below
        var display = { labels: {{ $chart_labels_on ? 'true' : 'false' }} };
        // switched by the box menu's 集計方法, the tooltip names what the value is (最小値 ： 点数); null otherwise
        var valueName = @json($chart_value_name ?? null);
        var num = function (v) { var n = parseFloat(v); return isNaN(n) ? null : n; };
        // the values are percents (the box menu's 表示方法: 総計に対する比率 / 差分の比率), 1 decimal
        var percent = {{ !empty($chart_percent) ? 'true' : 'false' }};
        // the value as it is (0.04 stays 0.04), grouped; up to 10 decimals cut off float noise
        var fmt = percent
            ? function (n) { return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }
            : function (n) { return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 10 }); };

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

        // The point colors from the highlight state (faded when not picked). base is one
        // color for line, a per-point palette array for the other types.
        var base = {!! $chart_color !!};
        var baseOf = function (i) { return $.isArray(base) ? base[i % base.length] : base; };
        var paint = function (i) {
            var color = baseOf(i);
            return faded(i) ? fade(color, 0.18) : color;
        };
        var pointColors = function () { return values.map(function (v, i) { return paint(i); }); };
        var pointRadii = function () { return values.map(function (v, i) { return (pickedN > 0 && !faded(i)) ? 5 : 3; }); };

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
                    // the legend names the dataset: the Y axis' name (Chart.js shows "undefined" without one)
                    label: @json((string) ($chart_axisy ?? '')),
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
                // a click picks the point on the filter bar, a double click lists its rows (明細)
                onClick: function (evt, elements) {
                    if (!elements.length) { return; }
                    var i = elements[0]._index, toggle = !!(evt.ctrlKey || evt.metaKey);
                    ExmentDashboard.pointClick('{{ $suuid }}', 'p' + i, click ? function () { ExmentDashboard.pick(click.column, click.values[i], toggle); } : null, detailOf(i));
                },
                hover: { onHover: function (evt, elements) { evt.target.style.cursor = ((click || detail) && elements.length) ? 'pointer' : 'default'; } },
                tooltips: { callbacks: {
                    // the way to the point's rows
                    footer: function () { return detail ? ExmentDashboard.detailHint() : ''; },
                    // その他 lists the points it groups
                    afterBody: function (items) {
                        return (rest && items.length && items[0].index === rest.index) ? ExmentDashboard.restLines(rest) : [];
                    },
                    label: function (item, data) {
                        var v = data.datasets[item.datasetIndex].data[item.index];
                        @if($chart_type == 'pie')
                        var name = data.labels ? data.labels[item.index] + ': ' : '';
                        @else
                        var name = valueName ? valueName + ': ' : '';
                        @endif
                        return name + (v == null ? '' : (percent ? fmt(num(v)) : String(v)));
                    },
                    @if($chart_type == 'pie')
                    // a slice's tooltip has no title ('' as Chart.js gives it): the switched measure heads it
                    title: function () { return valueName || ''; },
                    @endif
                }, footerFontStyle: 'normal', footerFontSize: 11 },
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
                            @if(!empty($chart_percent))
                            callback: function (v) { return Number(v).toLocaleString() + '%'; },
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
            @if($chart_type != 'line')
            // その他 is no category of its own to paint
            if (rest && el._index === rest.index) { return; }
            @endif
            evt.preventDefault();
            @if($chart_type == 'line')
            ExmentDashboard.colorMenu('{{ $suuid }}', { kind: 'series', key: '', color: base }, evt);
            @else
            ExmentDashboard.colorMenu('{{ $suuid }}', { kind: 'points', key: String(labels[el._index]), color: baseOf(el._index) }, evt);
            @endif
        });
        @endif

        // the points repainted from the current highlight state
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
        // X item (`self`), `display` toggles the box menu's 値ラベル live, `data` hands the
        // plotted points to the CSV export.
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
            data: function () { return { axisx: @json($chart_axisx), axisy: @json($chart_axisy), labels: labels, values: values, percent: percent }; }
        };
    });
</script>
