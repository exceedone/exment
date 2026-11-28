{{-- The charts of the insights pages: each canvas[data-insight] in the .exm-insights around it (Chart.js of Exment). --}}
    <script>
    (function () {
        var root = document.currentScript ? document.currentScript.closest('.exm-insights') : document.querySelector('.exm-insights');
        if (!root) { return; }
        // Chart.js comes at the end of the page: on the first load, draw when it is there
        if (!window.Chart) {
            var later = function () { draw(); };
            document.addEventListener('DOMContentLoaded', later, { once: true });
            return;
        }
        draw();
        function draw() {
        if (!window.Chart || root.dataset.drawn) { return; }
        root.dataset.drawn = '1';
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark' || document.body.classList.contains('dark-mode');
        var grid = dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)';
        var text = dark ? '#cbd5e1' : '#555';
        function bytes(v) {
            var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
            while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
            return (i === 0 ? v : v.toFixed(1)) + ' ' + u[i];
        }
        root.querySelectorAll('canvas[data-insight]').forEach(function (canvas) {
            if (canvas._chart) { canvas._chart.destroy(); }
            var spec = JSON.parse(canvas.getAttribute('data-insight'));
            var ranking = spec.type === 'ranking';
            var datasets = spec.datasets.map(function (d) {
                return {
                    label: d.label, data: d.data, borderColor: d.color, backgroundColor: spec.type === 'line' ? d.color + '22' : d.color,
                    borderWidth: spec.type === 'line' ? 2 : 0, pointRadius: spec.labels.length > 45 ? 0 : 2, tension: 0.25, fill: false, maxBarThickness: 24
                };
            });
            var tick = spec.bytes ? function (v) { return bytes(v); } : undefined;
            canvas._chart = new window.Chart(canvas, {
                type: ranking ? 'bar' : spec.type,
                data: { labels: spec.labels, datasets: datasets },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    indexAxis: ranking ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: datasets.length > 1, labels: { color: text, boxWidth: 12 } },
                        tooltip: spec.bytes ? { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + bytes(ctx.parsed.y); } } } : {}
                    },
                    scales: ranking ? {
                        // the names on the left, the numbers below
                        x: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, precision: 0 } },
                        y: { grid: { display: false }, ticks: { color: text, autoSkip: false } }
                    } : {
                        x: { stacked: !!spec.stacked, grid: { color: grid }, ticks: { color: text, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                        y: Object.assign({ stacked: !!spec.stacked, beginAtZero: true, grid: { color: grid } }, { ticks: tick ? { color: text, precision: 0, callback: tick } : { color: text, precision: 0 } })
                    }
                }
            });
        });
        }
    })();
    </script>
