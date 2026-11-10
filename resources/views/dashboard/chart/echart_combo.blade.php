{{--
    複合 (Excel's 組み合わせグラフ; ChartItem::comboVars, ChartSecondValue), drawn by Apache ECharts:
    the 値 as bars on the left axis, the 2つ目の値 as a line — or bars — on an axis of its own on the
    right (or on the left one with the 値). Each axis is named and numbered in its own units, the
    tooltip names both values, the legend says which axis each one is read on.
--}}
<div data-echart-id="{{ $suuid }}" style="width:100%;height:{{ $chart_height }}px;"></div>
<script type="text/javascript">
    // ECharts is fetched on first use (dashboard.js withECharts), as for the other ECharts types
    ExmentDashboard.withECharts('{{ $suuid }}').done(function () {
        var el = document.querySelector('[data-echart-id="{{ $suuid }}"]');
        if (!el) { return; }

        // a function tooltip formatter is injected as innerHTML — escape labels there
        function escHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        // a point without a value stays null (no bar / a gap in the line), not a 0
        var number = function (v) { var n = parseFloat(v); return isNaN(n) ? null : n; };

        var labels = {!! $chart_labels !!};
        var values = ({!! $chart_data !!} || []).map(number);
        var values2 = ({!! $chart_data2 !!} || []).map(number);
        // {name1, name2, legend1, legend2, unit1, unit2 ({prefix, suffix, digits}), type2 (line / bar),
        // right, color1, color2, key2 (the 2つ目の値's color key), compact ([[10000, "万"], …])}
        var combo = {!! $chart_combo !!};
        // the 値's bars' colors as the sort left them (その他 in its gray)
        var pointColors = {!! $chart_point_colors !!};
        // click-to-filter: {column, values[], selected[], highlight} (ChartItem::clickFilter), else null
        var click = {!! $chart_click !!};
        var pickable = !!click;
        // 明細: {url, points[i]} (ChartItem::detailPayload) — a double click on a bar or on a point of
        // the 2つ目の値 lists the same rows, those of the X item
        var detail = {!! $chart_detail ?? 'null' !!};
        var drillable = !!(detail && detail.points);
        // the その他 point of 表示件数 (ChartItem::restTip), else null
        var rest = {!! $chart_rest ?? 'null' !!};
        // the target line of the 値 (ChartItem::targetPayload), on the left axis, else null
        var target = {!! $chart_target ?? 'null' !!};
        var below = {};
        (target ? target.below : []).forEach(function (i) { below[i] = true; });
        var axisXName = @json($chart_axisx);
        var right = !!combo.right, line2 = combo.type2 !== 'bar';

        // a value in its measure's units (¥1,200 / 394件), with the decimals the view rounds it to
        function fmtOf(unit) {
            return function (v) {
                if (v == null || isNaN(v)) { return ''; }
                return unit.prefix + Number(v).toLocaleString(undefined, { maximumFractionDigits: unit.digits == null ? 10 : unit.digits }) + unit.suffix;
            };
        }
        var fmt1 = fmtOf(combo.unit1), fmt2 = fmtOf(combo.unit2);
        // an axis' ticks counted in a unit of their size once its values reach ten of it (5,000万,
        // as Excel's display units), its measure's unit around them
        function tickOf(unit, list) {
            var top = list.reduce(function (m, v) { return v == null ? m : Math.max(m, Math.abs(v)); }, 0), step = null;
            (combo.compact || []).forEach(function (c) { if (!step && top >= c[0] * 10) { step = c; } });
            return function (v) {
                return unit.prefix + Number(step ? v / step[0] : v).toLocaleString(undefined, { maximumFractionDigits: step ? 1 : 10 }) + (step ? step[1] : '') + unit.suffix;
            };
        }

        // the left axis: the 値's — and the 2つ目の値's when it has none of its own (the legend names
        // them then); the right one: the 2つ目の値's, a line's spanning its values (it is read by its
        // shape, as Excel scales it), bars' from 0
        var leftValues = (right ? values : values.concat(values2)).concat(target ? [target.value] : []);
        var yAxes = [{ type: 'value', name: right ? combo.name1 : '', nameTextStyle: { align: 'left' }, axisLabel: { formatter: tickOf(combo.unit1, leftValues), hideOverlap: true } }];
        if (right) {
            yAxes.push({ type: 'value', name: combo.name2, position: 'right', nameTextStyle: { align: 'right' }, scale: line2, splitLine: { show: false }, axisLabel: { formatter: tickOf(combo.unit2, values2), hideOverlap: true } });
        }

        // the target line of the 値, named inside the plot at its right end — right of the plot is the right axis
        var targetLine = null;
        if (target) {
            ExmentDashboard.chartTargetAxis(yAxes[0], target.value);
            targetLine = ExmentDashboard.chartTargetLine(target, false);
            targetLine.label.position = 'insideEndTop';
            targetLine.label.formatter = function () { return String(target.name) + ' ' + String(target.text); };
        }

        // Cross-highlight: the bar's pick on this chart's own X item — the picked points solid, the
        // rest faded, on both values. `picked` is value => true; nothing picked = nothing faded.
        var picked = {}, pickedN = 0;
        function setPicked(list) {
            picked = {};
            pickedN = 0;
            (list || []).forEach(function (v) { v = String(v); if (!picked[v]) { picked[v] = true; pickedN++; } });
        }
        setPicked(pickable && click.highlight ? click.selected : null);
        var faded = function (i) { return pickedN > 0 && !picked[String(click.values[i])]; };
        // the box menu's 値ラベル: the bars' values; a line of the 2つ目の値 is always labelled, as Excel's sample
        var state = { labels: {{ $chart_labels_on ? 'true' : 'false' }} };
        var labelOf = function (fmt, show) {
            return { show: show, position: 'top', fontSize: 11, color: '#2b2b3a', formatter: function (p) { return fmt(p.value); } };
        };

        function series() {
            var bars = {
                name: combo.legend1, type: 'bar', yAxisIndex: 0, barMaxWidth: 40, itemStyle: { color: combo.color1 },
                data: values.map(function (v, i) {
                    // a bar under the target in the line's color
                    return { value: v, itemStyle: { color: below[i] ? target.color : (pointColors[i] || combo.color1), opacity: faded(i) ? 0.18 : 1 } };
                }),
                label: labelOf(fmt1, state.labels), labelLayout: { hideOverlap: true }
            };
            if (targetLine) { bars.markLine = targetLine; }
            var second = {
                name: combo.legend2, type: line2 ? 'line' : 'bar', yAxisIndex: right ? 1 : 0, z: 3, itemStyle: { color: combo.color2 },
                data: values2.map(function (v, i) { return { value: v, itemStyle: { opacity: faded(i) ? 0.18 : 1 } }; }),
                label: labelOf(fmt2, line2 || state.labels), labelLayout: { hideOverlap: true }
            };
            if (line2) {
                $.extend(second, { symbol: 'emptyCircle', symbolSize: 8, lineStyle: { width: 2.5 } });
            } else {
                second.barMaxWidth = 40;
            }
            return [bars, second];
        }

        // the tooltip of an X item: both values in their units, the gap to the target, その他's
        // points, the way to the 明細
        var hint = drillable ? ExmentDashboard.detailHint() : null;
        function tip(ps) {
            ps = Array.isArray(ps) ? ps : [ps];
            if (!ps.length) { return ''; }
            var i = ps[0].dataIndex, lines = [escHtml(labels[i])];
            ps.forEach(function (p) {
                var second = p.seriesIndex === 1;
                lines.push(p.marker + escHtml(second ? combo.name2 : combo.name1) + ': ' + (second ? fmt2(values2[i]) : fmt1(values[i])));
            });
            var text = lines.join('<br>');
            if (target) { text += '<br>' + escHtml(ExmentDashboard.chartTargetTip(target, values[i], fmt1)); }
            if (rest && i === rest.index) { text += ExmentDashboard.restTip(rest); }
            if (hint && detail.points[i]) { text += '<br><span style="font-size:11px;color:#8a8a8a;">' + escHtml(hint) + '</span>'; }
            return text;
        }

        var option = {
            color: [combo.color1, combo.color2],
            legend: { top: 0, type: 'scroll', data: [combo.legend1, combo.legend2] },
            // kept inside the chart: その他's list makes it tall, and the box cuts what leaves it
            tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, confine: true, formatter: tip },
            grid: { left: '3%', right: '3%', bottom: 30, top: 58, containLabel: true },
            xAxis: { type: 'category', data: labels, name: axisXName, nameLocation: 'middle', nameGap: 26 },
            yAxis: yAxes,
            series: series()
        };

        // dispose the previous instance of THIS box (a reload replaces the body)
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

        if (!pickable && !drillable) {
            chart.on('mousemove', function (p) {
                if (p.componentType === 'series') { chart.getZr().setCursorStyle('default'); }
            });
        }
        // a click picks the X item on the filter bar, a double click lists its rows (明細) — on a
        // bar or on a point of the 2つ目の値 alike
        if (pickable || drillable) {
            chart.on('click', function (p) {
                if (p.componentType !== 'series' || typeof p.dataIndex !== 'number') { return; }
                var i = p.dataIndex, ne = p.event && p.event.event, toggle = !!(ne && (ne.ctrlKey || ne.metaKey));
                ExmentDashboard.pointClick('{{ $suuid }}', 'p' + i,
                    (pickable && i < click.values.length) ? function () { ExmentDashboard.pick(click.column, click.values[i], toggle); } : null,
                    (drillable && detail.points[i]) ? function () { ExmentDashboard.openDetail('{{ $suuid }}', detail.url, detail.points[i]); } : null);
            });
        }

        @if(!empty($chart_color_edit))
        // paint a color (dashboard editors): right-click the bars or the 2つ目の値, each a series of one color
        chart.on('contextmenu', function (p) {
            if (p.componentType !== 'series') { return; }
            var ne = p.event && p.event.event;
            if (ne && ne.preventDefault) { ne.preventDefault(); }
            ExmentDashboard.colorMenu('{{ $suuid }}', p.seriesIndex === 1
                ? { kind: 'series', key: combo.key2, color: combo.color2 }
                : { kind: 'series', key: '', color: combo.color1 }, ne);
        });
        @endif

        function render() {
            if (chart.isDisposed && chart.isDisposed()) { return; }
            chart.setOption({ series: series() });
        }
        var resize = function () { if (!(chart.isDisposed && chart.isDisposed())) { chart.resize(); } };
        $(window).off('resize.echart_{{ $suuid }}').on('resize.echart_{{ $suuid }}', resize);
        $('[data-suuid="{{ $suuid }}"]').off('exment:dashboard_loaded.echart').on('exment:dashboard_loaded.echart', function () { setTimeout(resize, 50); });

        // Per-box hooks for dashboard.js: `highlight` repaints the bar's pick on the chart's own X
        // item, `display` the menu's 値ラベル, `data` the two values of each point (the CSV, the type
        // picker's preview)
        window.ExmentCharts = window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = {
            self: (pickable && click.highlight) ? click.column : null,
            highlight: function (list) { setPicked(list); render(); },
            display: function (opts) { $.extend(state, opts || {}); render(); },
            data: function () {
                return { axisx: axisXName, axisy: combo.name1, labels: labels, values: values, percent: false,
                    axisy2: combo.name2, values2: values2, second: { type: combo.type2, right: right } };
            }
        };
    });
</script>
