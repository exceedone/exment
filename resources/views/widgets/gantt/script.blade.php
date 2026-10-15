<script>
(function () {
    'use strict';
    var root = document.getElementById('{{ $chartId }}');
    if (!root || root.dataset.gtBound) { return; }
    root.dataset.gtBound = '1';

    var D = JSON.parse(document.getElementById('{{ $chartId }}-data').textContent);
    var L = @json($lang);

    var LEFT_W = 340;
    var ROW_H = 34;
    var DAY = 86400000;

    // The apostrophe is a hex reference on purpose: the admin's Pjax middleware
    // turns every decimal reference in a pjax response back into its character,
    // scripts included, and a decimal one would end this string early.
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#x27;' }[c];
        });
    }
    // parse "Y-m-d" in local time; new Date("Y-m-d") would be UTC and shift a day
    function parse(s) {
        if (!s) { return null; }
        var p = s.split('-');
        return new Date(+p[0], +p[1] - 1, +p[2]);
    }
    // Steps by calendar day: adding 24h would land on 23:00 of the day before
    // after a daylight saving change, and getDay() would name the wrong day.
    function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
    function fmtMD(d) { return (d.getMonth() + 1) + '/' + d.getDate(); }
    // Rough width of a 10px header label: wide (CJK) glyphs take about a full
    // em, digits and latin letters a little over half of one. Estimated rather
    // than measured, because the chart may be drawn while its tab is hidden.
    function labelW(s) {
        var w = 0;
        s = String(s || '');
        for (var i = 0; i < s.length; i++) { w += s.charCodeAt(i) > 0xff ? 10 : 6; }
        return w;
    }
    function fmtShort(s) { var d = parse(s); return d ? fmtMD(d) : ''; }

    var today = parse(D.today);

    // In an embedded tab the chart may load while hidden; wait until it has a
    // real width before measuring, or every bar would be squeezed into the
    // fallback width.
    function whenVisible(cb) {
        var tries = 0;
        (function poll() {
            var el = root.querySelector('.gt-scroll');
            if (el && el.clientWidth > 0) { cb(); return; }
            if (++tries > 600) { cb(); return; }
            window.requestAnimationFrame(poll);
        })();
    }

    whenVisible(function () {

    /* ------------------------------------------------ range ------------- */
    // A date typed with the wrong year would stretch the chart over decades,
    // every day of them drawn on every row. Dates more than about three years
    // from today do not widen it; their bars are cut off at its edge.
    var lo = addDays(today, -3 * 366), hi = addDays(today, 3 * 366);
    var min = null, max = null;
    function widen(s) {
        var d = parse(s);
        if (!d || !(d >= lo && d <= hi)) { return; }
        if (min === null || d < min) { min = d; }
        if (max === null || d > max) { max = d; }
    }
    (D.rows || []).forEach(function (r) { widen(r.start); widen(r.end); });
    (D.groups || []).forEach(function (g) { widen(g.due); });
    widen(D.today);
    if (min === null) { min = addDays(today, -14); }
    if (max === null) { max = addDays(today, 14); }
    min = addDays(min, -4);
    max = addDays(max, 4);
    // snap to Monday so the week ticks line up
    while (min.getDay() !== 1) { min = addDays(min, -1); }
    while (max.getDay() !== 0) { max = addDays(max, 1); }

    var days = Math.round((max - min) / DAY) + 1;
    var avail = Math.max(400, (root.querySelector('.gt-scroll').clientWidth || 1000) - LEFT_W - 20);
    var dw = Math.max(4, Math.min(24, Math.floor(avail / days)));
    var CANVAS_W = days * dw;

    function x(s) {
        var d = parse(s);
        if (!d) { return null; }
        return Math.round((d - min) / DAY) * dw;
    }

    /* ------------------------------------------------ legend ------------ */
    var legend = '';
    (D.legend || []).forEach(function (e) {
        legend += '<span><span class="sw" style="background:' + esc(e.color) + '"></span>' + esc(e.label) + '</span>';
    });
    legend += '<span><span class="today-line">│</span> ' + esc(L.today) + '</span>';
    if ((D.groups || []).some(function (g) { return g.due; })) {
        legend += '<span><span class="dia">◆</span> ' + esc(L.due) + '</span>';
    }
    var legendEl = root.querySelector('.gt-legend');
    if (legendEl) { legendEl.innerHTML = legend; }

    /* ------------------------------------------------ grid lines -------- */
    function gridHtml(withLabels) {
        var h = '';
        for (var i = 0; i < days; i++) {
            var d = addDays(min, i);
            var px = i * dw;
            if (d.getDay() === 1) { h += '<div class="gt-line" style="left:' + px + 'px"></div>'; }
            if (d.getDay() === 6) { h += '<div class="gt-we" style="left:' + px + 'px;width:' + (dw * 2) + 'px"></div>'; }
        }
        h += '<div class="gt-today" style="left:' + x(D.today) + 'px"></div>';
        if (withLabels) {
            var everyOther = (dw * 7) < 44;
            var wk = 0;
            // The today label shares the week row. A week label it would land
            // on is left out, rather than the two printed over each other.
            var todayAt = x(D.today);
            var todayX = todayAt === null ? null : todayAt + 4;
            var todayEnd = todayX === null ? null : todayX + labelW(L.today);
            for (var i2 = 0; i2 < days; i2++) {
                var d2 = addDays(min, i2);
                var px2 = i2 * dw;
                if (d2.getDate() === 1 || i2 === 0) {
                    h += '<div class="gt-mon" style="left:' + (px2 + 3) + 'px">' + d2.getFullYear() + '/' + (d2.getMonth() + 1) + '</div>';
                }
                if (d2.getDay() === 1) {
                    if (!everyOther || (wk % 2 === 0)) {
                        var wkX = px2 + 3;
                        var wkText = fmtMD(d2);
                        if (todayX === null || wkX >= todayEnd + 2 || wkX + labelW(wkText) + 2 <= todayX) {
                            h += '<div class="gt-wk" style="left:' + wkX + 'px">' + wkText + '</div>';
                        }
                    }
                    wk++;
                }
            }
            h += '<div class="gt-today-label" style="left:' + (x(D.today) + 4) + 'px">' + esc(L.today) + '</div>';
        }
        return h;
    }

    /* ------------------------------------------------ rows -------------- */
    function orderRows(list) {
        // parents first, each followed by its children, everything by start date
        var byStart = function (a, b) {
            if (!a.start && !b.start) { return String(a.id) < String(b.id) ? -1 : 1; }
            if (!a.start) { return 1; }
            if (!b.start) { return -1; }
            return a.start < b.start ? -1 : (a.start > b.start ? 1 : 0);
        };
        var ids = {};
        list.forEach(function (r) { ids[String(r.id)] = true; });
        var roots = list.filter(function (r) { return !r.parent || !ids[String(r.parent)]; }).sort(byStart);
        var out = [];
        roots.forEach(function (r) {
            out.push(r);
            list.filter(function (c) { return String(c.parent) === String(r.id); })
                .sort(byStart)
                .forEach(function (c) { c._child = true; out.push(c); });
        });
        // anything still missing (grandchildren...) keeps flat order at the end
        list.forEach(function (r) { if (out.indexOf(r) < 0) { out.push(r); } });
        return out;
    }

    function rowHtml(r) {
        var color = (D.colors && D.colors[r.color]) || '#3c8dbc';
        var overdue = !!(r.end && parse(r.end) < today && (r.progress === null || r.progress === undefined || r.progress < 100));
        var left = '<div class="gt-left" style="width:' + LEFT_W + 'px">';
        left += '<span class="gt-dot" style="background:' + esc(color) + '"></span>';
        left += '<span class="gt-label">' + (r._child ? '<span class="gt-indent">└ </span>' : '') +
            '<a href="' + esc(r.url) + '" title="' + esc(r.label) + '">' + esc(r.label) + '</a></span>';
        if (D.features.assignee) {
            var a = r.assignee && D.assignees ? D.assignees[r.assignee] : null;
            left += a ? '<span class="gt-av" style="background:' + esc(a.color) + '" title="' + esc(a.name) + '">' + esc(a.initial) + '</span>'
                      : '<span style="width:22px;flex:none;"></span>';
        }
        left += '<span class="gt-due' + (overdue ? ' gt-overdue' : '') + '">' + (r.end ? fmtShort(r.end) : '—') + '</span>';
        left += '</div>';

        var canvas = '<div class="gt-canvas" style="width:' + CANVAS_W + 'px">' + gridHtml(false);
        if (r.start && r.end) {
            // cut at the chart's edges: a date outside its range does not widen it
            var sx = Math.max(0, x(r.start)), ex = Math.min(CANVAS_W, x(r.end) + dw);
            if (ex > sx) {
                var tip = r.label + '\n' + r.start + ' ~ ' + r.end;
                if (r.progress !== null && r.progress !== undefined) { tip += '\n' + L.progress + ' ' + r.progress + '%'; }
                var a2 = r.assignee && D.assignees ? D.assignees[r.assignee] : null;
                if (a2) { tip += '\n' + a2.name; }
                canvas += '<div class="gt-bar' + (overdue ? ' gt-over' : '') + '" data-url="' + esc(r.url) + '" title="' + esc(tip) +
                    '" style="left:' + sx + 'px;width:' + Math.max(6, ex - sx) + 'px;background:' + esc(color) + '">' +
                    (r.progress ? '<i style="width:' + Math.max(0, Math.min(100, r.progress)) + '%"></i>' : '') + '</div>';
            }
        }
        canvas += '</div>';
        return '<div class="gt-row gt-item" style="height:' + ROW_H + 'px">' + left + canvas + '</div>';
    }

    var html = '';
    // header
    html += '<div class="gt-row gt-head"><div class="gt-left" style="width:' + LEFT_W + 'px">' +
        esc(D.group_label || '') + '</div>' +
        '<div class="gt-canvas" style="width:' + CANVAS_W + 'px">' + gridHtml(true) + '</div></div>';

    var hasGroups = (D.groups || []).length > 1 || ((D.groups || []).length === 1 && D.groups[0].label);
    var items = 0;
    (D.groups || []).forEach(function (g, gi) {
        var rows = (D.rows || []).filter(function (r) { return String(r.group || '') === String(g.key); });
        if (!rows.length) { return; }
        items += rows.length;
        if (hasGroups) {
            html += '<div class="gt-row gt-grp" data-grp="' + gi + '"><div class="gt-left" style="width:' + LEFT_W + 'px">' +
                '<span class="gt-caret fa fa-caret-down"></span>' +
                '<i class="fa fa-flag-checkered"></i><span class="gt-label">' + esc(g.label || '') + '</span>' +
                '<span class="gt-cnt">' + rows.length + '</span>' +
                (g.due ? '<span class="gt-due">' + fmtShort(g.due) + '</span>' : '') + '</div>' +
                '<div class="gt-canvas" style="width:' + CANVAS_W + 'px">' + gridHtml(false) +
                (g.due && x(g.due) >= 0 && x(g.due) < CANVAS_W ? '<div class="gt-dia" style="left:' + (x(g.due) + Math.round(dw / 2) - 5) + 'px" title="' + esc((g.label || '') + ' ' + L.due + ': ' + g.due) + '"></div>' : '') +
                '</div></div>';
        }
        orderRows(rows).forEach(function (r) {
            html += rowHtml(r).replace('class="gt-row gt-item"', 'class="gt-row gt-item gt-of-' + gi + '"');
        });
    });

    var inner = root.querySelector('.gt-inner');

    // Nothing was drawn. A bare date ruler over empty space reads as a chart
    // that failed to load, so say there is no data instead - and drop the
    // ruler with it, because no bar is left for it to line anything up
    // against. Counted rather than read off D.rows: a row whose group was
    // filtered out never reaches the canvas.
    if (!items) {
        if (legendEl) { legendEl.innerHTML = ''; }
        inner.style.width = '';
        inner.innerHTML = '<div class="gt-empty">' + esc(L.empty) + '</div>';
        return;
    }

    inner.style.width = (LEFT_W + CANVAS_W) + 'px';
    inner.innerHTML = html;

    /* ------------------------------------------------ events ------------ */
    inner.addEventListener('click', function (ev) {
        var bar = ev.target.closest('.gt-bar');
        if (bar) {
            var barUrl = bar.getAttribute('data-url');
            if (!(window.ppNavigate && window.ppNavigate(barUrl))) { window.location.href = barUrl; }
            return;
        }
        var grp = ev.target.closest('.gt-grp');
        if (grp) {
            var gi = grp.getAttribute('data-grp');
            var caret = grp.querySelector('.gt-caret');
            var hidden = caret.classList.toggle('fa-caret-right');
            caret.classList.toggle('fa-caret-down', !hidden);
            inner.querySelectorAll('.gt-of-' + gi).forEach(function (el) {
                el.classList.toggle('gt-hidden', hidden);
            });
        }
    });

    // land the viewport on today rather than on the far past
    var scroll = root.querySelector('.gt-scroll');
    var tx = x(D.today);
    if (tx !== null && tx > (scroll.clientWidth - LEFT_W) * 0.6) {
        scroll.scrollLeft = Math.max(0, tx - Math.round((scroll.clientWidth - LEFT_W) * 0.4));
    }

    });
})();
</script>
