/**
 * Exment dashboard runtime: box loading, the dashboard filter bar, the chart toolbar
 * (chart-type switcher + chart filter).
 *
 * Entry point: ExmentDashboard.init({lang: {...}}) from DashboardController::home.
 * Re-run on every pjax render, so every binding is namespaced and re-bound idempotently.
 *
 * Request params of a box (dashboardbox/html/{suuid}):
 *   df_{column}           dashboard filter bar selection, copied from the page URL
 *   ct                    runtime chart type (per box; remembered per user)
 *   cs                    runtime sort of the points (per box; remembered per user)
 *   cd                    display option 値ラベル ("labels"; per box; remembered per user)
 *   bf_{column}           chart filter selection (page-lifetime, per box)
 *
 * A filter bar change is applied selectively (see `navigate`): push a history entry for
 * the new URL, reload only the boxes the changed items narrow (their data-df-dims
 * attribute), refresh the bar from a partial request (?_df_bar=1) — untouched boxes keep
 * their content. The entry is one of pjax's own (see `pushEntry`), so back / forward
 * re-render the page for the entry's URL the way pjax restores any page.
 *
 * Cross-highlight: a chart never filters by its own X item (ChartItem::highlightColumn), it
 * draws the picked values solid and fades the rest. A change on that item alone — a click on
 * the chart, or the same item on the bar — is repainted in place through the chart's
 * `highlight` hook (window.ExmentCharts[suuid]) instead of a fetch: the data cannot differ.
 */
(function ($) {
    'use strict';

    var NS = '.exdash';
    var L = {};      // texts
    var A = {};      // asset base URLs (init's options.assets)
    var state = {};  // suuid => {ct: type, cs: sort, cd: "labels", bf: {column: [values] | {from, to}}, reopen: true}
    var saving = {}; // suuid => true while its toolbar choices are being saved, 'again' when they changed meanwhile
    var pagePath = ''; // path of the dashboard page, set by init: a pjax link pushes its own URL before it renders

    function esc(s) { return $('<i>').text(String(s == null ? '' : s)).html(); }
    function boxOf(suuid) { return $('[data-suuid="' + suuid + '"]'); }
    function stateOf(suuid) { return state[suuid] || (state[suuid] = {}); }

    // The toolbar choices (chart type, sort, display) survive the visit per user, on the server
    // (DashboardBoxController::chartState): the next entry starts the box with them (init's
    // options.charts). One save per box at a time, the last one carrying the latest choices, so
    // quick changes can never land out of order. A stale value is harmless — the server falls
    // back to the configured type / the view's order.
    function saveToolbar(suuid) {
        if (saving[suuid]) { saving[suuid] = 'again'; return; }
        saving[suuid] = true;
        var s = stateOf(suuid);
        $.ajax({
            url: admin_url('dashboardbox/chart_state/' + suuid),
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' },
            data: { ct: s.ct || '', cs: s.cs || '', cd: s.cd || '' }
        }).always(function () {
            var again = saving[suuid] === 'again';
            delete saving[suuid];
            if (again) { saveToolbar(suuid); }
        });
    }

    // ---- box request URL --------------------------------------------------------------
    function dfParams() {
        var out = [];
        new URLSearchParams(window.location.search).forEach(function (v, k) {
            if (k.indexOf('df_') === 0 && v !== '') { out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); }
        });
        return out;
    }

    // the values picked on one bar item, as the page URL carries them (df_col=v / df_col[]=v);
    // null when the item carries a from / to (df_col[from] / df_col[to]) instead: that is no
    // pick a chart could highlight, the server filters by it (DashboardFilter::styleOf)
    function dfValues(column) {
        var out = [], range = false;
        new URLSearchParams(window.location.search).forEach(function (v, k) {
            if (v === '') { return; }
            if (k === 'df_' + column || k === 'df_' + column + '[]') { out.push(v); }
            else if (k === 'df_' + column + '[from]' || k === 'df_' + column + '[to]') { range = true; }
        });
        return range ? null : out;
    }

    function boxQuery(suuid) {
        var s = stateOf(suuid), out = dfParams();
        if (s.ct) { out.push('ct=' + encodeURIComponent(s.ct)); }
        if (s.cs) { out.push('cs=' + encodeURIComponent(s.cs)); }
        if (s.cd) { out.push('cd=' + encodeURIComponent(s.cd)); }
        $.each(s.bf || {}, function (column, v) {
            var key = 'bf_' + encodeURIComponent(column);
            if ($.isArray(v)) {
                if (v.length === 1) { out.push(key + '=' + encodeURIComponent(v[0])); }
                else { $.each(v, function (i, x) { out.push(key + '[]=' + encodeURIComponent(x)); }); }
            } else {
                if (v.from) { out.push(key + '[from]=' + encodeURIComponent(v.from)); }
                if (v.to) { out.push(key + '[to]=' + encodeURIComponent(v.to)); }
            }
        });
        return out.length ? '?' + out.join('&') : '';
    }

    // ---- box loading ------------------------------------------------------------------
    function loadBox(suuid, url) {
        if (!suuid) { return; }
        var $box = boxOf(suuid);
        if ($box.hasClass('loading')) {
            // a newer state arrived while fetching: re-run once this request completes
            $box.data('pending', url || true);
            return;
        }
        $box.removeData('pending').addClass('loading');
        // the popover a chart-filter change came from reopens on the render of this request
        // alone: one that fails, or lands on a page rendered again meanwhile, drops it
        var reopen = !!stateOf(suuid).reopen;
        delete stateOf(suuid).reopen;
        var $body = $box.find('.box-body-inner-body');
        $body.css('height', $body.height());
        $box.find('.box-body-inneritem').html('');
        $box.find('.overlay').show();

        $.ajax({
            url: url || (admin_url('dashboardbox/html/' + suuid) + boxQuery(suuid)),
            type: 'GET',
            success: function (data) {
                if (replaced($box)) { return; }
                // the new body's script registers the chart hooks afresh: a stale hook must never answer for it
                if (window.ExmentCharts) { delete window.ExmentCharts[suuid]; }
                if (data.header) { $box.find('.box-body-inner-header').html(data.header); }
                if (data.body) { $box.find('.box-body-inner-body').html(data.body); }
                if (data.footer) { $box.find('.box-body-inner-footer').html(data.footer); }
                $body.css('height', '');
                $box.find('.overlay').hide();
                // the server may have dropped chart-filter values the new scope no longer
                // offers: the rendered popover is the truth, mirror it
                if ($box.find('.exment-bf-list, .exment-bf-range').length) {
                    stateOf(suuid).bf = collectChartFilter($box);
                }
                if (reopen) {
                    openPop($box.find('[data-ct-pop]').first());
                }
                // the request may have been built before a pushState'd filter change:
                // recompute the badge against the current URL (same result as the server's)
                syncBadge($box);
                $box.trigger('exment:dashboard_loaded');
                $box.removeClass('loading');
                Exment.CommonEvent.tableHoverLink();
                flushPending($box, suuid);
            },
            error: function () {
                if (replaced($box)) { return; }
                $box.find('.overlay').hide();
                $box.removeClass('loading');
                $box.find('.box-body-inner-body').html(esc(L.error));
                flushPending($box, suuid);
            }
        });
    }

    // The page was rendered again (pjax) while the box was fetching: its suuid — the chart
    // hooks, the toolbar state — belongs to the new page's box now, which loads on its own
    function replaced($box) {
        return !$.contains(document.documentElement, $box[0]);
    }

    function flushPending($box, suuid) {
        var pending = $box.data('pending');
        if (pending) {
            $box.removeData('pending');
            loadBox(suuid, pending === true ? null : pending);
        }
    }

    // ---- chart toolbar ----------------------------------------------------------------
    function closePops() {
        $('.ct-pop').removeClass('show');
        $('.ct-btn').removeClass('open');
    }

    function openPop($btn) {
        if (!$btn.length) { return; }
        closePops();
        $btn.addClass('open');
        var $pop = $btn.closest('.ct-item').find('.ct-pop').first().addClass('show');
        // keep the panel inside its card: cap its width and pull an overflowing edge back
        var card = $pop.closest('.box')[0], item = $pop.closest('.ct-item')[0];
        if (!card || !item) { return; }
        var PAD = 8, c = card.getBoundingClientRect(), room = c.width - PAD * 2;
        $pop.css({ left: '', right: '', 'max-width': room + 'px' });
        if ($pop[0].getBoundingClientRect().width > room) {
            $pop.css('min-width', 0); // the fields' own min-width beats max-width: let them wrap instead
        }
        var p = $pop[0].getBoundingClientRect(), i = item.getBoundingClientRect();
        if (p.left < c.left + PAD) { $pop.css({ left: (c.left + PAD - i.left) + 'px', right: 'auto' }); }
        else if (p.right > c.right - PAD) { $pop.css({ left: 'auto', right: (i.right - (c.right - PAD)) + 'px' }); }
    }

    // A range input is rendered with the data's end (its data-end: the min for "from", the max
    // for "to") when nothing is typed on it, as a Power BI numeric range slicer shows the ends.
    // Left there — or typed back to it, "72" = "72.0" — it bounds nothing and stays off the request.
    function atDataEnd($input) {
        var v = String($input.val() || '').trim(), end = String($input.attr('data-end') || '');
        if (!v || !end) { return false; }
        if (v === end) { return true; }
        var a = Number(v), b = Number(end);
        return !isNaN(a) && !isNaN(b) && a === b;
    }

    function collectChartFilter($box) {
        var sel = {};
        $box.find('.exment-bf-list').each(function () {
            var column = $(this).data('column'), values = [];
            $(this).find('.exment-bf-check:checked').each(function () { values.push(String($(this).val())); });
            if (column && values.length) { sel[column] = values; }
        });
        $box.find('.exment-bf-range').each(function () {
            var column = $(this).data('column'), bound = $(this).data('bound'), v = String($(this).val() || '').trim();
            if (!column || !v || atDataEnd($(this))) { return; }
            if (!sel[column] || $.isArray(sel[column])) { sel[column] = {}; }
            sel[column][bound] = v;
        });
        return sel;
    }

    function applySort(suuid, cs) {
        if (!suuid) { return; }
        if (cs) { stateOf(suuid).cs = cs; } else { delete stateOf(suuid).cs; }
        saveToolbar(suuid);
        loadBox(suuid);
    }

    // display options: kept as the request value ("labels"), applied live through the
    // renderer's hook — no reload — and re-applied by the server on the next one
    function displayOf(suuid) {
        var on = {};
        $.each(String(stateOf(suuid).cd || '').split(','), function (i, k) { if (k) { on[k] = true; } });
        return on;
    }
    function applyDisplay(suuid, on) {
        var keys = [];
        $.each(['labels'], function (i, k) { if (on[k]) { keys.push(k); } });
        var cd = keys.join(',');
        if (cd) { stateOf(suuid).cd = cd; } else { delete stateOf(suuid).cd; }
        saveToolbar(suuid);
        var hook = (window.ExmentCharts || {})[suuid];
        if (hook && typeof hook.display === 'function') { try { hook.display({ labels: !!on.labels }); return; } catch (e) {} }
        loadBox(suuid);
    }
    // CSV of the plotted points (UTF-8 BOM for Excel), named after the box
    function exportCsv(suuid) {
        var hook = (window.ExmentCharts || {})[suuid], $box = boxOf(suuid);
        if (!hook || typeof hook.data !== 'function') { return; }
        var d = hook.data(), rows = [];
        // a text that a spreadsheet would read as a formula (= + - @, a tab or a CR first) is
        // kept as text by a quote in front (CSV injection); a number stays a number, written as
        // a label shows one too ("-1,234", "-10.5%")
        var cell = function (v) {
            var s = String(v == null ? '' : v);
            if (typeof v !== 'number' && /^[=+\-@\t\r]/.test(s) && !/^[+-]?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?%?$/.test(s)) { s = "'" + s; }
            return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        if (d.matrix) {
            rows.push([d.axisx || ''].concat(d.series));
            // an empty cell (null: a MIN / MAX cell no row fell into) is an empty field — $.map
            // would drop a null and shift the columns after it
            $.each(d.categories, function (i, c) { rows.push([c].concat($.map(d.series, function (s, si) { var v = (d.matrix[si] || [])[i]; return v == null ? '' : v; }))); });
        } else {
            rows.push([d.axisx || '', d.axisy || '']);
            $.each(d.labels, function (i, l) { rows.push([l, d.values[i]]); });
        }
        var csv = '\ufeff' + $.map(rows, function (r) { return $.map(r, cell).join(','); }).join('\r\n');
        var title = $.trim($box.find('.box-title').first().text()) || 'chart', now = new Date();
        var stamp = now.getFullYear() + ('0' + (now.getMonth() + 1)).slice(-2) + ('0' + now.getDate()).slice(-2);
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = title + '_' + stamp + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    }

    // ---- chart colors -------------------------------------------------------------------
    // A dashboard editor right-clicks a point / slice / series and picks its color, the way
    // Excel's Fill works: the renderers call colorMenu(suuid, {kind, key, color}, event)
    // only when the server allows it (ChartItem::canEditColors). The color is stored on the
    // box for everyone (DashboardBoxController::chartColor), then the box re-renders.
    // the accents of the current Office theme (the old one's #ffc000 duplicated the standard row)
    // plus a darker shade of the first four
    var CC_THEME = ['#156082', '#e97132', '#196b24', '#0f9ed5', '#a02b93', '#4ea72e', '#0a3041', '#75391a', '#0c3612', '#084f6b'];
    var CC_STANDARD = ['#c00000', '#ff0000', '#ffc000', '#ffff00', '#92d050', '#00b050', '#00b0f0', '#0070c0', '#002060', '#7030a0'];

    function closeColorMenu() { $('.cc-pop').remove(); }

    function saveColor(suuid, data) {
        closeColorMenu();
        $.ajax({
            url: admin_url('dashboardbox/chart_color/' + suuid),
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' },
            data: data
        }).done(function () { loadBox(suuid); })
            .fail(function () { if (window.toastr) { window.toastr.error(L.error); } });
    }

    function colorMenu(suuid, target, ev) {
        closeColorMenu();
        closePops();
        var current = String(target.color || '').toLowerCase();
        var swatches = function (list) {
            return '<div class="cc-grid">' + $.map(list, function (c) {
                return '<button type="button" class="cc-swatch' + (c === current ? ' on' : '') + '" data-cc-color="' + c + '" style="background:' + c + '" title="' + c + '"></button>';
            }).join('') + '</div>';
        };
        var start = /^#[0-9a-f]{6}$/.test(current) ? current : '#4472c4';
        var $pop = $('<div class="cc-pop"></div>').html(
            '<h4>' + esc(L.color_theme) + '</h4>' + swatches(CC_THEME)
            + '<h4>' + esc(L.color_standard) + '</h4>' + swatches(CC_STANDARD)
            + '<div class="cc-row" data-cc-auto><i class="fa fa-undo"></i>' + esc(L.color_auto) + '</div>'
            + '<div class="cc-row cc-more"><i class="fa fa-eyedropper"></i>' + esc(L.color_more) + '</div>'
            + '<div class="cc-picker"></div>'
        ).appendTo(document.body);
        var pick = function (color) { saveColor(suuid, { kind: target.kind, key: target.key, color: color }); };
        $pop.on('click', '[data-cc-color]', function () { pick($(this).data('ccColor')); });
        $pop.on('click', '[data-cc-auto]', function () { pick(''); });

        // at the pointer, kept inside the window — again when the picker makes it taller, since
        // scrolling to reach it would close the menu
        var x = (ev && ev.clientX) || 0, y = (ev && ev.clientY) || 0;
        var place = function () {
            var w = $pop.outerWidth(), h = $pop.outerHeight();
            $pop.css({ left: Math.max(4, Math.min(x, window.innerWidth - w - 4)) + 'px', top: Math.max(4, Math.min(y, window.innerHeight - h - 4)) + 'px' });
        };

        // その他の色: a picker drawn in the menu — the browser's own one cannot carry a button —
        // with the same controls (saturation / brightness square, eyedropper where the browser
        // has one, preview, hue, R G B or HEX fields) and OK at its bottom right. Nothing is
        // saved before OK; Esc / a click away keeps the current color. Not `.show` for the
        // picker: Bootstrap 3 forces that class to display:block.
        var $picker = $pop.find('.cc-picker'), hsv = rgbToHsv(hexToRgb(start));
        var hex = function () { return rgbToHex(hsvToRgb(hsv)); };
        var render = function (keep) {
            var rgb = hsvToRgb(hsv);
            $picker.find('.cc-sv').css('background', 'linear-gradient(to top, #000, rgba(0, 0, 0, 0)), linear-gradient(to right, #fff, hsl(' + Math.round(hsv.h) + ', 100%, 50%))');
            $picker.find('.cc-sv-dot').css({ left: (hsv.s * 100) + '%', top: ((1 - hsv.v) * 100) + '%' });
            $picker.find('.cc-preview').css('background', hex());
            if (keep !== 'hue') { $picker.find('.cc-hue').val(Math.round(hsv.h)); }
            if (keep !== 'fields') {
                var hsl = hsvToHsl(hsv);
                $picker.find('[data-cc-ch]').each(function () { this.value = rgb[$(this).data('ccCh')]; });
                $picker.find('[data-cc-hsl]').each(function () { this.value = hsl[$(this).data('ccHsl')]; });
                $picker.find('.cc-hexin').val(hex().toUpperCase()); // with the #, as the browser's picker shows it
            }
        };
        $pop.on('click', '.cc-more', function () {
            if (!$picker.children().length) {
                $picker.html(
                    '<div class="cc-sv"><span class="cc-sv-dot"></span></div>'
                    + '<div class="cc-pick-row">' + (window.EyeDropper ? '<button type="button" class="cc-drop"><i class="fa fa-eyedropper"></i></button>' : '')
                    + '<span class="cc-preview"></span><input type="range" class="cc-hue" min="0" max="359" step="1"></div>'
                    + '<div class="cc-fields cc-m-rgb"><div class="cc-f-rgb">' + $.map(['r', 'g', 'b'], function (ch) {
                        return '<label><input type="number" min="0" max="255" data-cc-ch="' + ch + '"><span>' + ch.toUpperCase() + '</span></label>';
                    }).join('') + '</div>'
                    + '<div class="cc-f-hsl">' + $.map([['h', 359], ['s', 100], ['l', 100]], function (f) {
                        return '<label><input type="number" min="0" max="' + f[1] + '" data-cc-hsl="' + f[0] + '"><span>' + f[0].toUpperCase() + '</span></label>';
                    }).join('') + '</div>'
                    + '<div class="cc-f-hex"><label><input type="text" class="cc-hexin" maxlength="7" spellcheck="false" autocomplete="off"><span>HEX</span></label></div>'
                    + '<button type="button" class="cc-mode" title="RGB / HEX"><i class="fa fa-sort"></i></button></div>'
                    + '<div class="cc-pick-foot"><button type="button" class="cc-ok">' + esc(L.color_ok || 'OK') + '</button></div>'
                );
                render();
            }
            $picker.toggleClass('cc-shown');
            place();
        });
        $pop.on('mousedown', '.cc-sv', function (e) {
            e.preventDefault();
            var el = this;
            var move = function (m) {
                var r = el.getBoundingClientRect();
                hsv.s = Math.min(1, Math.max(0, (m.clientX - r.left) / r.width));
                hsv.v = Math.min(1, Math.max(0, 1 - (m.clientY - r.top) / r.height));
                render();
            };
            move(e);
            $(document).on('mousemove.ccsv', move).one('mouseup.ccsv', function () { $(document).off('.ccsv'); });
        });
        $pop.on('input', '.cc-hue', function () { hsv.h = Number(this.value) || 0; render('hue'); });
        $pop.on('input', '[data-cc-ch]', function () {
            var rgb = {};
            $picker.find('[data-cc-ch]').each(function () { rgb[$(this).data('ccCh')] = Math.min(255, Math.max(0, parseInt(this.value, 10) || 0)); });
            hsv = rgbToHsv(rgb);
            render('fields');
        });
        $pop.on('input', '.cc-hexin', function () {
            var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec($.trim(this.value));
            if (m) { hsv = rgbToHsv(hexToRgb(m[1])); render('fields'); }
        });
        $pop.on('input', '[data-cc-hsl]', function () {
            var hsl = {};
            $picker.find('[data-cc-hsl]').each(function () {
                var k = $(this).data('ccHsl');
                hsl[k] = Math.min(k === 'h' ? 359 : 100, Math.max(0, parseInt(this.value, 10) || 0));
            });
            hsv = hslToHsv(hsl);
            render('fields');
        });
        // the browser's picker cycles RGB / HSL / HEX, so this one does too
        $pop.on('click', '.cc-mode', function () {
            var $f = $picker.find('.cc-fields'), modes = ['cc-m-rgb', 'cc-m-hsl', 'cc-m-hex'];
            var at = 0;
            $.each(modes, function (i, m) { if ($f.hasClass(m)) { at = i; } });
            $f.removeClass(modes.join(' ')).addClass(modes[(at + 1) % modes.length]);
            render();
        });
        $pop.on('click', '.cc-drop', function () {
            new window.EyeDropper().open().then(function (r) { hsv = rgbToHsv(hexToRgb(r.sRGBHex)); render(); }).catch(function () {});
        });
        $pop.on('click', '.cc-ok', function () {
            var v = hex();
            if (v !== start) { pick(v); } else { closeColorMenu(); }
        });
        place();
    }

    // color conversions of the menu's picker: {r, g, b} 0-255, {h 0-360, s 0-1, v 0-1}
    function hexToRgb(h) {
        var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(h || '')), s = m ? m[1] : '000000';
        if (s.length === 3) { s = s.replace(/(.)/g, '$1$1'); }
        return { r: parseInt(s.slice(0, 2), 16), g: parseInt(s.slice(2, 4), 16), b: parseInt(s.slice(4, 6), 16) };
    }
    function rgbToHex(c) {
        return '#' + $.map([c.r, c.g, c.b], function (n) { var s = Math.round(n).toString(16); return s.length < 2 ? '0' + s : s; }).join('');
    }
    function rgbToHsv(c) {
        var r = c.r / 255, g = c.g / 255, b = c.b / 255, max = Math.max(r, g, b), d = max - Math.min(r, g, b), h = 0;
        if (d) { h = max === r ? ((g - b) / d) % 6 : (max === g ? (b - r) / d + 2 : (r - g) / d + 4); }
        return { h: (h * 60 + 360) % 360, s: max ? d / max : 0, v: max };
    }
    function hsvToRgb(c) {
        var f = function (n) { var k = (n + c.h / 60) % 6; return Math.round(255 * (c.v - c.v * c.s * Math.max(0, Math.min(k, 4 - k, 1)))); };
        return { r: f(5), g: f(3), b: f(1) };
    }
    // the HSL the fields show (s / l as whole percents), and back
    function hsvToHsl(c) {
        var l = c.v * (1 - c.s / 2), m = Math.min(l, 1 - l);
        return { h: Math.round(c.h), s: Math.round((m ? (c.v - l) / m : 0) * 100), l: Math.round(l * 100) };
    }
    function hslToHsv(c) {
        var l = c.l / 100, s = c.s / 100, v = l + s * Math.min(l, 1 - l);
        return { h: c.h, s: v ? 2 * (1 - l / v) : 0, v: v };
    }

    function reloadFromToolbar($el, reset) {
        var $box = $el.closest('[data-suuid]'), suuid = $box.data('suuid');
        if (!suuid) { return; }
        if (reset) { delete stateOf(suuid).bf; } else { stateOf(suuid).bf = collectChartFilter($box); }
        stateOf(suuid).reopen = true;
        loadBox(suuid);
    }

    // ---- filter bar -------------------------------------------------------------------
    function initFilterBar() {
        $('.exment-df-bar .df-select').each(function () {
            var $s = $(this);
            if ($s.hasClass('select2-hidden-accessible')) { return; }
            $s.select2({ width: '100%', closeOnSelect: false, placeholder: $s.data('placeholder') || '' });
            $s.data('applied', ($s.val() || []).slice());
        });
        initSliders();
    }

    // ---- ECharts (lazy) ---------------------------------------------------------------
    // The ECharts bundle (1 MB) is not loaded with every admin page: the first ECharts box of a
    // dashboard fetches it from the URL init passes (options.assets.echarts) and the box blades
    // render inside withECharts(suuid).done(...). Resolved at once when it is already there;
    // when the fetch fails, the box of `suuid` says so instead of staying blank.
    var echartsLoading = null;
    function withECharts(suuid) {
        var loading;
        if (window.echarts) {
            loading = $.Deferred().resolve().promise();
        } else if (echartsLoading) {
            loading = echartsLoading;
        } else if (!A.echarts) {
            loading = $.Deferred().reject().promise();
        } else {
            echartsLoading = loading = $.ajax({ url: A.echarts, dataType: 'script', cache: true })
                .fail(function () { echartsLoading = null; }); // a failed fetch is retried by the next box
        }
        if (suuid) { loading.fail(function () { chartError(suuid, L.chart_load_error); }); }
        return loading;
    }
    // Why a chart box is empty, in its chart area (the echart blades use it for a render failure too)
    function chartError(suuid, message) {
        $('[data-echart-id="' + suuid + '"]').html($('<div class="exment-chart-error">').text(String(message == null ? '' : message)));
    }

    // ---- range slider -----------------------------------------------------------------
    // A number range item carries a two-handle slider under its inputs (filter_bar.blade
    // data-slider), like a Power BI numeric range slicer. The slider is the admin layer's own
    // Ion.RangeSlider (1.8, its Slider form field's), fetched on first use from the base URL
    // init passes. Dragging moves the numbers in the inputs live; releasing applies them as
    // a typed bound would (a handle left at its end bounds nothing). A typed bound reaches
    // the slider through the bar's re-render, which rebuilds it from the inputs.
    var ionLoading = null;
    function withIonSlider() {
        if ($.fn.ionRangeSlider) { return $.Deferred().resolve().promise(); }
        if (ionLoading) { return ionLoading; }
        var base = String(A.ionslider || '').replace(/\/$/, '');
        if (!base) { return $.Deferred().reject().promise(); }
        if (!$('#exment-ionslider-css').length) {
            $('<link id="exment-ionslider-css" rel="stylesheet">').attr('href', base + '/ion.rangeSlider.css').appendTo('head');
        }
        ionLoading = $.getScript(base + '/ion.rangeSlider.min.js')
            .fail(function () { ionLoading = null; }); // a failed fetch is retried by the next bar
        return ionLoading;
    }
    // the decimals the two ends are written with give the step: 1 for integers, 0.1 for "72.5"
    function stepFor(a, b) {
        var d = 0;
        $.each([a, b], function (i, s) { var m = /\.(\d+)$/.exec(String(s)); if (m && m[1].length > d) { d = m[1].length; } });
        return { step: Math.pow(10, -d), decimals: d };
    }
    // Ion binds its drag handlers to <body> and window, which outlive the bar: a slider is
    // removed (its handlers with it) before its bar is replaced or left
    var sliders = [];
    function removeSliders() {
        $.each(sliders, function (i, $input) {
            try { $input.ionRangeSlider('remove'); } catch (e) {}
        });
        sliders = [];
    }
    // A handle held down: the bar is not replaced under it — its release applies the drag
    // from the inputs of this bar. Ion stops the mousedown from bubbling, so it is seen on
    // its way down (capture), once per document.
    var sliding = false, slidingWatched = false;
    function watchSliding() {
        if (slidingWatched) { return; }
        slidingWatched = true;
        var down = function (ev) { sliding = !!$(ev.target).closest('.exment-df-bar .irs').length; };
        var up = function () { sliding = false; };
        document.addEventListener('mousedown', down, true);
        document.addEventListener('touchstart', down, true);
        document.addEventListener('mouseup', up, true);
        document.addEventListener('touchend', up, true);
    }
    function initSliders() {
        var $ranges = $('.exment-df-bar .df-range[data-slider]').filter(function () { return !$(this).find('.irs').length; });
        if (!$ranges.length) { return; }
        withIonSlider().done(function () {
            $ranges.each(function () {
                var $r = $(this), $from = $r.find('[data-bound="from"]'), $to = $r.find('[data-bound="to"]'), $wrap = $r.find('.df-slider-wrap');
                var min = Number($from.attr('data-end')), max = Number($to.attr('data-end'));
                if (!$wrap.length || isNaN(min) || isNaN(max) || min >= max) { return; }
                var s = stepFor($from.attr('data-end'), $to.attr('data-end'));
                // an empty input keeps its end (Number('') would be 0)
                var clamp = function (v, dflt) { v = $.trim(String(v == null ? '' : v)); var n = Number(v); return (v === '' || isNaN(n)) ? dflt : Math.min(max, Math.max(min, n)); };
                var show = function (n) { return String(Number(Number(n).toFixed(s.decimals))); };
                var $input = $('<input type="hidden" class="df-slider">').appendTo($wrap.empty());
                sliders.push($input);
                // a typed bound outside today's data range sits clamped on the slider: only the
                // input of the handle being dragged takes the slider's value, the other keeps
                // what it holds (60 stays 60 while the data starts at 80)
                var held = { from: clamp($from.val(), min), to: clamp($to.val(), max) };
                $input.ionRangeSlider({
                    type: 'double', min: min, max: max, step: s.step,
                    from: held.from, to: held.to,
                    hideMinMax: true, hideFromTo: true, hasGrid: false,
                    onChange: function (o) {
                        // moved = more than half a step away (the slider rounds to its step)
                        var moved = function (a, b) { return Math.abs(Number(a) - Number(b)) > s.step / 2; };
                        if (moved(o.fromNumber, held.from)) {
                            $from.val(show(o.fromNumber)).toggleClass('active', !atDataEnd($from));
                        }
                        if (moved(o.toNumber, held.to)) {
                            $to.val(show(o.toNumber)).toggleClass('active', !atDataEnd($to));
                        }
                        held = { from: o.fromNumber, to: o.toNumber };
                    },
                    onFinish: function () { navigate(filterBarUrl()); }
                });
            });
        });
    }

    function selectChanged($s) {
        var a = ($s.data('applied') || []).slice().sort(), b = ($s.val() || []).slice().sort();
        return a.join('') !== b.join('');
    }

    function filterBarUrl() {
        var $bar = $('.exment-df-bar'), params = new URLSearchParams();
        var dashboard = $bar.data('dashboard-suuid');
        if (dashboard) { params.set('dashboard', dashboard); }
        $bar.find('.df-select').each(function () {
            var column = $(this).data('column'), values = $(this).val() || [];
            if (values.length === 1) { params.set('df_' + column, values[0]); }
            else { values.forEach(function (v) { params.append('df_' + column + '[]', v); }); }
        });
        $bar.find('.df-range').each(function () {
            var column = $(this).data('column');
            $(this).find('.df-range-input').each(function () {
                var v = String($(this).val() || '').trim();
                if (v && !atDataEnd($(this))) { params.set('df_' + column + '[' + $(this).data('bound') + ']', v); }
            });
        });
        // a bar the user emptied (every value removed) is marked dfr=1: the server remembers it
        // empty, so the next entry does not re-apply the configured defaults
        var hasDf = false;
        params.forEach(function (v, k) { if (k.indexOf('df_') === 0) { hasDf = true; } });
        if (!hasDf) { params.set('dfr', '1'); }
        var query = params.toString();
        return pagePath + (query ? '?' + query : '');
    }

    // Click-to-filter from a chart: select `value` on the bar's `column` item — click = only
    // this value (clicking the sole selected value again clears it), Ctrl/⌘-click = toggle it
    // within the current selection — then navigate like a bar change.
    function pick(column, value, toggle) {
        var $s = $('.exment-df-bar .df-select[data-column="' + column + '"]');
        value = String(value == null ? '' : value);
        // the option is found by comparing values: a value is free text, and a backslash or
        // a quote in it would break a selector
        var known = $s.find('option').filter(function () { return this.value === value; }).length > 0;
        if (!$s.length || value === '' || !known) { return; }
        var current = ($s.val() || []).slice(), values;
        if (toggle) {
            values = current.indexOf(value) >= 0 ? current.filter(function (v) { return v !== value; }) : current.concat([value]);
        } else {
            values = (current.length === 1 && current[0] === value) ? [] : [value];
        }
        $s.val(values);
        navigate(filterBarUrl());
    }

    // ---- selective apply --------------------------------------------------------------
    // A bar change reloads ONLY the boxes the changed items narrow (data-df-dims, written
    // per box by DashboardController): the others keep their rendered content — no spinner
    // that could read as "this chart was filtered too". The URL still updates (a history
    // entry, so F5 / back keep working) and the bar re-renders its option lists from a
    // partial request. Anything unexpected falls back to a full pjax render.
    var barReq = 0;

    function dfColumnOf(key) {
        if (key.indexOf('df_') !== 0) { return null; }
        var column = key.slice(3), bracket = column.indexOf('[');
        return bracket >= 0 ? column.slice(0, bracket) : column;
    }

    // active selection of a URL query string, as column => sorted ["key=value", ...]
    function dfSelection(search) {
        var sel = {};
        new URLSearchParams(search).forEach(function (v, k) {
            var column = dfColumnOf(k);
            if (column && v !== '') { (sel[column] = sel[column] || []).push(k + '=' + v); }
        });
        $.each(sel, function (column, parts) { parts.sort(); });
        return sel;
    }

    function changedColumns(oldSearch, newSearch) {
        var a = dfSelection(oldSearch), b = dfSelection(newSearch), out = [];
        $.each(a, function (column, parts) {
            if (!b[column] || b[column].join('&') !== parts.join('&')) { out.push(column); }
        });
        $.each(b, function (column) {
            if (!a[column]) { out.push(column); }
        });
        return out;
    }

    function boxDims($box) {
        return String($box.attr('data-df-dims') || '').split(',').filter(Boolean);
    }

    // client mirror of DashboardBoxController::filterBadge, for boxes that skip the reload:
    // the active items minus the ones this box honours — so unfiltered numbers stay disclosed
    // without a re-fetch. No-op on boxes without data-df-dims (server badge is the truth).
    function syncBadge($box) {
        if ($box.attr('data-df-dims') === undefined) { return; }
        var active = Object.keys(dfSelection(window.location.search));
        var dims = boxDims($box);
        var ignored = active.filter(function (column) { return dims.indexOf(column) < 0; });
        var $body = $box.find('.box-body-inner-body'), $badge = $body.find('.exment-filter-badge').first();
        if (!$body.length || !ignored.length) { $badge.remove(); return; }
        var labels = {};
        $('.exment-df-bar .df-field').each(function () {
            var column = $(this).find('[data-column]').first().data('column');
            if (column) { labels[column] = $.trim($(this).find('label').first().text()); }
        });
        var text = ignored.length === active.length
            ? L.filter_not_affected
            : L.filter_partially_affected + ': ' + ignored.map(function (column) { return labels[column] || column; }).join(', ');
        if (!$badge.length) { $badge = $('<div class="exment-filter-badge"><span></span></div>').prependTo($body); }
        $badge.find('span').text(text);
    }

    function refreshFilterBar(url) {
        var token = ++barReq;
        $.get(url + (url.indexOf('?') >= 0 ? '&' : '?') + '_df_bar=1').done(function (html) {
            if (token !== barReq) { return; }
            var $fresh = $('<div>').append($.parseHTML(String(html))).find('.exment-df-bar').first();
            syncOpenSelects($fresh);
            applyFilterBar($fresh, token);
        });
    }

    // While a dropdown is open the fresh bar cannot be swapped in (applyFilterBar defers):
    // narrow the OPEN select's option list in place instead, so picking fast never picks
    // from a stale list. The user's selection is preserved; selected values the fresh scope
    // no longer offers stay listed so they can be removed (mirror of FilterBarView).
    function syncOpenSelects($fresh) {
        $('.exment-df-bar .df-select').each(function () {
            var $s = $(this), open = false;
            try { open = $s.data('select2') && $s.select2('isOpen'); } catch (e) {}
            if (!open) { return; }
            var $freshSelect = $fresh.find('.df-select[data-column="' + $s.data('column') + '"]');
            if (!$freshSelect.length) { return; }
            var selected = ($s.val() || []).map(String), seen = {}, opts = [];
            $freshSelect.find('option').each(function () {
                seen[String($(this).val())] = true;
                opts.push($(this).clone());
            });
            $.each(selected.slice().reverse(), function (i, v) {
                if (seen[v]) { return; }
                var $old = $s.find('option').filter(function () { return String($(this).val()) === v; }).first();
                opts.unshift($('<option>').val(v).text($old.length ? $old.text() : v));
            });
            $s.empty().append(opts).val(selected).trigger('change.select2');
            try {
                var s2 = $s.data('select2');
                var term = s2.$container ? String(s2.$container.find('.select2-search__field').val() || '') : '';
                s2.trigger('query', { term: term }); // re-render the open results list
            } catch (e) {}
        });
    }

    // Swap in the freshly rendered bar — but never while the user is in it: replacing the
    // DOM under an open select2 strands its dropdown at the page corner (the dropdown is
    // attached to <body> and loses its anchor). Retry until free; a newer refresh (token
    // bump) obsoletes this one.
    function applyFilterBar($fresh, token) {
        if (token !== barReq) { return; }
        var $bar = $('.exment-df-bar').first();
        if (!$fresh.length || !$bar.length) { return; }
        var active = document.activeElement;
        var busy = sliding || $bar.find('.df-select').toArray().some(function (el) {
            try { return $(el).data('select2') && $(el).select2('isOpen'); } catch (e) { return false; }
        }) || ($.contains($bar[0], active) && (
            $(active).hasClass('df-range-input')                                             // typing a range bound
            || ($(active).hasClass('select2-search__field') && String(active.value || '') !== '') // typed search text
        ));
        if (busy) { setTimeout(function () { applyFilterBar($fresh, token); }, 250); return; }
        $bar.find('.df-select').each(function () {
            try { $(this).select2('destroy'); } catch (e) {}
        });
        removeSliders();
        $bar.replaceWith($fresh);
        initFilterBar();
    }

    // pjax's request is sent and not answered yet (readyState 0 again once aborted)
    function rendering() {
        var xhr = $.pjax && $.pjax.xhr;
        return !!xhr && xhr.readyState > 0 && xhr.readyState < 4;
    }

    function fullNavigate(url) {
        // an open dropdown is attached to <body> and would survive the pjax swap as an orphan
        $('.exment-df-bar .df-select').each(function () {
            try { $(this).select2('close'); } catch (e) {}
        });
        if ($.pjax) { $.pjax({ url: url, container: '#pjax-container' }); } else { window.location.href = url; }
    }

    // ---- history ----------------------------------------------------------------------
    // A filter change gets a history entry of its own, written as one of pjax's: an entry
    // whose state has pjax's shape (id, url, container) is restored by pjax on back /
    // forward through a full render of its URL — fetched afresh each time, open-admin keeps
    // no pjax cache (maxCacheLength 0) — and pjax's notion of the current entry
    // ($.pjax.state) follows ours, or it would skip the entry it still believes current.
    // The entry the dashboard was rendered in becomes such an entry too when it is not one
    // yet (a full page load), and its URL says explicitly that the bar is empty (dfr) when
    // it carries no filter state: fetched again on the way back, a URL without one is
    // redirected to this user's remembered selection (DashboardController::
    // filterDefaultRedirect), and the entry would show another state than its URL.
    var lastId = 0;

    // pjax reads the navigation direction off its ids (timestamps): strictly increasing
    function entryState(url) {
        lastId = Math.max(Date.now(), lastId + 1);
        return { id: lastId, url: url, title: document.title, container: '#pjax-container' };
    }

    // `href` with its filter state made explicit: dfr=1 when no df_ value and no dfr is on it
    function explicitUrl(href) {
        var u = new URL(href), explicit = false;
        u.searchParams.forEach(function (v, k) { if (k === 'dfr' || (k.indexOf('df_') === 0 && v !== '')) { explicit = true; } });
        if (!explicit) { u.searchParams.set('dfr', '1'); }
        return u.href;
    }

    function pushEntry(url) {
        var current = explicitUrl(window.location.href), st = window.history.state;
        st = (st && st.container) ? $.extend({}, st, { url: current }) : entryState(current);
        window.history.replaceState(st, '', current);
        $.pjax.state = st;
        st = entryState(new URL(url, window.location.href).href);
        window.history.pushState(st, '', st.url);
        $.pjax.state = st;
    }

    // The entry's URL with the selection the page was rendered with (the bar's data-query),
    // when they differ: fetched for back / forward, a URL without filter state is redirected
    // to the remembered selection, and pjax keeps the entry's own URL — the boxes, which take
    // the selection off the URL (dfParams), would show unfiltered data under that bar.
    function alignEntry() {
        var query = $('.exment-df-bar').attr('data-query');
        if (query == null || !window.history.replaceState) { return; }
        if (!changedColumns(window.location.search, '?' + query).length) { return; }
        var href = new URL(pagePath + '?' + query, window.location.href).href, st = window.history.state;
        if (st && st.container) {
            st = $.extend({}, st, { url: href });
            if ($.pjax && $.pjax.state && $.pjax.state.id === st.id) { $.pjax.state = st; }
        }
        window.history.replaceState(st, '', href);
    }

    function navigate(url) {
        $('.exment-df-bar .df-select').each(function () {
            $(this).data('applied', ($(this).val() || []).slice());
            try { $(this).select2('close'); } catch (e) {}
        });
        if (url === window.location.pathname + window.location.search) { return; }
        var $boxes = $('[data-suuid]').filter(function () { return $(this).data('suuid'); });
        var canPartial = $.pjax && window.history && window.history.pushState && $boxes.length
            && $boxes.filter('[data-df-dims]').length === $boxes.length;
        // a pjax render under way (back / forward, a link) is about to replace the page this
        // change was made on: render the change instead — a new pjax request drops that one
        if (!canPartial || rendering()) { fullNavigate(url); return; }
        var changed = changedColumns(window.location.search, url.indexOf('?') >= 0 ? url.slice(url.indexOf('?')) : '');
        pushEntry(url);
        $boxes.each(function () {
            var $box = $(this), suuid = $box.data('suuid'), dims = boxDims($box), hit = [];
            $.each(changed, function (i, column) { if (dims.indexOf(column) >= 0) { hit.push(column); } });
            if (!hit.length) { syncBadge($box); return; }
            // only the chart's own X item changed, and to a pick (not a from / to, which the
            // server filters by): same data, repaint the pick in place (a box mid-fetch
            // reloads instead — its request may predate this URL)
            var hook = (window.ExmentCharts || {})[suuid], picked = hit.length === 1 ? dfValues(hit[0]) : null;
            if (picked !== null && hook && hook.self === hit[0] && typeof hook.highlight === 'function' && !$box.hasClass('loading')) {
                try {
                    hook.highlight(picked);
                    syncBadge($box);
                    return;
                } catch (e) {}
            }
            loadBox(suuid);
        });
        refreshFilterBar(url);
    }

    // Handler of a range bound input: apply($input) once a bound is entered. A number input
    // fires change once left (or on Enter). A date input fires it at every digit typed into
    // its year (2025: 0002, 0020, 0202, 2025): a typed date applies once the input is left
    // (Enter leaves it), a day picked on its calendar at once, as before.
    function rangeEdited(apply) {
        return function (ev) {
            var el = this, $el = $(el);
            if (el.type !== 'date') {
                if (ev.type === 'change') { apply($el); }
                return;
            }
            switch (ev.type) {
                case 'focusin': $el.data('entered', el.value).removeData('typing'); return;
                case 'keydown': $el.data('typing', true); return;
                case 'change': if ($el.data('typing')) { return; } break;
                case 'focusout': $el.removeData('typing'); break;
            }
            if (el.value === $el.data('entered')) { return; }
            $el.data('entered', el.value);
            apply($el);
        };
    }

    // ---- bindings ---------------------------------------------------------------------
    function bind() {
        $(document).off(NS);
        $(window).off(NS);

        // box widgets
        $(document).on('click' + NS, '[data-exment-widget="delete"]', function (ev) {
            var suuid = $(ev.target).closest('[data-suuid]').data('suuid');
            Exment.CommonEvent.ShowSwal(admin_url('dashboardbox/delete/' + suuid), { title: L.delete_confirm, confirm: L.confirm, method: 'delete', cancel: L.cancel });
        });
        $(document).on('click' + NS, '[data-exment-widget="reload"]', function (ev) {
            loadBox($(ev.target).closest('[data-suuid]').data('suuid'));
        });
        $(document).on('click' + NS, '[data-ajax-link]', function (ev) {
            var $link = $(ev.target).closest('[data-ajax-link]');
            loadBox($link.closest('[data-suuid]').data('suuid'), $link.data('ajax-link'));
        });

        // filter bar: a select applies when its dropdown closes (several values picked in one
        // go); a change while it is closed (chip ×) applies at once; a range applies on change
        $(document).on('select2:close' + NS, '.exment-df-bar .df-select', function () {
            if (selectChanged($(this))) { navigate(filterBarUrl()); }
        });
        $(document).on('change' + NS, '.exment-df-bar .df-select', function () {
            if (!$(this).select2('isOpen') && selectChanged($(this))) { navigate(filterBarUrl()); }
        });
        $(document).on('change' + NS + ' focusin' + NS + ' focusout' + NS + ' keydown' + NS, '.exment-df-bar .df-range-input', rangeEdited(function () { navigate(filterBarUrl()); }));
        // リセット: back to the configured defaults (the bar renders their URL query)
        $(document).on('click' + NS, '.exment-df-bar .df-reset', function () {
            navigate(pagePath + '?' + String($(this).closest('.exment-df-bar').attr('data-reset-query') || ''));
        });

        // chart toolbar
        $(document).on('click' + NS, function (ev) {
            var $btn = $(ev.target).closest('[data-ct-pop], [data-cs-pop]');
            if ($btn.length) {
                if ($btn.hasClass('open')) { closePops(); } else { openPop($btn); }
                return;
            }
            if (!$(ev.target).closest('.ct-pop').length) { closePops(); }
        });
        $(document).on('change' + NS, '.exment-ct-switch', function () {
            var suuid = $(this).closest('[data-suuid]').data('suuid'), ct = String($(this).val() || '');
            // picking the box's own type drops the choice: the box follows its setting again
            if (ct && ct !== String($(this).attr('data-configured') || '')) { stateOf(suuid).ct = ct; } else { delete stateOf(suuid).ct; }
            saveToolbar(suuid);
            loadBox(suuid);
        });
        // sort menu (Power BI's "Sort by"): a field row or a direction row applies at once and the
        // box re-renders (menu closed, like Power BI). A direction alone sorts by the measure (the
        // last field); a field alone takes its natural direction — high to low for the measure,
        // A to Z for a text column.
        $(document).on('click' + NS, '[data-cs-field], [data-cs-dir]', function () {
            var $pop = $(this).closest('.cs-pop');
            var field = $(this).data('csField') || $pop.find('[data-cs-field].on').data('csField') || $pop.find('[data-cs-field]').last().data('csField');
            var dir = $(this).data('csDir') || $pop.find('[data-cs-dir].on').data('csDir') || (field === 'y' ? 'desc' : 'asc');
            applySort($(this).closest('[data-suuid]').data('suuid'), field ? field + ':' + dir : '');
        });
        $(document).on('click' + NS, '[data-cd-toggle]', function () {
            var $row = $(this), $box = $row.closest('[data-suuid]'), suuid = $box.data('suuid'), on = displayOf(suuid);
            on[$row.data('cdToggle')] = !on[$row.data('cdToggle')];
            $row.toggleClass('on', !!on[$row.data('cdToggle')]);
            applyDisplay(suuid, on);
        });
        $(document).on('click' + NS, '[data-cd-export]', function () {
            closePops();
            exportCsv($(this).closest('[data-suuid]').data('suuid'));
        });
        $(document).on('click' + NS, '[data-cc-reset]', function () {
            closePops();
            saveColor($(this).closest('[data-suuid]').data('suuid'), { reset: 1 });
        });
        // the color menu closes on a press outside it, Esc, a scroll or a resize
        $(document).on('mousedown' + NS, function (ev) {
            if (!$(ev.target).closest('.cc-pop').length) { closeColorMenu(); }
        });
        $(document).on('keydown' + NS, function (ev) { if (ev.key === 'Escape') { closeColorMenu(); } });
        $(window).on('scroll' + NS + ' resize' + NS, closeColorMenu);
        $(document).on('click' + NS, '.exment-cs-reset', function () {
            var suuid = $(this).closest('[data-suuid]').data('suuid');
            delete stateOf(suuid).cd;
            applySort(suuid, '');
        });
        $(document).on('change' + NS, '.exment-bf-check', function () { reloadFromToolbar($(this), false); });
        $(document).on('change' + NS + ' focusin' + NS + ' focusout' + NS + ' keydown' + NS, '.exment-bf-range', rangeEdited(function ($input) { reloadFromToolbar($input, false); }));
        $(document).on('click' + NS, '.exment-bf-reset', function () { reloadFromToolbar($(this), true); });
        $(document).on('keydown' + NS, '.exment-bf-range, .exment-bf-search, .exment-df-bar .df-range-input', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); $(this).blur(); }
        });
        $(document).on('input' + NS, '.exment-bf-search', function () {
            var q = String($(this).val() || '').toLowerCase();
            $(this).closest('.exment-bf-list').find('label').each(function () {
                var hit = q === '' || $(this).text().toLowerCase().indexOf(q) >= 0 || $(this).find('input').prop('checked');
                $(this).toggleClass('miss', !hit);
            });
        });
    }

    window.ExmentDashboard = {
        // `state` deliberately survives init(): a filter-bar change re-renders the page through
        // pjax, and the boxes must come back with the chart type / chart filter they had
        init: function (options) {
            L = (options && options.lang) || {};
            A = (options && options.assets) || {};
            pagePath = window.location.pathname;
            alignEntry();     // before any box reads the selection off the URL
            closeColorMenu(); // a menu left open on <body> across a pjax render
            barReq++;         // a bar refresh still in flight is the page before's: never swapped in here
            removeSliders();  // the page before's
            watchSliding();
            bind();
            initFilterBar();
            var $boxes = $('[data-suuid]');
            $boxes.parents('.row').addClass('row-eq-height row-dashboard');
            var charts = (options && options.charts) || {}; // suuid => {ct, cs, cd} remembered for this user
            $boxes.each(function () {
                var suuid = $(this).data('suuid'), saved = suuid ? charts[suuid] : null;
                if (saved) {
                    $.each(['ct', 'cs', 'cd'], function (i, k) {
                        if (saved[k] && !stateOf(suuid)[k]) { stateOf(suuid)[k] = saved[k]; }
                    });
                }
                loadBox(suuid);
            });
        },
        load: loadBox,
        pick: pick,
        colorMenu: colorMenu,
        withECharts: withECharts,
        chartError: chartError
    };
})(jQuery);

/**
 * Dashboard SETTING form — the filter bar's items table (FilterBarForm). init() runs on every
 * render of the form (pjax included) with what the page alone cannot know: the two linkage
 * endpoints, the dashboard id and a few texts.
 *
 *   columnsUrl  dashboard/filter_bar_columns ?q=table            → column choices of a table
 *   valuesUrl   dashboard/filter_bar_values ?table&column&dashboard → what the デフォルト値 cell
 *               renders for a column: {kind:'select', options, capped} a picker of the stored
 *               values, {kind:'range', input, min, max} from / to inputs (the data's ends as
 *               placeholders — here empty means "no default", so nothing is prefilled)
 *
 * The デフォルト値 text input keeps carrying the POSTED value (Dashboard::filter_bar_dims); the
 * widget drawn in front of it only writes into it, in the formats FilterBarConfig::defaultQuery
 * reads: picked values joined by ',' for a list column, "from~to" for a range column (either
 * side may be empty).
 */
window.ExmentDashboardForm = (function ($) {
    'use strict';

    var NS = '.exdfform';
    var TABLE = '#has-many-table-filter_bar_dims-table';
    var opt = {};

    function esc(s) { return $('<i>').text(String(s == null ? '' : s)).html(); }
    function rows() { return $(TABLE + ' tbody tr:visible'); }
    function sourceTable() { return $('select[name="filter_bar_table"]').val(); }

    // A row added with "+ new" is cloned from a template rendered before any table was picked:
    // fill its column select from a sibling row, or from the linkage endpoint.
    function fillNewRow(e) {
        if (!$(e.target).closest('.add').length) { return; }
        var empty = rows().last().find('select.column').filter(function () { return !this.value; });
        if (!empty.length) { return; }
        var fill = function (html) { empty.each(function () { $(this).html(html).val('').trigger('change.select2'); }); };
        var loaded = $(TABLE + ' select.column').not(empty).filter(function () { return this.options.length > 1; }).first();
        if (loaded.length) { fill(loaded.html()); return; }
        var table = sourceTable();
        if (!table) { return; }
        $.get(opt.columnsUrl, { q: table }, function (data) {
            var html = '<option value=""></option>';
            $.each(data, function (i, d) { html += '<option value="' + esc(d.id) + '">' + esc(d.text) + '</option>'; });
            fill(html);
        });
    }

    // The widget in front of a row's デフォルト値 input, for the column the row has now.
    function defaultInit(tr) {
        var inp = tr.find('input.default');
        if (!inp.length) { return; }
        var group = inp.closest('.input-group');
        var shell = group.length ? group : inp;
        var old = tr.find('.df-default-picker');
        if (old.length) { try { old.select2('destroy'); } catch (e) {} }
        tr.find('.df-default-picker, .df-default-range').remove();
        shell.show();
        var table = sourceTable(), column = tr.find('select.column').val();
        if (!table || !column) { return; }
        $.get(opt.valuesUrl, { table: table, column: column, dashboard: opt.dashboardId }, function (res) {
            if (tr.find('select.column').val() !== column || tr.find('.df-default-picker, .df-default-range').length) { return; }
            var current = String(inp.val() || '');
            if (res.kind === 'range') {
                // a single exact value stored before the column became a range shows as the
                // range it equals (from = to); several exact values have no range to show
                if (current !== '' && current.indexOf('~') < 0) {
                    if (current.indexOf(',') >= 0) { return; }
                    current = current + '~' + current;
                }
                rangeWidget(shell, inp, res, current);
            } else if (!res.capped) {
                pickerWidget(shell, inp, res, current);
            }
            // a text column too long to list keeps the plain input
        });
    }

    // from / to inputs; the data's ends are the placeholders (an empty side = no bound)
    function rangeWidget(shell, inp, res, current) {
        var L = opt.lang || {}, parts = current.split('~');
        var type = res.input === 'number' ? 'number' : (res.input === 'date' ? 'date' : 'text');
        var box = $('<div class="df-default-range"></div>');
        var from = $('<input class="form-control df-default-bound" data-bound="from">').attr('type', type).attr('placeholder', res.min || L.range_from || '').val($.trim(parts[0] || ''));
        var to = $('<input class="form-control df-default-bound" data-bound="to">').attr('type', type).attr('placeholder', res.max || L.range_to || '').val($.trim(parts[1] || ''));
        box.append(from, $('<span>–</span>'), to);
        shell.hide();
        shell.after(box);
        box.on('input change', 'input', function () {
            var f = $.trim(from.val() || ''), t = $.trim(to.val() || '');
            inp.val(f === '' && t === '' ? '' : f + '~' + t);
        });
    }

    // A list default as it is stored: the values joined by commas, a comma inside a value
    // written "\,", a backslash "\\" (FilterBarConfig::listDefault reads it on the server)
    function joinList(values) {
        return $.map(values, function (v) { return String(v).replace(/\\/g, '\\\\').replace(/,/g, '\\,'); }).join(',');
    }
    function splitList(text) {
        var values = [], current = '';
        for (var i = 0; i < text.length; i++) {
            var c = text.charAt(i), next = text.charAt(i + 1);
            if (c === '\\' && (next === ',' || next === '\\')) { current += next; i++; }
            else if (c === ',') { values.push(current); current = ''; }
            else { current += c; }
        }
        values.push(current);
        return $.map(values, function (v) { v = $.trim(v); return v === '' ? null : v; });
    }

    // a select2 picker of the stored values, so a default is picked by label instead of typed by id
    function pickerWidget(shell, inp, res, current) {
        var selected = splitList(current);
        var sel = $('<select multiple class="df-default-picker form-control" style="width:100%"></select>');
        var seen = {};
        $.each(res.options, function (i, o) { seen[o.id] = true; sel.append($('<option>').val(o.id).text(o.text)); });
        $.each(selected, function (i, v) { if (!seen[v]) { sel.append($('<option>').val(v).text(v)); } });
        shell.hide();
        shell.after(sel);
        sel.val(selected).select2({ width: '100%' });
        sel.on('change', function () { inp.val(joinList(sel.val() || [])); });
    }

    return {
        init: function (options) {
            opt = options || {};
            $(document).off(NS);
            $('#has-many-table-filter_bar_dims').off(NS).on('admin_hasmany_row_change' + NS, fillNewRow);
            rows().each(function () { defaultInit($(this)); });
            // another column on a row, or another source table: the stored default no longer fits
            $(document).on('change' + NS, TABLE + ' select.column', function () {
                var tr = $(this).closest('tr');
                tr.find('input.default').val('');
                defaultInit(tr);
            });
            $(document).on('change' + NS, 'select[name="filter_bar_table"]', function () {
                rows().each(function () { $(this).find('input.default').val(''); defaultInit($(this)); });
            });
        }
    };
})(jQuery);
