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
        var cartesian = ['mbar', 'sbar', 'sbar100', 'shbar100', 'mline', 'sarea'].indexOf(type) >= 0;
        var isLine = (type === 'mline' || type === 'sarea');
        // 100% stacked: matrix holds each cell's share of its X item (ChartShowAs::PERCENT_OF_ROW),
        // `values` the numbers they are shares of, for the tooltip and the CSV; the horizontal one
        // lists the X items top-down
        var fullStack = (type === 'sbar100' || type === 'shbar100');
        var horizontal = (type === 'shbar100');
        var values = {!! $chart_values ?? 'null' !!};
        var fullTexts = @json($chart_full_texts ?? null);
        var stacked = (type === 'sbar' || type === 'sarea' || fullStack);
        // the series the legend shows (name => false when hidden; null: all of them) and, on a
        // 100% stacked chart, the server's shares over every series — `matrix` is them rescaled
        // to the series shown (reshare)
        var shown = null;
        var shares = matrix;
        var esc =function (t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
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
        // 明細: a double click on a cell lists the rows behind it — {url, x, series: the view column
        // ids, x_keys[xi] / series_keys[si]: their values} (ChartItem::detailPayload), else null.
        // A bar / line point and a heatmap or treemap cell are a category of a series, a treemap's
        // frame a series, a boxplot's box a category over every series; the sunburst zooms on a click
        var detail = {!! $chart_detail ?? 'null' !!};
        var drillable = !!(detail && detail.x_keys) && (cartesian || ['heatmap', 'treemap', 'boxplot'].indexOf(type) >= 0);
        // the その他 category of 表示件数 (ChartItem::restTip): {index, count, items, more}, else null;
        // its cells' 明細 are the rows of the categories left out (detail.rest: its index, when it has one)
        var rest = {!! $chart_rest ?? 'null' !!};
        function detailKeys(xi, si) {
            var keys = {};
            if (rest && xi === rest.index) {
                if (detail.rest !== xi) { return null; }
                keys.rest = 1;
            } else if (xi != null) { if (xi >= detail.x_keys.length) { return null; } keys[detail.x] = detail.x_keys[xi]; }
            if (si != null) { if (si >= detail.series_keys.length) { return null; } keys[detail.series] = detail.series_keys[si]; }
            return keys;
        }
        var showLegend = {{ $chart_legend ? 'true' : 'false' }};
        var axisXName = @json($chart_axisx);
        var axisYName = @json($chart_axisy);
        // switched by the box menu's 集計方法, a tooltip names what its values are (最小値 ： 点数); null otherwise
        var valueName = @json($chart_value_name ?? null);
        var legend = { show: showLegend, type: 'scroll', top: 0, data: seriesNames };
        var heatData = null; // heatmap cells [x, s, value] (+ class, colored by thresholds)
        // the box's color thresholds (色分けのしきい値, ChartThresholds::payload): the legend's
        // pieces {value: the class of their cells, rule: the rule's index (-1: the cells meeting
        // none), color, label} and each cell's class; null: the continuous scale
        var thresholds = {!! $chart_thresholds ?? 'null' !!};
        var colorByRules = !!thresholds && type === 'heatmap';
        var hiddenClasses = {}; // class => true: hidden from the legend
        var HEAT_COLORS = ['#e0ffff', '#5b8ff9', '#1d39c4']; // the continuous scale, low to high
        var heatRange = [0, 1]; // its ends (scaleOf)
        // the values are percents (the box menu's 表示方法: 総計に対する比率 / 差分の比率), 1 decimal
        var percent = {{ !empty($chart_percent) ? 'true' : 'false' }};
        // a cell no row fell into is null for a MIN / MAX measure (ChartPivot::emptyCell): no
        // value to show, place in a heatmap or count into a boxplot's spread
        var plain = function (n) { return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 10 }); };
        var fmt = percent
            ? function (n) { return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }
            : plain;
        // a stack of calculated values (表示方法 on the stacked bar) is labelled with its total on top
        var stackTotal = {{ !empty($chart_stack_total) ? 'true' : 'false' }};
        // the target line (目標線, ChartItem::targetPayload): {value, name, text, diff, color}, else
        // null — across the grouped / stacked bars and lines, a stack's total read against it
        var target = {!! $chart_target ?? 'null' !!};

        function hierarchy() {
            return seriesNames.map(function (name, i) {
                // si / xi: the series and the category a tile stands for (a click names its 明細)
                return { name: String(name), si: i, children: xCategories.map(function (xc, xi) { return { name: String(xc), si: i, xi: xi, value: (matrix[i] && matrix[i][xi]) || 0 }; }) };
            });
        }
        function quantile(sorted, q) {
            var pos = (sorted.length - 1) * q, base = Math.floor(pos), rest = pos - base;
            return (sorted[base + 1] !== undefined) ? sorted[base] + rest * (sorted[base + 1] - sorted[base]) : sorted[base];
        }

        var option;
        switch (type) {
            case 'heatmap': {
                heatData = [];
                for (var s = 0; s < matrix.length; s++) {
                    for (var x = 0; x < matrix[s].length; x++) {
                        if (matrix[s][x] == null) { continue; }
                        heatData.push(colorByRules ? [x, s, matrix[s][x], classOf(s, x)] : [x, s, matrix[s][x]]);
                    }
                }
                heatRange = scaleOf(heatData);
                option = {
                    tooltip: { position: 'top' },
                    grid: { left: '3%', right: '7%', bottom: 30, top: '6%', containLabel: true },
                    xAxis: { type: 'category', data: xCategories, name: axisXName, nameLocation: 'middle', nameGap: 26, splitArea: { show: true } },
                    yAxis: { type: 'category', data: seriesNames, splitArea: { show: true } },
                    visualMap: { min: heatRange[0], max: heatRange[1], calculable: true, orient: 'vertical', right: 0, top: 'center', inRange: { color: HEAT_COLORS } },
                    series: [{ name: axisYName, type: 'heatmap', data: heatData.map(heatItem), label: { show: true }, emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,0.3)' } } }]
                };
                if (colorByRules) { colorTheCells(); }
                // a heatmap of many rows is as tall as they need (ChartItem): the box grows to it,
                // no scrollbar of its own
                if ({{ (int) $chart_height }} > 300) {
                    var inner = el.closest('.box-body-inner-body');
                    if (inner) { inner.style.maxHeight = 'none'; }
                }
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
            default: { // mbar / sbar / sbar100 / shbar100 / mline / sarea
                var seriesType = isLine ? 'line' : 'bar';
                var series = seriesNames.map(function (name, i) {
                    var s = { name: String(name), type: seriesType, data: cellsOf(i) };
                    if (stacked) { s.stack = 'total'; }
                    if (isLine) { s.smooth = true; s.lineStyle = { width: 2 }; }
                    if (type === 'sarea') { s.areaStyle = { opacity: 0.3 }; }
                    if (seriesType === 'bar') { s.barMaxWidth = 36; }
                    return s;
                });
                var categoryAxis = { type: 'category', data: xCategories, name: axisXName, nameLocation: 'middle', nameGap: 26, boundaryGap: seriesType === 'bar' };
                var valueAxis = { type: 'value', name: axisYName, nameTextStyle: { align: 'left' } };
                if (percent) { valueAxis.axisLabel = { formatter: '{value}%' }; }
                if (fullStack) {
                    // every bar is 100%: from 0, or from -100% when a share is negative (drawn below
                    // 0 by its size, as Excel's 100% stacked chart)
                    valueAxis.min = hasNegative() ? -100 : 0;
                    valueAxis.max = 100;
                }
                if (horizontal) {
                    // as the single-series horizontal bar: the first X item on top (inverse), the
                    // category axis's name above it, the value axis's under its ticks; a long item
                    // name is cut (the tooltip gives it whole)
                    categoryAxis = { type: 'category', data: xCategories, name: axisXName, inverse: true, nameLocation: 'start', nameTextStyle: { align: 'left' }, axisLabel: { width: categoryLabelWidth(), overflow: 'truncate' } };
                    valueAxis.nameLocation = 'middle';
                    valueAxis.nameGap = 26;
                    delete valueAxis.nameTextStyle;
                    valueAxis.axisLabel = $.extend({ hideOverlap: true }, valueAxis.axisLabel);
                }
                option = {
                    color: colors,
                    tooltip: { trigger: 'axis', axisPointer: { type: seriesType === 'bar' ? 'shadow' : 'line' } },
                    legend: legend,
                    // px, not %: the legend row and the Y-axis name above the plot need fixed room, or they overlap on a short box;
                    // the Y name runs right from the axis line (centred on it, a long one is cut by the box's left edge)
                    grid: { left: '3%', right: '5%', bottom: 30, top: showLegend ? 64 : 36, containLabel: true },
                    xAxis: horizontal ? valueAxis : categoryAxis,
                    yAxis: horizontal ? categoryAxis : valueAxis,
                    series: series
                };
                if (stackTotal) { series.push(stackTotalSeries()); }
            }
        }
        if (fullStack) { tellTheShares(); } else if (valueName) { nameTheValues(); }
        if (target && cartesian && !fullStack) { drawTheTarget(); }
        if (rest) { listTheRest(); }
        if (drillable) { hintTheDetail(); }

        // The heatmap colored by the thresholds, as by Excel's highlight rules: a cell in the
        // color of the first rule it meets (its class, the cell's 4th value), one meeting none
        // plain; every label bold, in ink that reads on its cell. The legend under the plot
        // lists the rules along the scale (60 未満 / 60〜75 / 75 以上); a click on one hides its
        // cells (out of the visualMap's range: no color, label, tooltip or click), another shows them.
        function colorTheCells() {
            option.visualMap = {
                type: 'piecewise', dimension: 3, orient: 'horizontal', left: 'center', bottom: 0, itemGap: 18,
                pieces: thresholds.pieces.map(function (p) { return { value: p.value, color: p.color, label: p.label }; }),
                outOfRange: { color: 'rgba(0,0,0,0)' }
            };
            option.grid.right = '3%';
            option.grid.bottom = 56;
            var series = option.series[0];
            series.data = heatData.map(heatItem);
            series.itemStyle = { borderColor: '#e6e6e6', borderWidth: 1 };
            series.label = { show: true, fontWeight: 'bold', formatter: function (p) { return hiddenClasses[p.value[3]] ? '' : fmt(p.value[2]); } };
        }
        function classOf(s, x) {
            var row = thresholds.classes[s];
            return row ? row[x] : null;
        }
        // The continuous scale: from the lowest value drawn to the highest, so the cells tell
        // apart (from 0, values of 45 to 78 came out one dark blue); a single value from 0 to it.
        function scaleOf(cells) {
            if (!cells.length) { return [0, 1]; }
            var lo = Infinity, hi = -Infinity;
            cells.forEach(function (d) { lo = Math.min(lo, d[2]); hi = Math.max(hi, d[2]); });
            if (lo === hi) { lo = Math.min(0, lo); }
            return [lo, hi === lo ? lo + 1 : hi];
        }
        // the color the continuous scale gives a value (ECharts mixes the stops' RGB linearly)
        function scaleColor(v) {
            var t = Math.max(0, Math.min(1, (v - heatRange[0]) / (heatRange[1] - heatRange[0])));
            var at = t * (HEAT_COLORS.length - 1), i = Math.min(Math.floor(at), HEAT_COLORS.length - 2), f = at - i;
            var a = parseInt(HEAT_COLORS[i].slice(1), 16), b = parseInt(HEAT_COLORS[i + 1].slice(1), 16);
            var mix = function (shift) { return Math.round((a >> shift & 255) + ((b >> shift & 255) - (a >> shift & 255)) * f); };
            return '#' + ((1 << 24) + (mix(16) << 16) + (mix(8) << 8) + mix(0)).toString(16).slice(1);
        }
        // a cell: its number in ink that reads on its color — the scale's, or its rule's (a cell
        // meeting no rule: dark); a cell hidden from the thresholds' legend has no tooltip
        function heatItem(d) {
            if (!colorByRules) { return { value: d, label: { color: inkOn(scaleColor(d[2])) } }; }
            var piece = null;
            thresholds.pieces.forEach(function (p) { if (p.value === d[3]) { piece = p; } });
            var item = { value: d, label: { color: (piece && piece.rule >= 0) ? inkOn(piece.color) : '#2b2b3a' } };
            if (hiddenClasses[d[3]]) { item.tooltip = { show: false }; }
            return item;
        }

        // Whether a cell of a series shown is below 0 (the 100% stacked chart's axis then reaches -100%).
        function hasNegative() {
            return (values || []).some(function (row, i) { return isShown(i) && (row || []).some(function (v) { return v != null && Number(v) < 0; }); });
        }
        function isShown(i) { return !shown || shown[String(seriesNames[i])] !== false; }
        // A series hidden in the legend leaves the 100% stacked chart, as it does Excel's: each
        // bar 100% of the series still shown — the server's shares rescaled to the sum of the
        // shown ones' sizes (the shares are of Σ|value|, so this is value / Σ|shown value|). An
        // item none of them falls into keeps its empty bar; everything shown, the shares as given.
        function reshare() {
            if (seriesNames.every(function (name, i) { return isShown(i); })) { matrix = shares; return; }
            var sums = xCategories.map(function (xc, xi) {
                return shares.reduce(function (sum, row, i) { var v = row ? row[xi] : null; return (isShown(i) && v != null) ? sum + Math.abs(v) : sum; }, 0);
            });
            matrix = shares.map(function (row, i) {
                return (row || []).map(function (v, xi) { return (v == null || !isShown(i) || !sums[xi]) ? v : v / sums[xi] * 100; });
            });
        }
        // the room for a horizontal bar's item names: about a third of the box, so a long name
        // leaves the bars their room
        function categoryLabelWidth() {
            return Math.max(60, Math.round((el.clientWidth || 300) * 0.3));
        }

        // The tooltip of a 100% stacked chart, as an Excel / survey report gives it: the X item with
        // the total of its bar — 開発部（計 62件） — and each series' share with the number it is
        // a share of — 満足: 19.4%（12件）; a series no row fell into is left out. Switched by the
        // 集計方法, the measure's name under the item. Labels escaped (injected as innerHTML).
        function tellTheShares() {
            option.tooltip.formatter = function (ps) {
                ps = [].concat(ps).filter(function (p) { return p.seriesIndex < seriesNames.length; });
                if (!ps.length) { return ''; }
                var xi = ps[0].dataIndex, total = 0;
                (values || []).forEach(function (row, i) { var v = row ? row[xi] : null; if (isShown(i) && v != null && !isNaN(v)) { total += Number(v); } });
                var text = function (template, n) { return String(template || '').replace('{n}', plain(n)); };
                return esc(ps[0].name) + esc(text(fullTexts && fullTexts.total, total)) + (valueName ? '<br>' + esc(valueName) : '')
                    + ps.map(function (p) {
                        var raw = (values && values[p.seriesIndex]) ? values[p.seriesIndex][xi] : null;
                        if (raw == null) { return ''; }
                        return '<br>' + p.marker + esc(p.seriesName) + ': <b>' + esc(fmt(p.value) || '-') + '</b>' + esc(text(fullTexts && fullTexts.value, raw));
                    }).join('');
            };
        }

        // The total of each stack, above it: an invisible point at the stack's top — the end of
        // its positive part (0 when it has none: ECharts piles negatives down from 0) — whose
        // label prints the total. Not stacked itself, so a negative last series cannot drag the
        // label into the column. It is no series of the data — after seriesNames, out of the
        // legend (legend.data), the tooltip and the clicks.
        function stackTotalSeries() {
            var totals = [], tops = [];
            xCategories.forEach(function (xc, xi) {
                var t = null, top = 0;
                matrix.forEach(function (row) {
                    var v = row ? row[xi] : null;
                    if (v != null) { t = (t || 0) + v; if (v > 0) { top += v; } }
                });
                totals.push(t);
                tops.push(t == null ? null : top);
            });
            return {
                name: '', type: 'scatter', silent: true, symbolSize: 1, itemStyle: { color: 'transparent' }, tooltip: { show: false },
                data: tops,
                label: { show: true, position: 'top', distance: 4, fontSize: 11, color: '#2b2b3a', formatter: function (p) { return fmt(totals[p.dataIndex]); } }
            };
        }

        // The switched measure named in the tooltips (the heatmap's series is named after it
        // already): under the category of an axis tooltip, before the value of a tile, as the
        // series of a box. A function formatter is injected as innerHTML: labels escaped.
        function nameTheValues() {
            if (cartesian) {
                option.tooltip.formatter = function (ps) {
                    // the series of the data only (not the stack totals' one)
                    ps = [].concat(ps).filter(function (p) { return p.seriesIndex < seriesNames.length; });
                    return esc(ps.length ? ps[0].name : '') + '<br>' + esc(valueName) + ps.map(function (p) { return '<br>' + p.marker + esc(p.seriesName) + ': ' + fmt(p.value); }).join('');
                };
            } else if (type === 'treemap' || type === 'sunburst') {
                option.tooltip.formatter = function (p) { return esc(p.name) + '<br>' + esc(valueName) + ': ' + fmt(p.value); };
            } else if (type === 'boxplot') {
                option.series[0].name = valueName;
            }
        }

        // The target line, as Excel's combo chart draws a column of the target: dashed across the
        // plot at the target, named right of it in the room the grid leaves there, on a series of
        // its own — no data, out of the legend (legend.data), the tooltip and the clicks — so that
        // hiding a series keeps it; the value axis reaches it; the tooltip names it.
        function drawTheTarget() {
            ExmentDashboard.chartTargetAxis(option.yAxis, target.value);
            option.series.push({ name: '', type: 'line', data: [], silent: true, tooltip: { show: false }, markLine: ExmentDashboard.chartTargetLine(target, false) });
            option.grid.right = Math.max(Math.round((el.clientWidth || 0) * 0.05), ExmentDashboard.chartTargetRoom(target));
            var base = option.tooltip.formatter;
            option.tooltip.formatter = function (p) {
                return (typeof base === 'function' ? base(p) : plainTip(p)) + '<br>' + esc(ExmentDashboard.chartTargetTip(target, null, fmt));
            };
        }

        // A tooltip as the type's own tooltip option writes it, for one without a formatter.
        function plainTip(p) {
            if (Array.isArray(p)) {
                p = p.filter(function (q) { return q.seriesIndex < seriesNames.length; });
                return esc(p.length ? p[0].name : '') + p.map(function (q) { return '<br>' + q.marker + esc(q.seriesName) + ': ' + fmt(q.value); }).join('');
            }
            if (type === 'heatmap' && Array.isArray(p.value)) {
                return p.marker + esc(xCategories[p.value[0]]) + ' / ' + esc(seriesNames[p.value[1]]) + ': ' + fmt(p.value[2]);
            }
            if (type === 'boxplot') {
                return esc(p.name) + (p.seriesName ? '<br>' + esc(p.seriesName) : '') + (Array.isArray(p.value) ? '<br>' + p.value.slice(1).map(fmt).join(' / ') : '');
            }
            return esc(p.name) + ': ' + fmt(p.value);
        }

        // The tooltip of the その他 category lists the categories it groups (with their total
        // over the series), after what it says now.
        function listTheRest() {
            if (!option.tooltip) { return; }
            // its list makes it tall: kept inside the chart, or the box cuts it
            option.tooltip.confine = true;
            var base = option.tooltip.formatter;
            option.tooltip.formatter = function (p) {
                var text = typeof base === 'function' ? base(p) : plainTip(p);
                var q = Array.isArray(p) ? p[0] : p;
                // a bar / line point's category, a tile's (a series' frame has none)
                var xi = !q ? null : (cartesian ? q.dataIndex : (q.data && typeof q.data.xi === 'number' ? q.data.xi : null));
                return xi === rest.index ? text + ExmentDashboard.restTip(rest) : text;
            };
        }

        // The tooltip of a point with a 明細 ends with the way to it (after what it says now: the
        // formatter of nameTheValues, else the one the type's tooltip option gives)
        function hintTheDetail() {
            var hint = ExmentDashboard.detailHint();
            if (!hint || !option.tooltip) { return; }
            var base = option.tooltip.formatter;
            option.tooltip.formatter = function (p) {
                return (typeof base === 'function' ? base(p) : plainTip(p)) + '<br><span style="font-size:11px;color:#8a8a8a;">' + esc(hint) + '</span>';
            };
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
            return heatData.map(function (d) {
                var item = heatItem(d);
                item.itemStyle = { opacity: faded(d[0]) ? 0.18 : 1 };
                return item;
            });
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
        if (!pickable && !drillable && type !== 'sunburst') {
            chart.on('mousemove', function (p) {
                if (p.componentType === 'series') { chart.getZr().setCursorStyle('default'); }
            });
        }

        // click-to-filter on the X category (cartesian series: dataIndex; heatmap: value[0]); a
        // double click lists the cell's rows (明細: the series as well — seriesIndex, value[1],
        // a treemap tile's si / xi; a boxplot's box is its category over every series)
        if (pickable || drillable) {
            chart.on('click', function (p) {
                var xi = null, si = null;
                if (p.componentSubType === 'heatmap' && Array.isArray(p.value)) {
                    if (colorByRules && hiddenClasses[p.value[3]]) { return; } // hidden from the legend
                    xi = p.value[0];
                    si = p.value[1];
                } else if (p.componentSubType === 'bar' || p.componentSubType === 'line') {
                    if (p.seriesIndex >= seriesNames.length) { return; } // the stack totals
                    xi = p.dataIndex;
                    si = p.seriesIndex;
                } else if (p.componentSubType === 'treemap' && p.data && typeof p.data.si === 'number') {
                    si = p.data.si;
                    xi = typeof p.data.xi === 'number' ? p.data.xi : null;
                } else if (p.componentSubType === 'boxplot') {
                    xi = p.dataIndex;
                } else {
                    return;
                }
                var ne = p.event && p.event.event, toggle = !!(ne && (ne.ctrlKey || ne.metaKey));
                var keys = drillable ? detailKeys(xi, si) : null;
                ExmentDashboard.pointClick('{{ $suuid }}', 'c' + si + ':' + xi,
                    (pickable && typeof xi === 'number' && xi < click.values.length) ? function () { ExmentDashboard.pick(click.column, click.values[xi], toggle); } : null,
                    keys ? function () { ExmentDashboard.openDetail('{{ $suuid }}', detail.url, keys); } : null);
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

        var resize = function () {
            chart.resize();
            // a 100% stacked chart's room for the item names and for each share's label follow the size
            if (fullStack && !(chart.isDisposed && chart.isDisposed())) {
                if (horizontal) { chart.setOption({ yAxis: { axisLabel: { width: categoryLabelWidth() } } }); }
                if (display.labels) { render(); }
            }
        };
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
                var inside = stacked;
                var fits = fullStack ? shareFits() : null;
                chart.setOption({ series: seriesNames.map(function (name, i) {
                    var s = { label: { show: display.labels, position: inside ? 'inside' : 'top', fontSize: 10, color: '#2b2b3a', formatter: function (p) { return fmt(p.value); } }, labelLayout: { hideOverlap: true } };
                    if (fullStack) {
                        // a share's label in ink that reads on its series' color; none on a share too thin for it
                        s.label.color = inkOn(colors[i % colors.length]);
                        s.label.formatter = function (p) { var t = fmt(p.value); return fits(p.value, t) ? t : ''; };
                    }
                    if (pickable) { s.data = seriesData(i); } else if (fullStack) { s.data = cellsOf(i); }
                    return s;
                }) });
            } else if (type === 'heatmap' && (pickable || colorByRules)) {
                var heatOption = { series: [{ data: heatSeriesData() }] };
                if (colorByRules) {
                    // new data resets the legend's pieces to all shown: keep the ones hidden hidden
                    var selected = {};
                    thresholds.pieces.forEach(function (p, i) { selected[i] = !hiddenClasses[p.value]; });
                    heatOption.visualMap = { selected: selected };
                }
                chart.setOption(heatOption);
            }
        }
        // Whether a share's label fits in its share of the bar, as drawn now: its length along the
        // value axis (the pixels of 1% × the share) against the label's width on a horizontal
        // bar, its height on a column — whose width the label must fit too.
        function shareFits() {
            var perPercent = 0, band = 0;
            try {
                var axis = horizontal ? { xAxisIndex: 0 } : { yAxisIndex: 0 };
                perPercent = Math.abs(chart.convertToPixel(axis, 100) - chart.convertToPixel(axis, 0)) / 100;
                if (!horizontal && xCategories.length > 1) {
                    band = Math.abs(chart.convertToPixel({ xAxisIndex: 0 }, 1) - chart.convertToPixel({ xAxisIndex: 0 }, 0));
                }
            } catch (e) {}
            return function (value, text) {
                if (!text) { return false; }
                if (!perPercent) { return true; }
                var length = Math.abs(Number(value)) * perPercent, width = textWidth(text, 10);
                if (horizontal) { return length >= width + 6; }
                // a column is as wide as 80% of its band (ECharts' barCategoryGap), 36px at most
                return length >= 13 && (!band || width <= Math.min(36, band * 0.8) + 2);
            };
        }
        var ctx2d = document.createElement('canvas').getContext('2d');
        function textWidth(s, size) { ctx2d.font = size + 'px sans-serif'; return ctx2d.measureText(String(s)).width; }
        // white on a dark color, dark on a light one (a color that is no #rrggbb: dark)
        function inkOn(color) {
            var m = /^#?([0-9a-f]{6})$/i.exec(String(color || ''));
            if (!m) { return '#2b2b3a'; }
            var n = parseInt(m[1], 16);
            return (0.299 * (n >> 16 & 255) + 0.587 * (n >> 8 & 255) + 0.114 * (n & 255)) < 140 ? '#fff' : '#2b2b3a';
        }

        if (display.labels || pickedN > 0) { render(); }
        // a 100% stacked chart: the legend's series shown or hidden re-share the bars (reshare)
        if (fullStack) {
            ['legendselectchanged', 'legendselectall', 'legendinverseselect'].forEach(function (name) { chart.on(name, onLegend); });
        }
        // the thresholds' legend: the classes of the pieces switched off are hidden
        if (colorByRules) {
            chart.on('datarangeselected', function (e) {
                hiddenClasses = {};
                $.each((e && e.selected) || {}, function (i, on) {
                    var piece = thresholds.pieces[i];
                    if (piece && !on) { hiddenClasses[piece.value] = true; }
                });
                render();
            });
        }
        function onLegend(e) {
            shown = (e && e.selected) || null;
            reshare();
            var axis = {};
            axis[horizontal ? 'xAxis' : 'yAxis'] = { min: hasNegative() ? -100 : 0 };
            chart.setOption(axis);
            render();
        }
        window.ExmentCharts =window.ExmentCharts || {};
        window.ExmentCharts['{{ $suuid }}'] = {
            self: (pickable && click.highlight) ? click.column : null,
            highlight: function (list) { setPicked(list); render(); },
            display: function (opts) { $.extend(display, opts || {}); render(); },
            // a 100% stacked chart hands its own numbers, as Excel's chart is drawn from the cells
            // of the sheet: the CSV exports them, a preview of another type draws them
            // (the thresholds too: a preview of the heatmap colors its cells by them)
            data: function () { return { axisx: axisXName, axisy: axisYName, categories: xCategories, series: seriesNames, matrix: fullStack ? values : matrix, percent: fullStack ? false : percent, thresholds: thresholds }; }
        };
    });
</script>
