{{--
    Kanban board behaviour.
    The board is drawn here from the json payload built by KanbanGrid, the same
    way the design mockup does it. Drawing client side is what lets re-grouping,
    swimlane switching, filtering, multi select and the drawer work instantly.
--}}
<script type="application/json" id="{{ $boardId }}-data">{!! json_encode($board, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
<script>
$(function () {
    var root = document.getElementById('{{ $boardId }}');
    // pjax can run this twice for the same node
    if (!root || root.dataset.kbBound === '1') {
        return;
    }
    root.dataset.kbBound = '1';

    var D = JSON.parse(document.getElementById('{{ $boardId }}-data').textContent);
    var L = @json($lang);

    var NOW = new Date(String(D.now).replace(' ', 'T')).getTime();
    var EMPTY = D.empty_key;

    /* ---------------------------------------------------------- state ---- */
    var groupBy = D.group_column;
    var swimBy = D.swimlane_column || '';
    var filters = {};
    var keyword = '';
    var onlyOver = false;
    var onlyUnassigned = false;
    var sel = {};
    var aiMap = {};
    var dragId = null;

    // a card field already showing the assignee makes the footer avatar a duplicate
    var assigneeInFields = false;
    if (D.assignee_column) {
        $.each(D.cards.length ? D.cards[0].fields : [], function (i, f) {
            if (f.name === D.assignee_column) { assigneeInFields = true; }
        });
    }

    /* --------------------------------------------------------- helpers --- */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fmt(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(text).replace(/%s/g, function () { return args[i++]; });
    }
    function parseDt(s) { return new Date(String(s).replace(' ', 'T')); }
    function metaOf(name) {
        var found = null;
        $.each(D.groupables, function (i, m) { if (m.name === name) { found = m; } });
        if (!found) {
            $.each(D.filters, function (i, m) { if (m.name === name) { found = m; } });
        }
        return found;
    }
    function colorOf(name, value) { return (D.colors[name] || {})[value] || ''; }
    function firstKeyOf(name) {
        var keys = Object.keys(D.colors[name] || {});
        return keys.length ? keys[0] : null;
    }
    function hexToRgba(hex, a) {
        var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) { return 'rgba(120,130,140,' + a + ')'; }
        var n = parseInt(m[1], 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function avatarColor(text) {
        var s = String(text || ''), n = 0;
        for (var i = 0; i < s.length; i++) { n = (n * 31 + s.charCodeAt(i)) % 100000; }
        var palette = ['#16a085', '#3c8dbc', '#e67e22', '#8e44ad', '#2980b9', '#c0392b', '#27ae60', '#d35400', '#7f8c8d', '#1abc9c'];
        return palette[n % palette.length];
    }
    function avatarHTML(text) {
        if (!text) { return ''; }
        return '<span class="kb-assignee"><span class="kb-av" style="background:' + avatarColor(text) + '">' +
            esc(String(text).charAt(0)) + '</span>' + esc(text) + '</span>';
    }
    function fmtH(h) {
        h = Math.round(h);
        if (h >= 24) {
            var d = Math.floor(h / 24), r = h % 24;
            return d + L.day + (r ? r + 'h' : '');
        }
        return h + 'h';
    }

    /* -------------------------------------------------- derived values --- */
    function isDone(c) {
        if (!D.done_keys.length) { return false; }
        return D.done_keys.indexOf(c.values[D.group_column] || '') >= 0;
    }
    function slaOf(c) {
        if (!D.limit_column) { return null; }
        if (isDone(c)) { return { cls: 'done', txt: L.sla_done, breach: false }; }
        var raw = c.values[D.limit_column];
        if (!raw) { return null; }
        var remain = (parseDt(raw).getTime() - NOW) / 3600000;
        if (remain <= 0) { return { cls: 'breach', txt: fmt(L.sla_over, fmtH(Math.abs(remain))), breach: true }; }
        // hours in a support ticket, days on a delivery date: it is a setting
        if (remain <= D.limit_warn) { return { cls: 'warn', txt: fmt(L.sla_soon, fmtH(remain)), breach: false }; }
        return { cls: 'ok', txt: fmt(L.sla_left, fmtH(remain)), breach: false };
    }
    // how much of the WIP limit one card uses: one card, or its own amount
    function amountOf(c) {
        if (!D.wip_column) { return 1; }
        var raw = parseFloat(c.values[D.wip_column]);
        return isNaN(raw) ? 0 : raw;
    }
    function amountSum(items) {
        var total = 0;
        $.each(items, function (i, c) { total += amountOf(c); });
        return Math.round(total * 100) / 100;
    }
    function ageDays(c) {
        if (!D.age_column) { return null; }
        var raw = c.values[D.age_column];
        if (!raw) { return null; }
        return Math.floor((NOW - parseDt(raw).getTime()) / 86400000);
    }
    function assigneeText(key) {
        var text = '';
        $.each(D.assignees, function (i, o) { if (o.key === key) { text = o.label; } });
        if (text) { return text; }
        $.each(D.cards, function (i, c) {
            if (!text && c.values[D.assignee_column] === key) { text = c.texts[D.assignee_column]; }
        });
        return text || key;
    }

    // "AI" recommendation: for each unassigned card, the person who most often
    // handles the same value of the reference column, and how dominant they are.
    function buildAi() {
        aiMap = {};
        if (!D.features.ai) { return; }
        var stat = {};
        $.each(D.cards, function (i, c) {
            var ref = c.values[D.ai_column] || '';
            var who = c.values[D.assignee_column] || '';
            if (!ref || !who) { return; }
            if (!stat[ref]) { stat[ref] = { total: 0, map: {} }; }
            stat[ref].total++;
            stat[ref].map[who] = (stat[ref].map[who] || 0) + 1;
        });
        $.each(D.cards, function (i, c) {
            if (c.values[D.assignee_column]) { return; }
            var s = stat[c.values[D.ai_column] || ''];
            if (!s) { return; }
            var best = null, bestN = 0;
            $.each(s.map, function (k, n) { if (n > bestN) { bestN = n; best = k; } });
            if (!best) { return; }
            aiMap[c.id] = { key: best, text: assigneeText(best), conf: bestN / s.total };
        });
    }

    /* ---------------------------------------------------------- chips ---- */
    function iconHTML(icon) { return icon ? '<i class="fa ' + esc(icon) + '"></i>' : ''; }

    function chipHTML(f) {
        var color = colorOf(f.name, f.value);
        switch (f.style) {
            case 'text':
                return '<span class="kb-chip-text">' + esc(f.text) + '</span>';
            case 'tag':
                return '<span class="kb-tag">' + esc(f.text) + '</span>';
            case 'pill':
                return '<span class="kb-pill">' + iconHTML(f.icon) + esc(f.text) + '</span>';
            case 'dot':
                return '<span class="kb-prio" style="color:' + (color || '#444') + '">' +
                    '<span class="kb-sq" style="background:' + (color || '#95a5a6') + '"></span>' + esc(f.text) + '</span>';
            case 'lvl':
                return '<span class="kb-lvl" style="color:' + (color || '#444') + '">' +
                    '<span class="kb-cir" style="background:' + (color || '#95a5a6') + '"></span>' + esc(f.text) + '</span>';
            case 'state':
                return '<span class="kb-state" style="color:' + (color || '#5a6b7b') +
                    ';background:' + hexToRgba(color, .13) + ';border-color:' + hexToRgba(color, .35) + '">' + esc(f.text) + '</span>';
            case 'chip':
                return '<span class="kb-chip">' + iconHTML(f.icon) + esc(f.text) + '</span>';
            case 'point':
                return '<span class="kb-point">' + iconHTML(f.icon || 'fa-tachometer') + esc(f.text) + '</span>';
            case 'flag':
                var on = (f.value !== '' && f.value === firstKeyOf(f.name));
                return '<span class="kb-flag ' + (on ? 'on' : 'off') + '"><i class="fa ' +
                    (on ? 'fa-check' : 'fa-times') + '"></i>' + esc(f.text) + '</span>';
            case 'avatar':
                return avatarHTML(f.text);
            case 'icontext':
                return '<span class="kb-icontext">' + iconHTML(f.icon) + esc(f.text) + '</span>';
            default:
                return '<div class="kb-auto"><span class="kb-auto-label">' + esc(f.label) + '</span>' + f.html + '</div>';
        }
    }
    function fieldsAt(c, pos) {
        var html = '';
        $.each(c.fields, function (i, f) { if (f.pos === pos) { html += chipHTML(f); } });
        return html;
    }

    /* ----------------------------------------------------------- card ---- */
    function cardHTML(c) {
        var sla = slaOf(c);
        var age = ageDays(c);
        var cls = '';
        if (age !== null && !isDone(c)) {
            var step = D.age_steps;
            cls = age >= step[2] ? 'kb-age-3' : (age >= step[1] ? 'kb-age-2' : (age >= step[0] ? 'kb-age-1' : ''));
        }
        var unassigned = !!(D.assignee_column && !c.values[D.assignee_column]);

        var h = '<div class="kb-card ' + cls + (unassigned ? ' unassigned' : '') + (sel[c.id] ? ' sel' : '') +
            '" draggable="' + (D.editable ? 'true' : 'false') + '" data-id="' + c.id + '">';

        var header = fieldsAt(c, 'header');
        if (D.title_column || header || D.editable) {
            h += '<div class="kb-card-top">';
            if (D.editable) { h += '<span class="kb-handle" title="' + esc(L.drag_hint) + '"><i class="fa fa-bars"></i></span>'; }
            if (D.title_column) { h += '<a href="' + esc(c.url) + '" class="kb-num">' + esc(c.label) + '</a>'; }
            if (header) { h += '<span class="kb-chip-wrap">' + header + '</span>'; }
            h += '</div>';
        }

        h += '<div class="kb-card-title">' + esc(D.title_column ? (c.title || c.label) : c.label) + '</div>';

        var meta = fieldsAt(c, 'meta');
        if (meta) { h += '<div class="kb-card-meta">' + meta + '</div>'; }

        var meta2 = fieldsAt(c, 'meta2');
        if (meta2 || sla) {
            h += '<div class="kb-card-meta2">' + meta2 +
                (sla ? '<span class="kb-sla ' + sla.cls + '"><i class="fa fa-clock-o"></i>' + esc(sla.txt) + '</span>' : '') + '</div>';
        }

        var foot = fieldsAt(c, 'foot');
        var who = '';
        if (D.assignee_column && !assigneeInFields) {
            if (c.values[D.assignee_column]) {
                who = avatarHTML(c.texts[D.assignee_column]);
            } else {
                who = '<span class="kb-unassigned"><i class="fa fa-user-plus"></i>' + esc(L.unassigned) + '</span>';
                var ai = aiMap[c.id];
                if (ai) {
                    who += '<span class="kb-ai' + (ai.conf < 0.85 ? ' suggested' : '') + '" title="' + esc(L.ai_recommend) + '">' +
                        '<i class="fa fa-magic"></i>' + esc(ai.text) + ' ' + Math.round(ai.conf * 100) + '%</span>';
                }
            }
        }
        if (foot || who) { h += '<div class="kb-card-foot">' + foot + who + '</div>'; }

        h += '</div>';
        return h;
    }

    /* --------------------------------------------------------- board ----- */
    function onWorkflow() { return D.source === 'workflow' && groupBy === D.group_column; }

    function boardColumns() {
        var meta = metaOf(groupBy);
        var cols = [];
        $.each(meta ? meta.options : [], function (i, o) {
            cols.push({ key: o.key, label: o.label, wip: (groupBy === D.group_column ? (D.wip[o.key] || 0) : 0) });
        });
        // every record always sits on one workflow status, so there is nothing
        // an "unset" column could ever hold
        if (!onWorkflow()) {
            cols.push({ key: EMPTY, label: D.empty_label, wip: 0 });
        }
        return cols;
    }
    function colKeyOf(c, keys) {
        var v = c.values[groupBy] || '';
        return (v !== '' && keys[v]) ? v : EMPTY;
    }
    function laneKeyOf(c) { return swimBy ? (c.values[swimBy] || EMPTY) : ''; }
    function laneLabelOf(key) {
        if (key === EMPTY) { return D.empty_label; }
        var label = key;
        var meta = metaOf(swimBy);
        $.each(meta ? meta.options : [], function (i, o) { if (o.key === key) { label = o.label; } });
        return label;
    }

    function visible(c) {
        if (keyword) {
            var q = keyword.toLowerCase();
            var hay = (c.label + ' ' + c.title);
            $.each(c.texts, function (k, v) { hay += ' ' + v; });
            if (hay.toLowerCase().indexOf(q) < 0) { return false; }
        }
        var ok = true;
        $.each(filters, function (name, value) {
            if (value !== '' && (c.values[name] || '') !== value) { ok = false; }
        });
        if (!ok) { return false; }
        if (onlyOver) {
            var s = slaOf(c);
            if (!s || !s.breach) { return false; }
        }
        if (onlyUnassigned) {
            if (!D.assignee_column || c.values[D.assignee_column]) { return false; }
        }
        return true;
    }

    function render() {
        buildAi();
        var shown = [];
        $.each(D.cards, function (i, c) { if (visible(c)) { shown.push(c); } });

        var cols = boardColumns();
        var keys = {};
        $.each(cols, function (i, col) { if (col.key !== EMPTY) { keys[col.key] = true; } });

        var lanes = [];
        if (!swimBy) {
            lanes = [''];
        } else {
            $.each(shown, function (i, c) {
                var k = laneKeyOf(c);
                if (lanes.indexOf(k) < 0) { lanes.push(k); }
            });
            lanes.sort();
        }

        var html = '';
        $.each(lanes, function (li, lane) {
            var laneItems = swimBy ? $.grep(shown, function (c) { return laneKeyOf(c) === lane; }) : shown;
            html += '<div class="kb-swimlane">';
            if (swimBy) {
                html += '<div class="kb-lane-head"><i class="fa fa-bars"></i>' + esc(laneLabelOf(lane)) +
                    '<span class="kb-lane-count">' + laneItems.length + '</span></div>';
            }
            html += '<div class="kb-row">';
            $.each(cols, function (ci, col) {
                var items = $.grep(laneItems, function (c) { return colKeyOf(c, keys) === col.key; })
                    .sort(function (a, b) { return (a.rank || 0) - (b.rank || 0); });
                var load = D.wip_column ? amountSum(items) : items.length;
                var over = col.wip > 0 && load > col.wip;

                html += '<div class="kb-col" data-col="' + esc(col.key) + '">';
                html += '<div class="kb-col-head' + (over ? ' over-wip' : '') + '">' +
                    '<span class="kb-col-title">' + esc(col.label) + '</span>' +
                    '<span class="kb-col-count">' + items.length + '</span>' +
                    (col.wip > 0 ? '<span class="kb-col-wip">' + load + '/' + col.wip + '</span>' : '') +
                    '</div>';
                html += '<div class="kb-list" data-col="' + esc(col.key) + '">';
                if (!items.length) {
                    html += '<div class="kb-empty">' + esc(L.no_card) + '</div>';
                } else {
                    $.each(items, function (i, c) { html += cardHTML(c); });
                }
                html += '</div>';
                // on a workflow board a new record can only appear on the start status
                var canQuickAdd = D.features.quickadd && D.label_column && groupBy === D.group_column &&
                    col.key !== EMPTY && (!onWorkflow() || col.key === D.workflow_start);
                if (canQuickAdd) {
                    html += '<div class="kb-quickadd"><input type="text" class="kb-quick" data-col="' + esc(col.key) +
                        '" placeholder="' + esc(L.quickadd) + '"></div>';
                }
                html += '</div>';
            });
            html += '</div></div>';
        });

        $(root).find('.kb-board').html(html);
        renderKpi();
        renderBulk();
    }

    /* ----------------------------------------------------------- KPI ----- */
    function renderKpi() {
        if (!D.features.kpi) { return; }
        var open = 0, done = 0, unassigned = 0, breach = 0, ageSum = 0, ageCnt = 0;
        $.each(D.cards, function (i, c) {
            if (isDone(c)) { done++; } else { open++; }
            if (D.assignee_column && !c.values[D.assignee_column]) { unassigned++; }
            var s = slaOf(c);
            if (s && s.breach) { breach++; }
            if (!isDone(c)) {
                var a = ageDays(c);
                if (a !== null) { ageSum += a; ageCnt++; }
            }
        });
        var $k = $(root).find('.kb-kpis');
        $k.find('[data-kpi=open] .kb-kpi-num').text(open);
        $k.find('[data-kpi=unassigned] .kb-kpi-num').text(unassigned);
        $k.find('[data-kpi=breach] .kb-kpi-num').text(breach);
        $k.find('[data-kpi=age] .kb-kpi-num').text(ageCnt ? (ageSum / ageCnt).toFixed(1) : '0.0');
        $k.find('[data-kpi=done] .kb-kpi-num').text(done);
    }

    /* ---------------------------------------------------------- bulk ----- */
    function selCount() { return Object.keys(sel).length; }
    function renderBulk() {
        if (!D.features.bulk) { return; }
        var n = selCount();
        $(root).find('.kb-bulkbar').css('display', n > 0 ? 'flex' : 'none');
        $(root).find('.kb-sel-count').text(n);
    }

    /* --------------------------------------------------------- saving ---- */
    function saveValues(id, values) {
        var payload = { _method: 'PUT', value: values };
        return $.ajax({
            url: D.update_url + '/' + id,
            type: 'POST',
            data: payload,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
        });
    }
    // the webapi answers a rejected save with {errors:[{column: "message"}]},
    // so show that instead of a generic failure whenever it is there
    function errorMessage(xhr) {
        var json = xhr.responseJSON;
        if (!json) { return L.save_error; }
        if (json.message) { return json.message; }
        var messages = [];
        $.each(json.errors || [], function (i, row) {
            $.each(row, function (column, text) { messages.push(text); });
        });
        return messages.length ? messages.join(' ') : L.save_error;
    }
    function cardById(id) {
        var found = null;
        $.each(D.cards, function (i, c) { if (String(c.id) === String(id)) { found = c; } });
        return found;
    }
    function reloadBoard() {
        if ($.pjax && $('#pjax-container').length) {
            $.pjax.reload({ container: '#pjax-container' });
        } else {
            location.reload();
        }
    }

    /* ---------------------------------------------------------- toast ---- */
    var toastTimer = null;
    function toast(msg, icon, kind, undoFn) {
        var $t = $('#kb-toast');
        if (!$t.length) { $t = $('<div id="kb-toast"></div>').appendTo(document.body); }
        $t.attr('class', 'kb-toast ' + (kind || ''));
        $t.html('<i class="fa ' + (icon || 'fa-info-circle') + '"></i><span class="kb-toast-msg">' + esc(msg) + '</span>' +
            (undoFn ? '<span class="kb-undo">' + esc(L.undo) + '</span>' : ''));
        $t.show();
        if (undoFn) {
            $t.find('.kb-undo').on('click', function () { $t.hide(); undoFn(); });
        }
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { $t.hide(); }, 6000);
    }

    /* ----------------------------------------------------------- move ---- */
    function reorderInColumn(card, colKey, index) {
        var keys = {};
        $.each(boardColumns(), function (i, col) { if (col.key !== EMPTY) { keys[col.key] = true; } });
        var items = $.grep(D.cards, function (c) { return c !== card && colKeyOf(c, keys) === colKey; })
            .sort(function (a, b) { return (a.rank || 0) - (b.rank || 0); });
        if (index < 0 || index > items.length) { index = items.length; }
        items.splice(index, 0, card);
        $.each(items, function (i, c) { c.rank = i; });
    }

    /* -------------------------------------------------------- workflow --- */
    // A workflow board never writes a column. It runs the workflow action that
    // leads to the target status, through the same modal the record screen uses,
    // so authority, conditions, comment, next assignee and notify all behave the
    // same. A status with no action from here is simply not a valid drop.
    function moveCardWorkflow(id, toKey) {
        var card = cardById(id);
        if (!card || !card.wf) { return; }
        if (toKey === card.wf.status || toKey === EMPTY) { return; }

        var actionId = card.wf.moves[toKey];
        if (!actionId) {
            toast(fmt(L.wf_no_action, card.label, labelOfValue(D.group_column, toKey)), 'fa-ban', 'danger');
            return;
        }
        openWfModal(card, actionId);
    }

    function openWfModal(card, actionId) {
        var url = D.data_url + '/' + card.id + '/actionModal';
        if (window.Exment && Exment.ModalEvent && Exment.ModalEvent.ShowModal) {
            destroyDrawer();
            Exment.ModalEvent.ShowModal($('<a></a>'), url, { action_id: actionId });
            return;
        }
        // no Exment js on the page: fall back to the record screen
        location.href = card.url;
    }

    // grey out the statuses this card may not reach, while it is being dragged
    function markDropTargets(id) {
        if (!onWorkflow()) { return; }
        var card = cardById(id);
        $(root).find('.kb-col').each(function () {
            var key = this.dataset.col;
            var ok = !card || !card.wf || key === card.wf.status || !!card.wf.moves[key];
            $(this).toggleClass('kb-nodrop', !ok);
        });
    }
    function clearDropTargets() { $(root).find('.kb-col').removeClass('kb-nodrop'); }

    function moveCard(id, toKey, index) {
        if (onWorkflow()) {
            moveCardWorkflow(id, toKey);
            return;
        }

        var card = cardById(id);
        if (!card) { return; }
        var prev = card.values[groupBy] || '';
        var next = (toKey === EMPTY) ? '' : toKey;
        if (prev === next) {
            reorderInColumn(card, toKey, index);
            render();
            return;
        }
        if (!D.editable) { return; }

        // move first so the board feels instant, roll back if the save fails
        card.values[groupBy] = next;
        card.texts[groupBy] = labelOfValue(groupBy, next);
        reorderInColumn(card, toKey, index);
        render();

        var values = {};
        values[groupBy] = next;
        saveValues(id, values).done(function () {
            toast(fmt(L.moved, card.label, labelOfValue(groupBy, next) || D.empty_label), 'fa-arrow-circle-right', '', function () {
                var back = {};
                back[groupBy] = prev;
                card.values[groupBy] = prev;
                card.texts[groupBy] = labelOfValue(groupBy, prev);
                render();
                saveValues(id, back).fail(function (xhr) {
                    card.values[groupBy] = next;
                    card.texts[groupBy] = labelOfValue(groupBy, next);
                    render();
                    toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
                });
            });
        }).fail(function (xhr) {
            card.values[groupBy] = prev;
            card.texts[groupBy] = labelOfValue(groupBy, prev);
            render();
            toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
        });
    }

    function labelOfValue(name, value) {
        if (value === '' || value == null) { return ''; }
        var label = value;
        var meta = metaOf(name);
        $.each(meta ? meta.options : [], function (i, o) { if (o.key === value) { label = o.label; } });
        return label;
    }

    /* --------------------------------------------------------- drawer ---- */
    function openDrawer(id) {
        var card = cardById(id);
        if (!card) { return; }

        var $drawer = $('#kb-drawer');
        if (!$drawer.length) {
            $('<div id="kb-backdrop" class="kb-backdrop"></div>').appendTo(document.body);
            $drawer = $('<div id="kb-drawer" class="kb-drawer">' +
                '<div class="kb-drawer-head"><a href="#" class="kb-num kb-drawer-num"></a>' +
                '<button type="button" class="kb-drawer-close">&times;</button></div>' +
                '<div class="kb-drawer-body"></div>' +
                '<div class="kb-drawer-foot"><a href="#" class="btn btn-sm btn-primary kb-drawer-open">' +
                '<i class="fa fa-external-link"></i>&nbsp;' + esc(L.open_record) + '</a></div>' +
                '</div>').appendTo(document.body);
            $('#kb-backdrop, .kb-drawer-close').on('click', closeDrawer);
            // the drawer lives on <body>, so leaving the page would leave it
            // hanging over the next screen unless it is closed here
            $drawer.on('click', 'a[href]', closeDrawer);
        }

        $drawer.find('.kb-drawer-num').text(card.label).attr('href', card.url);
        $drawer.find('.kb-drawer-open').attr('href', card.url);

        var b = '';
        if (D.title_column && card.title) { b += '<div class="kb-drawer-title">' + esc(card.title) + '</div>'; }
        b += '<dl class="kb-fields">';
        b += '<dt>' + esc(labelOfColumn(D.group_column)) + '</dt><dd>' +
            esc(card.texts[D.group_column] || D.empty_label) + '</dd>';
        $.each(card.fields, function (i, f) {
            b += '<dt>' + esc(f.label) + '</dt><dd>' + (f.style === 'auto' ? f.html : chipHTML(f)) + '</dd>';
        });
        b += '</dl>';

        // every action the record screen would offer, including the ones a drag
        // cannot express: a reject, or an approval that still needs other people
        if (card.wf) {
            b += '<div class="kb-wf"><div class="kb-wf-head"><i class="fa fa-code-fork"></i>' + esc(L.wf_action) + '</div>';
            if (card.wf.actions.length) {
                $.each(card.wf.actions, function (i, a) {
                    b += '<button type="button" class="btn btn-sm btn-success kb-wf-btn" data-action="' + esc(a.id) + '">' +
                        '<i class="fa fa-check-square"></i>&nbsp;' + esc(a.name) +
                        (a.changes ? '<span class="kb-wf-to">&rarr; ' + esc(labelOfValue(D.group_column, a.to)) + '</span>' : '') +
                        '</button>';
                });
            } else {
                b += '<div class="kb-wf-none">' + esc(card.wf.locked ? L.wf_locked : L.wf_none) + '</div>';
            }
            b += '</div>';
        }

        var ai = aiMap[card.id];
        if (ai) {
            var pct = Math.round(ai.conf * 100);
            b += '<div class="kb-ai-rec">' +
                '<div class="kb-ai-rec-head"><i class="fa fa-magic"></i>' + esc(L.ai_recommend) + '</div>' +
                '<div class="kb-ai-rec-who"><span class="kb-av" style="background:' + avatarColor(ai.text) + '">' +
                esc(String(ai.text).charAt(0)) + '</span><b>' + esc(ai.text) + '</b>' +
                '<span class="kb-ai" style="margin-left:auto">' + pct + '%</span></div>' +
                '<div class="kb-ai-meter"><span style="width:' + pct + '%"></span></div>' +
                '<div class="kb-ai-hint">' + esc(ai.conf >= 0.85 ? L.ai_auto : L.ai_suggest) + '</div>' +
                (D.editable ? '<button type="button" class="btn btn-primary btn-sm kb-ai-apply" data-id="' + card.id +
                    '"><i class="fa fa-check"></i>&nbsp;' + esc(L.ai_apply) + '</button>' : '') +
                '</div>';
        }

        $drawer.find('.kb-drawer-body').html(b);
        $drawer.find('.kb-ai-apply').on('click', function () {
            applyAi([card.id]);
            closeDrawer();
        });
        $drawer.find('.kb-wf-btn').on('click', function () {
            openWfModal(card, this.dataset.action);
        });

        $('#kb-backdrop').show();
        setTimeout(function () { $('#kb-backdrop').addClass('show'); $drawer.addClass('show'); }, 10);
    }
    function closeDrawer() {
        $('#kb-drawer').removeClass('show');
        $('#kb-backdrop').removeClass('show');
        setTimeout(function () { $('#kb-backdrop').hide(); }, 200);
    }
    // The drawer, the backdrop and the toast are attached to <body>, which pjax
    // never replaces. Without this they survive a move to the record screen and
    // sit on top of it.
    function destroyDrawer() {
        $('#kb-drawer').remove();
        $('#kb-backdrop').remove();
        $('#kb-toast').remove();
    }
    $(document).off('pjax:send.exmentkanban').on('pjax:send.exmentkanban', destroyDrawer);
    $(window).off('beforeunload.exmentkanban').on('beforeunload.exmentkanban', destroyDrawer);
    function labelOfColumn(name) {
        var meta = metaOf(name);
        return meta ? meta.label : name;
    }

    /* ------------------------------------------------------------- AI ---- */
    function applyAi(ids, message) {
        var targets = [];
        $.each(ids, function (i, id) {
            var ai = aiMap[id];
            if (ai) { targets.push({ id: id, ai: ai }); }
        });
        if (!targets.length) { return; }

        var calls = [];
        $.each(targets, function (i, t) {
            var values = {};
            values[D.assignee_column] = t.ai.key;
            var card = cardById(t.id);
            card.values[D.assignee_column] = t.ai.key;
            card.texts[D.assignee_column] = t.ai.text;
            calls.push(saveValues(t.id, values));
        });
        render();

        $.when.apply($, calls).done(function () {
            toast(message || fmt(L.assigned, targets.length), 'fa-user-plus', 'success');
        }).fail(function (xhr) {
            toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
            reloadBoard();
        });
    }
    function autoAssign() {
        // only act on the confident ones; the rest stay as a visible suggestion
        var auto = [], suggest = 0;
        $.each(aiMap, function (id, ai) {
            if (ai.conf >= 0.85) { auto.push(id); } else { suggest++; }
        });
        if (!auto.length) {
            toast(fmt(L.ai_result, 0, suggest), 'fa-magic', '');
            return;
        }
        applyAi(auto, fmt(L.ai_result, auto.length, suggest));
    }

    /* ------------------------------------------------------- quick add --- */
    function quickAdd(colKey, text) {
        var values = {};
        values[D.label_column] = text;
        // a workflow status is not a column: a new record starts at the start
        // status on its own, there is nothing to send
        if (colKey !== EMPTY && D.source !== 'workflow') { values[D.group_column] = colKey; }

        $.ajax({
            url: D.create_url,
            type: 'POST',
            data: { value: values },
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
        }).done(function () {
            toast(fmt(L.created, text), 'fa-plus-circle', 'success');
            reloadBoard();
        }).fail(function (xhr) {
            toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
        });
    }

    /* -------------------------------------------------------- controls --- */
    function buildFilterPanel() {
        var html = '';
        html += '<div class="kb-f"><label>' + esc(L.keyword) + '</label>' +
            '<input type="text" class="kb-f-keyword" placeholder="' + esc(L.search) + '"></div>';
        $.each(D.filters, function (i, m) {
            html += '<div class="kb-f"><label>' + esc(m.label) + '</label><select class="kb-f-select" data-name="' + esc(m.name) + '">' +
                '<option value="">' + esc(L.all) + '</option>';
            $.each(m.options, function (j, o) {
                html += '<option value="' + esc(o.key) + '">' + esc(o.label) + '</option>';
            });
            html += '</select></div>';
        });
        html += '<div class="kb-f"><label>' + esc(L.groupby) + '</label><select class="kb-f-groupby">';
        $.each(D.groupables, function (i, m) {
            html += '<option value="' + esc(m.name) + '"' + (m.name === groupBy ? ' selected' : '') + '>' + esc(m.label) + '</option>';
        });
        html += '</select></div>';
        html += '<div class="kb-f"><label>' + esc(L.swimlane) + '</label><select class="kb-f-swim">' +
            '<option value="">' + esc(L.none) + '</option>';
        $.each(D.groupables, function (i, m) {
            html += '<option value="' + esc(m.name) + '"' + (m.name === swimBy ? ' selected' : '') + '>' + esc(m.label) + '</option>';
        });
        html += '</select></div>';

        html += '<div class="kb-filter-checks">';
        if (D.limit_column) {
            html += '<label><input type="checkbox" class="kb-f-over">' + esc(L.only_over) + '</label>';
        }
        if (D.assignee_column) {
            html += '<label><input type="checkbox" class="kb-f-unassigned">' + esc(L.only_unassigned) + '</label>';
        }
        html += '<a href="javascript:void(0);" class="btn btn-sm btn-default kb-reset"><i class="fa fa-undo"></i>&nbsp;' +
            esc(L.reset) + '</a></div>';

        $(root).find('.kb-filterbox').html(html);
    }

    function bindControls() {
        var $root = $(root);

        $root.on('click', '.kb-filter-toggle', function () { $root.find('.kb-filterbox').toggle(); });

        $root.on('input', '.kb-search, .kb-f-keyword', function () {
            keyword = $(this).val().trim();
            $root.find('.kb-search, .kb-f-keyword').not(this).val(keyword);
            render();
        });
        $root.on('change', '.kb-f-select', function () {
            filters[$(this).data('name')] = $(this).val();
            render();
        });
        $root.on('change', '.kb-f-groupby', function () { groupBy = $(this).val(); render(); });
        $root.on('change', '.kb-f-swim', function () { swimBy = $(this).val(); render(); });
        $root.on('change', '.kb-f-over', function () { onlyOver = this.checked; render(); });
        $root.on('change', '.kb-f-unassigned', function () { onlyUnassigned = this.checked; render(); });
        $root.on('click', '.kb-reset', function () {
            keyword = ''; filters = {}; onlyOver = false; onlyUnassigned = false;
            groupBy = D.group_column; swimBy = D.swimlane_column || '';
            buildFilterPanel();
            $root.find('.kb-search').val('');
            render();
        });
        $root.on('click', '.kb-ai-btn', autoAssign);

        // ---- card click / selection
        $root.on('click', '.kb-card', function (e) {
            if ($(this).hasClass('dragging')) { return; }
            if (e.target.tagName === 'A') { return; }
            var id = this.dataset.id;
            if (D.features.bulk && (e.shiftKey || e.ctrlKey)) {
                e.preventDefault();
                if (sel[id]) { delete sel[id]; } else { sel[id] = true; }
                render();
                return;
            }
            if (D.features.drawer) { openDrawer(id); }
            else {
                var card = cardById(id);
                if (card) { location.href = card.url; }
            }
        });

        // ---- bulk bar
        $root.on('change', '.kb-bulk-move', function () {
            var value = $(this).val();
            if (value === '') { return; }
            $(this).val('');
            bulkSave(D.group_column, value === EMPTY ? '' : value,
                fmt(L.bulk_moved, selCount(), labelOfValue(D.group_column, value) || D.empty_label));
        });
        $root.on('change', '.kb-bulk-assign', function () {
            var value = $(this).val();
            if (value === '') { return; }
            $(this).val('');
            bulkSave(D.assignee_column, value, fmt(L.assigned, selCount()));
        });
        $root.on('click', '.kb-sel-clear', function () { sel = {}; render(); });

        // ---- quick add
        $root.on('keydown', '.kb-quick', function (e) {
            if (e.key !== 'Enter' && e.keyCode !== 13) { return; }
            var text = $(this).val().trim();
            if (!text) { return; }
            $(this).val('').prop('disabled', true);
            quickAdd(this.dataset.col, text);
        });

        // ---- drag & drop
        $root.on('dragstart', '.kb-card', function (e) {
            dragId = this.dataset.id;
            $(this).addClass('dragging');
            var dt = e.originalEvent.dataTransfer;
            dt.effectAllowed = 'move';
            try { dt.setData('text/plain', dragId); } catch (ex) {}
            markDropTargets(dragId);
        });
        $root.on('dragend', '.kb-card', function () {
            var card = this;
            clearDropTargets();
            // the click handler runs after dragend, so drop the flag a tick later
            setTimeout(function () { $(card).removeClass('dragging'); }, 0);
        });
        $root.on('dragover', '.kb-list', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $(this).addClass('drag-over');
        });
        $root.on('dragleave', '.kb-list', function () { $(this).removeClass('drag-over'); });
        $root.on('drop', '.kb-list', function (e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
            if (!dragId) { return; }
            clearDropTargets();
            moveCard(dragId, this.dataset.col, insertionIndex(this, e.originalEvent.clientY));
            dragId = null;
        });
    }

    function insertionIndex(list, y) {
        var cards = $(list).find('.kb-card').not('.dragging').toArray();
        for (var i = 0; i < cards.length; i++) {
            var r = cards[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) { return i; }
        }
        return cards.length;
    }

    function bulkSave(column, value, message) {
        var ids = Object.keys(sel);
        if (!ids.length || !column) { return; }
        var calls = [];
        $.each(ids, function (i, id) {
            var card = cardById(id);
            if (!card) { return; }
            card.values[column] = value;
            card.texts[column] = labelOfValue(column, value);
            var values = {};
            values[column] = value;
            calls.push(saveValues(id, values));
        });
        sel = {};
        render();
        $.when.apply($, calls).done(function () {
            toast(message, 'fa-exchange', 'success');
        }).fail(function (xhr) {
            toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
            reloadBoard();
        });
    }

    /* ------------------------------------------------------------ init --- */
    buildFilterPanel();
    bindControls();
    render();
});
</script>
