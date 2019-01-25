<div>
    <canvas id="chart_container"></canvas>
</div>
<script type="text/javascript">
    $(function () {
        var ctx = document.getElementById("chart_container").getContext('2d');
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
                    borderColor: {!! $chart_color !!},
                    pointBackgroundColor: {!! $chart_color !!},
                    fill: false,
                    @endif
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
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
							labelString: '{{ $chart_axisx }}'
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
							labelString: '{{ $chart_axisy }}'
						}
                        @endif
                    }]
                },
                @endif
            }
        });
    });
</script>