<div>
    <canvas id="myChart" width="400" height="250"></canvas>
</div>
<script type="text/javascript">
    $(function () {
        var ctx = document.getElementById("myChart").getContext('2d');
        var myChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: {!! $chart_labels !!},
                datasets: [{
                    data: {!! $chart_data !!},
                    borderWidth: 1
                }]
            },
            options: {
                legend: {
                    display: false
                },
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero:true
                        }
                    }]
                }
            }
        });
    });
</script>