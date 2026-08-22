<div>
    <canvas data-canvas-id="{{$suuid}}"></canvas>
</div>
<script type="text/javascript">
    $(function () {
        var ctx = $('[data-canvas-id="{{$suuid}}"]')[0].getContext('2d');
        ctx.canvas.height = {!! $chart_height !!};
        // click-to-filter: {column, values[]} when the group column is a filter-bar item (else null)
        var click = {!! $chart_click !!};
        var myChart = new Chart(ctx, {
            type: '{{ $chart_type }}',
            data: {
                labels: {!! $chart_labels !!},
                datasets: [{
                    data: {!! $chart_data !!},
                    @if($chart_type != 'line')
                    backgroundColor: {!! $chart_color !!},
                    fill: true,
                    @else
                    lineTension: 0, // draw straightline
                    borderColor: {!! $chart_color !!},
                    pointBackgroundColor: {!! $chart_color !!},
                    fill: false,
                    @endif
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: function (evt, elements) {
                    if (click && elements.length) { ExmentDashboard.pick(click.column, click.values[elements[0]._index], evt.ctrlKey || evt.metaKey); }
                },
                hover: { onHover: function (evt, elements) { evt.target.style.cursor = (click && elements.length) ? 'pointer' : 'default'; } },
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

        // Anomaly markers, toggled by the AI summary strip (dashboard.js): the flagged bars /
        // points turn amber and their tooltip names the expected range. Value-axis types only.
        window.ExmentCharts = window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = { mark: function (anomaly) {
            @if($chart_type == 'pie')
            return;
            @else
            var ds = myChart.data.datasets[0], base = {!! $chart_color !!}, AMBER = '#e0a020', flagged = {};
            ((anomaly && anomaly.points) || []).forEach(function (p) { flagged[p.index] = true; });
            var paint = function (i) { return flagged[i] ? AMBER : base; };
            @if($chart_type == 'line')
            ds.pointBackgroundColor = ds.data.map(function (v, i) { return paint(i); });
            ds.pointRadius = ds.data.map(function (v, i) { return flagged[i] ? 6 : 3; });
            @else
            ds.backgroundColor = ds.data.map(function (v, i) { return paint(i); });
            @endif
            var fmt = function (n) { return Math.round(n * 10) / 10; };
            myChart.options.tooltips = myChart.options.tooltips || {};
            myChart.options.tooltips.callbacks = myChart.options.tooltips.callbacks || {};
            myChart.options.tooltips.callbacks.afterLabel = function (item) {
                return (anomaly && flagged[item.index]) ? @json(exmtrans('dashboard.ai.expected_range')) + ': ' + fmt(anomaly.lower) + ' – ' + fmt(anomaly.upper) : '';
            };
            myChart.update();
            @endif
        } };
    });
</script>
