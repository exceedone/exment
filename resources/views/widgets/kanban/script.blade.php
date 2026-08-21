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

    // Settings added after a board was first drawn. Filled in once here so
    // every reader below can treat them as always present, instead of each
    // one guarding against a payload built by an older release.
    D.me = D.me || [];
    D.blocked = D.blocked || [];
    D.expedite = D.expedite || [];
    D.policies = D.policies || {};
    D.wip_enforce = D.wip_enforce || 'off';

    /* ---------------------------------------------------------- state ---- */
    var groupBy = D.group_column;
    var swimBy = D.swimlane_column || '';
    var filters = {};
    var keyword = '';
    var onlyOver = false;
    var onlyUnassigned = false;
    var onlyMine = false;
    var onlyBlocked = false;
    var onlyExpedite = false;
    // board columns folded away, per grouping. Remembered per view and per
    // browser: which columns a person needs open is their business, not the
    // view's, and it must not follow them onto somebody else's screen.
    var collapsed = {};
    var STORE_KEY = 'exment-kanban-' + (D.view_suuid || '');
    var sel = {};
    var aiMap = {};
    // How far each board column has been read, in order. Search results are
    // merged in out of order, so counting the cards on the page would make
    // "load more" skip whatever the search happened to bring along.
    var colSeq = {};
    // The same thing per cell, for a board split by the swimlane the view was
    // set up with. Kept alongside rather than instead of the column positions,
    // because the swimlane can be switched off on screen at any moment and the
    // two ways of reading cannot be converted into one another. Whichever is
    // behind only ever asks for records it has seen before, and those are
    // dropped by id - it can never step over one.
    var cellSeq = {};
    function cellKey(lane, colKey) { return lane + '\u0000' + colKey; }
    // a folded column belongs to the grouping it was folded under: regroup and
    // the same key names a different column, or none at all
    function foldKey(colKey) { return groupBy + '\u0000' + colKey; }
    function loadPrefs() {
        if (!D.view_suuid || !window.localStorage) { return; }
        try {
            var saved = JSON.parse(window.localStorage.getItem(STORE_KEY) || '{}') || {};
            $.each(saved.collapsed || [], function (i, k) { collapsed[k] = true; });
            // the board may have lost its assignee column since: a filter that
            // can no longer match anything must not come back switched on
            onlyMine = !!saved.mine && D.me.length > 0 && !!D.assignee_column;
        } catch (ex) {
            // private mode, a full store, or a leftover from an older release
        }
    }
    function savePrefs() {
        if (!D.view_suuid || !window.localStorage) { return; }
        try {
            window.localStorage.setItem(STORE_KEY, JSON.stringify({
                collapsed: Object.keys(collapsed),
                mine: onlyMine
            }));
        } catch (ex) {}
    }

    // keywords already asked of the server, so typing does not ask twice
    var searched = {};
    // per keyword: did the server have to stop before the end of a column?
    var searchCapped = {};
    var searchTimer = null;
    var dragId = null;

    // a card field already showing the assignee makes the footer avatar a
    // duplicate. Fields with an empty value are dropped per card, so every
    // card has to be checked - the first one may simply have no assignee.
    var assigneeInFields = false;
    if (D.assignee_column) {
        $.each(D.cards, function (i, c) {
            $.each(c.fields, function (j, f) {
                if (f.name === D.assignee_column) { assigneeInFields = true; }
            });
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
    // one number printed the way its own column is printed on the data list:
    // same currency mark, same digits, same thousand separators
    function fmtNum(value, spec) {
        var n = Number(value);
        if (isNaN(n)) { return ''; }
        var txt = n.toFixed((spec && spec.digits) ? spec.digits : 0);
        if (spec && spec.group) {
            var parts = txt.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            txt = parts.join('.');
        }
        return ((spec && spec.prefix) || '') + txt + ((spec && spec.suffix) || '');
    }
    // plain counts still deserve their thousand separators once a lane
    // holds four figures
    var GROUPED = { group: true, digits: 0 };
    // whether anything on screen is narrowing the board down. While it is, a
    // figure measured in sql no longer describes what the user is looking at.
    function filtersActive() {
        if (keyword || onlyOver || onlyUnassigned || onlyMine || onlyBlocked || onlyExpedite) { return true; }
        var active = false;
        $.each(filters, function (name, value) { if (value !== '') { active = true; } });
        return active;
    }
    // running total of one number column over the cards of a board column
    function sumOf(items, name) {
        if (!name) { return null; }
        var total = 0, found = false;
        $.each(items, function (i, c) {
            var raw = parseFloat(c.values[name]);
            if (!isNaN(raw)) { total += raw; found = true; }
        });
        return found ? total : null;
    }
    // average days the cards of a board column have been sitting in it: the
    // number that says where the work piles up
    function avgAge(items) {
        var total = 0, n = 0;
        $.each(items, function (i, c) {
            if (!c.entered) { return; }
            var t = parseDt(c.entered).getTime();
            if (isNaN(t)) { return; }
            total += (NOW - t) / 86400000;
            n++;
        });
        return n ? Math.round((total / n) * 10) / 10 : null;
    }

    /* -------------------------------------------------- derived values --- */
    function isDone(c) {
        if (!D.done_keys.length) { return false; }
        return D.done_keys.indexOf(c.values[D.group_column] || '') >= 0;
    }
    // A card can be blocked, or be an interruption, because of a column the
    // board is not grouped by - so each configured value carries the column it
    // came from rather than being read against the board columns.
    function matchesAny(c, list) {
        var hit = false;
        $.each(list, function (i, m) {
            if (hit || !m.column) { return; }
            var v = c.values[m.column];
            if (v === null || v === undefined || v === '') { return; }
            var values = $.isArray(v) ? v : [v];
            $.each(values, function (j, one) {
                if (String(one) === String(m.key)) { hit = true; }
            });
        });
        return hit;
    }
    function isBlocked(c) { return D.blocked.length ? matchesAny(c, D.blocked) : false; }
    function isExpedite(c) { return D.expedite.length ? matchesAny(c, D.expedite) : false; }
    // an assignee field holds one person, or several
    function isMine(c) {
        if (!D.assignee_column || !D.me.length) { return false; }
        var v = c.values[D.assignee_column];
        if (v === null || v === undefined || v === '') { return false; }
        var values = $.isArray(v) ? v : [v];
        var hit = false;
        $.each(values, function (i, one) {
            if (D.me.indexOf(String(one)) >= 0) { hit = true; }
        });
        return hit;
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
        $.each(c.fields, function (i, f) {
            // the label strip and the corner badge already show these columns,
            // so their chip would repeat the same information on the card
            if ((D.labels_column && f.name === D.labels_column) ||
                (D.badge_column && f.name === D.badge_column)) { return; }
            if (f.pos === pos) { html += chipHTML(f); }
        });
        return html;
    }

    /* --------------------------------------------- cover, labels, badge -- */
    function coverHTML(c) {
        if (!D.cover_column || !c.cover) { return ''; }
        return '<div class="kb-cover' + (D.cover_fit === 'contain' ? ' contain' : '') + '">' +
            '<img src="' + esc(c.cover) + '" alt="" loading="lazy"></div>';
    }
    function labelsHTML(c) {
        if (!D.labels_column || !c.labels || !c.labels.length) { return ''; }
        var html = '';
        $.each(c.labels, function (i, l) {
            var color = colorOf(D.labels_column, l.key) || '#95a5a6';
            if (D.labels_style === 'bar') {
                html += '<span class="kb-label-bar" style="background:' + color + '" title="' + esc(l.label) + '"></span>';
            } else {
                html += '<span class="kb-label" style="color:' + color + ';background:' + hexToRgba(color, .12) +
                    ';border-color:' + hexToRgba(color, .4) + '">' + esc(l.label) + '</span>';
            }
        });
        return '<div class="kb-labels">' + html + '</div>';
    }
    function progressHTML(c) {
        if (!D.progress_column) { return ''; }
        var raw = parseFloat(c.values[D.progress_column]);
        if (isNaN(raw)) { return ''; }
        var max = D.progress_max > 0 ? D.progress_max : 100;
        var pct = Math.max(0, Math.min(100, (raw / max) * 100));
        return '<div class="kb-progress' + (pct >= 100 ? ' full' : (pct >= 50 ? ' half' : '')) +
            '" title="' + esc(D.progress_label) + '">' +
            '<span class="kb-progress-bar" style="width:' + pct.toFixed(1) + '%"></span>' +
            '<span class="kb-progress-txt">' + Math.round(pct) + '%</span></div>';
    }
    function badgeHTML(c) {
        var text = D.badge_column ? c.texts[D.badge_column] : '';
        if (!text) { return ''; }
        return '<span class="kb-badge" title="' + esc(D.badge_label) + '">' + esc(text) + '</span>';
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
        var blocked = isBlocked(c);
        var expedite = isExpedite(c);

        var h = '<div class="kb-card ' + cls + (unassigned ? ' unassigned' : '') +
            (expedite ? ' kb-expedite' : '') + (blocked ? ' kb-blocked' : '') + (sel[c.id] ? ' sel' : '') +
            '" draggable="' + (D.editable ? 'true' : 'false') + '" data-id="' + c.id + '">';

        h += coverHTML(c);
        h += labelsHTML(c);

        // Above everything else on the card. Work that has stopped, and work
        // that jumped the queue, are the two things a stand-up has to see
        // before it reads a single title.
        if (expedite || blocked) {
            h += '<div class="kb-card-flags">';
            if (expedite) {
                h += '<span class="kb-mark kb-mark-exp"><i class="fa fa-bolt"></i>' + esc(L.expedite) + '</span>';
            }
            if (blocked) {
                h += '<span class="kb-mark kb-mark-blk"><i class="fa fa-hand-paper-o"></i>' + esc(L.blocked) + '</span>';
            }
            h += '</div>';
        }

        var header = fieldsAt(c, 'header');
        var badge = badgeHTML(c);
        if (D.title_column || header || badge || D.editable) {
            h += '<div class="kb-card-top">';
            if (D.editable) { h += '<span class="kb-handle" title="' + esc(L.drag_hint) + '"><i class="fa fa-bars"></i></span>'; }
            if (D.title_column) { h += '<a href="' + esc(c.url) + '" class="kb-num">' + esc(c.label) + '</a>'; }
            if (header || badge) { h += '<span class="kb-chip-wrap">' + header + badge + '</span>'; }
            h += '</div>';
        }

        h += '<div class="kb-card-title">' + esc(D.title_column ? (c.title || c.label) : c.label) + '</div>';
        h += progressHTML(c);

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
        // the hidden values belong to the column the view was built on: after a
        // re-group they name nothing, so nothing is hidden
        var hidden = (groupBy === D.group_column) ? (D.hide_keys || []) : [];
        var cols = [];
        $.each(meta ? meta.options : [], function (i, o) {
            if (hidden.indexOf(o.key) >= 0) { return; }
            // the limit and the policy were both written against the column
            // the view groups by: after a re-group they name nothing
            var own = (groupBy === D.group_column);
            cols.push({
                key: o.key,
                label: o.label,
                wip: own ? (D.wip[o.key] || 0) : 0,
                policy: own ? (D.policies[o.key] || '') : ''
            });
        });
        // every record always sits on one workflow status, so there is nothing
        // an "unset" column could ever hold
        if (!onWorkflow()) {
            cols.push({ key: EMPTY, label: D.empty_label, wip: 0, policy: '' });
        }
        return cols;
    }
    function colKeyOf(c, keys) {
        var v = c.values[groupBy] || '';
        return (v !== '' && keys[v]) ? v : EMPTY;
    }
    function laneKeyOf(c) { return swimBy ? (c.values[swimBy] || EMPTY) : ''; }
    // which cell a list on the page belongs to, so it can be found again after
    // the board has been rebuilt
    function listKey(el) {
        return cellKey($(el).closest('.kb-swimlane').attr('data-lane') || '', el.dataset.col);
    }
    // Lanes are collected from the cards and from the sql figures, so they
    // arrive in no particular order. Show them the way the column itself is
    // defined - the order the filter box lists, and the order the board
    // columns already use. Anything unknown, then "unset", go last.
    // when the board is split by the very column that marks an interruption,
    // that lane belongs at the top: an expedite nobody looks at first is not
    // being treated as an expedite
    function isExpediteLane(key) {
        if (!swimBy || !D.expedite.length) { return false; }
        var hit = false;
        $.each(D.expedite, function (i, m) {
            if (m.column === swimBy && String(m.key) === String(key)) { hit = true; }
        });
        return hit;
    }
    function sortLanes(list) {
        var meta = metaOf(swimBy);
        var order = {};
        $.each(meta ? meta.options : [], function (i, o) { order[o.key] = i; });
        function rank(k) {
            if (isExpediteLane(k)) { return -1; }
            if (k === EMPTY) { return 2e9; }
            return (order[k] === undefined) ? 1e9 : order[k];
        }
        return list.sort(function (a, b) {
            var d = rank(a) - rank(b);
            if (d !== 0) { return d; }
            return a < b ? -1 : (a > b ? 1 : 0);
        });
    }
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
            if (value === '') { return; }
            // the label strip may carry several values, so any of them is a match -
            // c.values only ever holds the first one
            if (D.labels_column && name === D.labels_column) {
                var hit = false;
                $.each(c.labels || [], function (i, l) { if (l.key === value) { hit = true; } });
                if (!hit) { ok = false; }
                return;
            }
            if ((c.values[name] || '') !== value) { ok = false; }
        });
        if (!ok) { return false; }
        if (onlyOver) {
            var s = slaOf(c);
            if (!s || !s.breach) { return false; }
        }
        if (onlyUnassigned) {
            if (!D.assignee_column || c.values[D.assignee_column]) { return false; }
        }
        if (onlyMine && !isMine(c)) { return false; }
        if (onlyBlocked && !isBlocked(c)) { return false; }
        if (onlyExpedite && !isExpedite(c)) { return false; }
        return true;
    }

    function render() {
        buildAi();
        var shown = [];
        $.each(D.cards, function (i, c) { if (visible(c)) { shown.push(c); } });

        var cols = boardColumns();
        var keys = {};
        $.each(cols, function (i, col) { if (col.key !== EMPTY) { keys[col.key] = true; } });

        var stats = D.col_stats || {};
        var laneStats = D.col_stats_lane || {};
        var colMode = colModeNow();
        var fromDb = colMode && !filtersActive();
        // How full each board column is, straight from the table. Unlike the
        // paging and the per-cell figures, this survives a lane switch: the sql
        // figures follow the board columns of the configured grouping, and a
        // swimlane only rearranges the same records inside them. Set aside
        // while a filter is on - the badge would then contradict the cards
        // drawn under it.
        var loadDb = D.col_count > 0 && groupBy === D.group_column && !filtersActive();

        var lanes = [];
        if (!swimBy) {
            lanes = [''];
        } else {
            // a lane whose cards all sit past the first slice would otherwise
            // vanish from the board, even though its count is known
            if (fromDb) {
                $.each(laneStats, function (k) { lanes.push(k); });
            }
            $.each(shown, function (i, c) {
                var k = laneKeyOf(c);
                if (lanes.indexOf(k) < 0) { lanes.push(k); }
            });
            sortLanes(lanes);
        }

        // The WIP limit is set per board column, so it has to be measured over
        // the whole column even when the board is split: one lane of a column
        // that is over budget looks comfortable on its own, and the limit stops
        // saying anything.
        var colLoad = {};
        if (swimBy) {
            $.each(cols, function (ci, col) {
                var cs = loadDb ? stats[col.key] : null;
                if (cs) { colLoad[col.key] = cs.load; return; }
                var own = $.grep(shown, function (c) { return colKeyOf(c, keys) === col.key; });
                colLoad[col.key] = D.wip_column ? amountSum(own) : own.length;
            });
        }

        var html = '';
        $.each(lanes, function (li, lane) {
            var laneItems = swimBy ? $.grep(shown, function (c) { return laneKeyOf(c) === lane; }) : shown;
            // per cell figures when the board is split, per column when it is not
            var laneStat = swimBy ? (laneStats[lane] || {}) : stats;
            html += '<div class="kb-swimlane' + (isExpediteLane(lane) ? ' kb-expedite-lane' : '') +
                '" data-lane="' + esc(lane) + '">';
            if (swimBy) {
                html += '<div class="kb-lane-head"><i class="fa fa-bars"></i>' + esc(laneLabelOf(lane)) +
                    '<span class="kb-lane-count">' + laneItems.length + '</span></div>';
            }
            html += '<div class="kb-row">';
            $.each(cols, function (ci, col) {
                var items = $.grep(laneItems, function (c) { return colKeyOf(c, keys) === col.key; })
                    .sort(function (a, b) { return (a.rank || 0) - (b.rank || 0); });
                var st = colMode ? laneStat[col.key] : null;
                var load = swimBy ? colLoad[col.key]
                    : ((loadDb && stats[col.key]) ? stats[col.key].load
                        : (D.wip_column ? amountSum(items) : items.length));
                var over = col.wip > 0 && load > col.wip;
                var loadTxt = fmtNum(load, D.wip_column ? D.wip_format : GROUPED);
                var sum = (fromDb && st) ? st.sum : sumOf(items, D.sum_column);
                var age = (fromDb && st) ? st.age : (D.col_age ? avgAge(items) : null);
                var total = (fromDb && st) ? st.total : items.length;

                var folded = !!collapsed[foldKey(col.key)];
                var stuck = 0;
                if (D.blocked.length) {
                    $.each(items, function (i, c) { if (isBlocked(c)) { stuck++; } });
                }

                html += '<div class="kb-col' + (folded ? ' kb-folded' : '') + '" data-col="' + esc(col.key) + '">';
                html += '<div class="kb-col-head' + (over ? ' over-wip' : '') + '">' +
                    '<button type="button" class="kb-fold" title="' +
                        esc(folded ? L.expand : L.collapse) + '"><i class="fa fa-chevron-' +
                        (folded ? 'right' : 'left') + '"></i></button>' +
                    '<span class="kb-col-title">' + esc(col.label) + '</span>' +
                    // the rule of the column, written where the work is done
                    // rather than in a wiki nobody opens twice
                    (col.policy ? '<span class="kb-col-policy" title="' +
                        esc(L.policy_title + ': ' + col.policy) + '"><i class="fa fa-info-circle"></i></span>' : '') +
                    '<span class="kb-col-count">' + items.length + '</span>' +
                    (stuck ? '<span class="kb-col-blocked" title="' + esc(L.blocked) + '">' +
                        '<i class="fa fa-hand-paper-o"></i>' + stuck + '</span>' : '') +
                    (col.wip > 0 ? '<span class="kb-col-wip">' + esc(loadTxt) + '/' + col.wip + '</span>' : '') +
                    '</div>';
                // the total and the average sit under the head, so the WIP
                // badge keeps meaning "cards against the limit". Every column
                // gets the row, empty ones included - one column without it
                // would start its cards higher than all the others.
                if (D.sum_column || D.col_age || colMode) {
                    html += '<div class="kb-col-stats">' +
                        (D.sum_column ? '<span class="kb-col-sum" title="' + esc(D.sum_label) + '">' +
                            esc(fmtNum(sum === null ? 0 : sum, D.sum_format)) + '</span>' : '') +
                        // The whole column, straight from the table - the badge
                        // above only ever counts what was drawn. Dropped while a
                        // filter is on: the figure would then describe records
                        // the board is deliberately not showing.
                        (fromDb ? '<span class="kb-col-total">' +
                            esc(fmt(L.col_total, fmtNum(total, GROUPED))) + '</span>' : '') +
                        (D.col_age ? '<span class="kb-col-age" title="' + esc(L.col_age) + '">' +
                            '<i class="fa fa-hourglass-half"></i>' +
                            (age === null ? '-' : age.toFixed(1) + L.day) + '</span>' : '') +
                        '</div>';
                }
                html += '<div class="kb-list" data-col="' + esc(col.key) + '">';
                if (!items.length) {
                    html += '<div class="kb-empty">' + esc(L.no_card) + '</div>';
                } else {
                    $.each(items, function (i, c) { html += cardHTML(c); });
                }
                html += '</div>';

                // Split by the swimlane the server groups by, each cell is
                // read on its own, so the button belongs to the cell and the
                // cards it fetches land right under it. Unsplit there is one
                // lane and one button per column, as before.
                var here = swimBy ? (cellSeq[cellKey(lane, col.key)] || 0) : (colSeq[col.key] || 0);
                var whole = st ? st.total : 0;
                // What is still unread, counted against the cards on the page
                // rather than against the reading position: a search, or paging
                // the board the other way round, can have brought some of them
                // in already, and a remainder that ignored them would not add up
                // with the badge beside it.
                var left = Math.max(0, whole - items.length);
                if (colMode && D.more_url && (swimBy || li === lanes.length - 1) && whole && here < whole && (!fromDb || left > 0)) {
                    // The remainder counts every record behind the button, so it
                    // says nothing about a board that has been narrowed down -
                    // the same reason the total chip above steps aside. The
                    // button stays: it is the only way to reach what is further
                    // down.
                    var moreTxt = fromDb ? fmt(L.more, fmtNum(left, GROUPED)) : L.more_plain;
                    html += '<div class="kb-more"><button type="button" class="kb-more-btn" data-col="' +
                        esc(col.key) + '" data-lane="' + esc(swimBy ? lane : '') +
                        '" data-offset="' + here + '">' +
                        '<i class="fa fa-angle-double-down"></i>&nbsp;' +
                        esc(moreTxt) + '</button></div>';
                }

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

        // Redrawing replaces every list, and a fresh element starts at the
        // top. Loading one column would otherwise send every other column back
        // to the beginning - including the one the user had just scrolled
        // through to get to the button.
        var scrolled = {};
        $(root).find('.kb-list').each(function () {
            if (this.scrollTop > 0) { scrolled[listKey(this)] = this.scrollTop; }
        });

        $(root).find('.kb-board').html(html);

        $(root).find('.kb-list').each(function () {
            var top = scrolled[listKey(this)];
            if (top) { this.scrollTop = top; }
        });

        renderPartial();
        renderKpi(shown);
        renderBulk();
    }

    // The sql figures follow the board columns of the configured grouping. A
    // swimlane keeps them only when it is the one the server grouped by as
    // well - any other lane cuts the same records a different way, and the
    // server has no figures for it.
    function colModeNow() {
        return D.col_count > 0 && groupBy === D.group_column &&
            (!swimBy || swimBy === D.swimlane_column);
    }

    // A board loaded per column holds the first slice of each column of the
    // grouping the server used - and only that grouping can be paged or
    // totalled. Regroup in the browser and the column totals and the load-more
    // buttons both go, leaving the same slice looking like the whole table:
    // the head counted 1,385 open a moment ago and now counts 150, with
    // nothing on screen to say which one is the truth.
    function renderPartial() {
        var $box = $(root).find('.kb-partial');
        if (!$box.length) { return; }

        var msgs = [];
        if (D.col_count > 0 && !colModeNow()) {
            // the sql figures of the configured grouping still say how big the
            // table is, even when they no longer line up with the columns drawn
            var whole = 0;
            $.each(D.col_stats || {}, function (key, st) { whole += st.total || 0; });
            if (whole > D.cards.length) {
                msgs.push(fmt(L.partial, fmtNum(D.cards.length, GROUPED), fmtNum(whole, GROUPED)));
            }
        }
        // a column that ran out of room kept its first slice and dropped the
        // rest, so the board is answering a narrower question than it was asked
        if (searchCapped[keyword]) {
            msgs.push(fmt(L.search_capped, fmtNum(D.col_count, GROUPED)));
        }

        if (!msgs.length) {
            $box.empty().hide();
            return;
        }
        var html = '';
        $.each(msgs, function (i, m) {
            html += '<div class="alert alert-warning mb-0' + (i ? ' mt-1' : '') + '">' + esc(m) + '</div>';
        });
        $box.html('<div class="box-body pb-0">' + html + '</div>').show();
    }

    /* ----------------------------------------------------------- KPI ----- */
    // counted over the cards on screen: a filtered board whose header still
    // totalled the whole table would contradict its own columns
    function renderKpi(cards) {
        if (!D.features.kpi) { return; }
        var open = 0, done = 0, unassigned = 0, breach = 0, ageSum = 0, ageCnt = 0;

        // Loaded per column, nothing filtered: the page holds a slice of every
        // lane, so counting the cards would understate all five numbers. The
        // same sql pass that fed the column heads answers this too. A swimlane
        // makes no difference here - it rearranges the same records, and these
        // are totals over all of them.
        var fromDb = D.col_count > 0 && groupBy === D.group_column && !filtersActive();
        if (fromDb) {
            $.each(D.col_stats || {}, function (key, st) {
                if (D.done_keys.indexOf(key) >= 0) {
                    done += st.total;
                } else {
                    open += st.total;
                    breach += st.breach || 0;
                    ageSum += st.agesum || 0;
                    ageCnt += st.agen || 0;
                }
                unassigned += st.unassigned || 0;
            });
            ageSum = ageSum / 86400;
        } else {
        $.each(cards || D.cards, function (i, c) {
            if (isDone(c)) { done++; } else { open++; }
            if (D.assignee_column && !c.values[D.assignee_column]) { unassigned++; }
            var s = slaOf(c);
            if (s && s.breach) { breach++; }
            if (!isDone(c)) {
                var a = ageDays(c);
                if (a !== null) { ageSum += a; ageCnt++; }
            }
        });
        }
        var $k = $(root).find('.kb-kpis');
        $k.find('[data-kpi=open] .kb-kpi-num').text(fmtNum(open, GROUPED));
        $k.find('[data-kpi=unassigned] .kb-kpi-num').text(fmtNum(unassigned, GROUPED));
        $k.find('[data-kpi=breach] .kb-kpi-num').text(fmtNum(breach, GROUPED));
        $k.find('[data-kpi=age] .kb-kpi-num').text(ageCnt ? (ageSum / ageCnt).toFixed(1) : '0.0');
        $k.find('[data-kpi=done] .kb-kpi-num').text(fmtNum(done, GROUPED));

        // Counted over the cards drawn, even where the other five come from
        // sql: a blocked value may live in any column the view fancies, and
        // the figures the server sends follow the board columns only. On a
        // board loaded per column that makes this a floor rather than a total,
        // so it is shown as one instead of quietly reading low.
        if (D.blocked.length) {
            var stuck = 0;
            $.each(cards || D.cards, function (i, c) { if (isBlocked(c)) { stuck++; } });
            var whole = 0;
            $.each(D.col_stats || {}, function (key, st) { whole += st.total || 0; });
            var floorOnly = D.col_count > 0 && whole > D.cards.length;
            var $stuckKpi = $k.find('[data-kpi=blocked]');
            $stuckKpi.find('.kb-kpi-num').text((floorOnly ? '\u2265' : '') + fmtNum(stuck, GROUPED));
            if (floorOnly) { $stuckKpi.attr('title', L.blocked_partial); } else { $stuckKpi.removeAttr('title'); }
        }
    }

    // Where the first slice of each column ended. Counted against the
    // grouping the server used, which is the only one it can page by.
    function initColSeq() {
        var meta = metaOf(D.group_column);
        var keys = {};
        $.each(meta ? meta.options : [], function (i, o) { keys[o.key] = true; });
        colSeq = {};
        cellSeq = {};
        $.each(D.cards, function (i, c) {
            var v = c.values[D.group_column] || '';
            var k = (v !== '' && keys[v]) ? v : EMPTY;
            colSeq[k] = (colSeq[k] || 0) + 1;
            // the first slice of a column, cut by lane, is the first slice of
            // each of its cells - the order is the same, only shorter
            if (D.swimlane_column) {
                var ck = cellKey(c.values[D.swimlane_column] || EMPTY, k);
                cellSeq[ck] = (cellSeq[ck] || 0) + 1;
            }
        });
    }

    // The board holds the first slice of every column, so filtering it here can
    // only ever find what already arrived. When it is loaded per column, go and
    // fetch the matches sitting further down as well - they are merged into the
    // same card list and the usual filter decides what to draw.
    function searchServer() {
        if (searchTimer) { window.clearTimeout(searchTimer); searchTimer = null; }
        if (!(D.col_count > 0) || !D.more_url) { return; }
        var q = keyword;
        if (q.length < 2 || searched[q]) { return; }

        searchTimer = window.setTimeout(function () {
            searchTimer = null;
            if (q !== keyword || searched[q]) { return; }
            searched[q] = true;

            var $box = $(root).find('.kb-search, .kb-f-keyword').addClass('kb-searching');
            $.getJSON(D.more_url, { view: D.view_suuid, q: q })
                .done(function (res) {
                    var seen = {}, added = 0;
                    $.each(D.cards, function (i, c) { seen[c.id] = true; });
                    $.each((res && res.cards) || [], function (i, c) {
                        if (!seen[c.id]) { D.cards.push(c); added++; }
                    });
                    searchCapped[q] = !!(res && res.has_more);
                    if (added > 0) {
                        render();
                        toast(fmt(L.search_more, added), 'fa-search');
                    } else {
                        // nothing new to draw, but the answer was still short
                        renderPartial();
                    }
                })
                .always(function () { $box.removeClass('kb-searching'); });
        }, 400);
    }

    /* ---------------------------------------------------------- bulk ----- */
    function selCount() { return Object.keys(sel).length; }
    function renderBulk() {
        if (!D.features.bulk) { return; }
        var n = selCount();
        $(root).find('.kb-bulkbar').css('display', n > 0 ? 'flex' : 'none');
        $(root).find('.kb-sel-count').text(n);

        // The board can be re-grouped by any column. Rebuild the choices from
        // the one it shows, so a bulk change lands where a drag would - not on
        // the column the view was set up with.
        var $move = $(root).find('.kb-bulk-move');
        if ($move.length && $move.data('kbFor') !== groupBy) {
            var meta = metaOf(groupBy);
            var options = '<option value="">' + esc(L.bulk_move) + '</option>';
            $.each(meta ? meta.options : [], function (i, o) {
                options += '<option value="' + esc(o.key) + '">' + esc(o.label) + '</option>';
            });
            $move.html(options).data('kbFor', groupBy);
        }
    }

    /* --------------------------------------------------------- saving ---- */
    function saveValues(id, values) {
        var payload = { _method: 'PUT', value: values };
        return $.ajax({
            url: D.update_url + '/' + id,
            type: 'POST',
            data: payload,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
        }).done(refreshStats);
    }

    // The column figures were measured in sql when the page was built, so the
    // first card the user moves makes every one of them wrong - the totals, the
    // KPI row, the remainder on the button. Read them again rather than adjust
    // them here: the breach count and the average age depend on the column a
    // record sits in, and cannot be corrected card by card.
    var statsTimer = null;
    function refreshStats() {
        if (!(D.col_count > 0) || !D.more_url) { return; }
        // a bulk change saves one record per call: ask once, after the last one
        if (statsTimer) { window.clearTimeout(statsTimer); }
        statsTimer = window.setTimeout(function () {
            statsTimer = null;
            $.getJSON(D.more_url, { view: D.view_suuid, stats: 1 })
                .done(function (res) {
                    if (!res) { return; }
                    D.col_stats = res.flat || {};
                    D.col_stats_lane = res.lanes || {};
                    render();
                });
        }, 400);
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

    // "load more" asks for what the server has not handed over yet, counted
    // per board column. A card that leaves one column for another is one fewer
    // read out of the first and one more out of the second. Takes plain values,
    // so the same call runs it backwards when a save fails or a move is undone.
    function shiftColSeq(fromValue, toValue, card) {
        if (groupBy !== D.group_column) { return; }
        var keys = {};
        $.each(boardColumns(), function (i, col) { if (col.key !== EMPTY) { keys[col.key] = true; } });
        function keyOf(v) { return (v != null && v !== '' && keys[v]) ? v : EMPTY; }
        var from = keyOf(fromValue), to = keyOf(toValue);
        if (from === to) { return; }
        if (colSeq[from]) { colSeq[from]--; }
        colSeq[to] = (colSeq[to] || 0) + 1;
        // it left one cell of its lane for another
        if (!D.swimlane_column || !card) { return; }
        var lane = card.values[D.swimlane_column] || EMPTY;
        if (cellSeq[cellKey(lane, from)]) { cellSeq[cellKey(lane, from)]--; }
        cellSeq[cellKey(lane, to)] = (cellSeq[cellKey(lane, to)] || 0) + 1;
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
    // Would putting this card in that column break its WIP limit? Returns the
    // column label and limit when it would, null when it would not.
    //
    // A limit nobody has to obey is decoration: the board coloured the head red
    // and took the card anyway, which is how a "limit" ends up meaning nothing.
    function wipStop(card, toKey) { return wipStopMany(card ? [card] : [], toKey); }

    function wipStopMany(cards, toKey) {
        if (D.wip_enforce === 'off' || !cards.length || !toKey || toKey === EMPTY) { return null; }

        var cols = boardColumns();
        var keys = {}, target = null;
        $.each(cols, function (i, col) {
            if (col.key !== EMPTY) { keys[col.key] = true; }
            if (col.key === toKey) { target = col; }
        });
        if (!target || target.wip <= 0) { return null; }

        // How much is actually arriving. An interruption is allowed past the
        // limit - that is the whole point of marking one, and it is still
        // counted, so the column stays visibly over. A card already sitting in
        // that column is not an arrival at all.
        var adding = 0;
        $.each(cards, function (i, c) {
            if (isExpedite(c) || colKeyOf(c, keys) === toKey) { return; }
            adding += amountOf(c);
        });
        if (adding <= 0) { return null; }

        // How full the column really is - the same source the badge on its
        // head reads, and read the same way: by the board columns of the
        // configured grouping rather than through colModeNow(), because a
        // swimlane cuts the same records a different way and changes nothing
        // about how full a column is.
        //
        // The one thing the badge steps aside for, a filter, is deliberately
        // ignored here: a limit that a word typed in the search box could talk
        // its way past would not be a limit.
        var st = (D.col_count > 0 && groupBy === D.group_column) ? (D.col_stats || {})[toKey] : null;
        var load = 0;
        if (st) {
            load = st.load;
        } else {
            $.each(D.cards, function (i, c) {
                if (colKeyOf(c, keys) === toKey) { load += amountOf(c); }
            });
        }
        if (load + adding <= target.wip) { return null; }

        return { label: target.label, limit: fmtNum(target.wip, D.wip_column ? D.wip_format : GROUPED) };
    }

    function markDropTargets(id) {
        var card = cardById(id);
        var wfMode = onWorkflow();
        var wipMode = D.wip_enforce === 'block';
        if (!wfMode && !wipMode) { return; }
        $(root).find('.kb-col').each(function () {
            var key = this.dataset.col;
            var ok = true;
            if (wfMode) {
                ok = !card || !card.wf || key === card.wf.status || !!card.wf.moves[key];
            }
            if (ok && wipMode && card && wipStop(card, key)) { ok = false; }
            $(this).toggleClass('kb-nodrop', !ok);
        });
    }
    function clearDropTargets() { $(root).find('.kb-col').removeClass('kb-nodrop'); }

    function moveCard(id, toKey, index) {
        var card = cardById(id);
        if (!card) { return; }

        // The drop is the last moment a WIP limit can still mean anything, and
        // it has to mean the same on both kinds of board - so this sits above
        // the workflow branch rather than inside the column path. Checked here
        // rather than only while dragging, because this is also the call a
        // keyboard or a touch gesture would make.
        var full = wipStop(card, toKey);
        if (full) {
            if (D.wip_enforce === 'block') {
                toast(fmt(L.wip_blocked, full.label, full.limit), 'fa-ban', 'danger');
                render();
                return;
            }
            if (!window.confirm(fmt(L.wip_full, full.label, full.limit))) {
                render();
                return;
            }
        }

        if (onWorkflow()) {
            moveCardWorkflow(id, toKey);
            return;
        }

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
        shiftColSeq(prev, next, card);
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
                shiftColSeq(next, prev, card);
                render();
                saveValues(id, back).fail(function (xhr) {
                    card.values[groupBy] = next;
                    card.texts[groupBy] = labelOfValue(groupBy, next);
                    shiftColSeq(prev, next, card);
                    render();
                    toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
                });
            });
        }).fail(function (xhr) {
            card.values[groupBy] = prev;
            card.texts[groupBy] = labelOfValue(groupBy, prev);
            shiftColSeq(next, prev, card);
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

    /* -------------------------------------------------------- history ---- */
    // asked for one card at a time: a board holds up to a few hundred, and
    // their whole approval trail would double the page for something only the
    // opened card ever shows
    var histCache = {};
    function historyHTML(rows) {
        if (!rows || !rows.length) { return '<div class="kb-hist-none">' + esc(L.history_none) + '</div>'; }
        var html = '<ul class="kb-hist-list">';
        $.each(rows, function (i, r) {
            html += '<li>' +
                '<div class="kb-hist-line"><span class="kb-hist-act">' + esc(r.action || '-') + '</span>' +
                '<span class="kb-hist-at">' + esc(r.at) + '</span></div>' +
                '<div class="kb-hist-flow">' + esc(r.from) + ' <i class="fa fa-long-arrow-right"></i> ' +
                '<b>' + esc(r.to) + '</b>' +
                (r.user ? '<span class="kb-hist-who"><i class="fa fa-user"></i>' + esc(r.user) + '</span>' : '') +
                '</div>' +
                (r.comment ? '<div class="kb-hist-cmt">' + esc(r.comment) + '</div>' : '') +
                '</li>';
        });
        return html + '</ul>';
    }
    function loadHistory(id, $box) {
        if (histCache[id]) { $box.html(historyHTML(histCache[id])); return; }
        $.getJSON(D.data_url + '/' + id + '/kanbanHistory')
            .done(function (res) {
                histCache[id] = (res && res.rows) || [];
                $box.html(historyHTML(histCache[id]));
            })
            .fail(function () { $box.html('<div class="kb-hist-none">' + esc(L.history_none) + '</div>'); });
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
        if (D.cover_column && card.cover) {
            b += '<div class="kb-drawer-cover"><img src="' + esc(card.cover) + '" alt=""></div>';
        }
        if (D.title_column && card.title) { b += '<div class="kb-drawer-title">' + esc(card.title) + '</div>'; }
        b += progressHTML(card);
        var tags = labelsHTML(card) + badgeHTML(card);
        if (tags) { b += '<div class="kb-drawer-tags">' + tags + '</div>'; }
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
                    b += '<button type="button" class="btn btn-sm btn-success kb-wf-btn" data-action="' + esc(a.id) + '"' +
                        // where it lands, so the limit can be read before the modal opens
                        (a.changes ? ' data-to="' + esc(a.to) + '"' : '') + '>' +
                        '<i class="fa fa-check-square"></i>&nbsp;' + esc(a.name) +
                        (a.changes ? '<span class="kb-wf-to">&rarr; ' + esc(labelOfValue(D.group_column, a.to)) + '</span>' : '') +
                        '</button>';
                });
            } else {
                b += '<div class="kb-wf-none">' + esc(card.wf.locked ? L.wf_locked : L.wf_none) + '</div>';
            }
            b += '</div>';
        }

        // A column board has no action buttons, and a touch screen fires no
        // drag events at all - without this the card could not be moved from a
        // phone, a tablet or the keyboard.
        if (!card.wf && D.editable) {
            var moveMeta = metaOf(groupBy);
            if (moveMeta && moveMeta.options.length) {
                var current = card.values[groupBy] || '';
                b += '<div class="kb-move"><label>' + esc(L.move_to) + '</label>' +
                    '<select class="kb-move-sel">';
                $.each(moveMeta.options, function (i, o) {
                    b += '<option value="' + esc(o.key) + '"' + (o.key === current ? ' selected' : '') +
                        '>' + esc(o.label) + '</option>';
                });
                b += '<option value="' + esc(EMPTY) + '"' + (current === '' ? ' selected' : '') +
                    '>' + esc(D.empty_label) + '</option></select></div>';
            }
        }

        if (D.features.history) {
            b += '<div class="kb-hist"><div class="kb-hist-head"><i class="fa fa-history"></i>' +
                esc(L.history) + '</div><div class="kb-hist-body">' +
                '<i class="fa fa-spinner fa-spin"></i></div></div>';
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
        if (D.features.history) { loadHistory(card.id, $drawer.find('.kb-hist-body')); }
        $drawer.find('.kb-ai-apply').on('click', function () {
            applyAi([card.id]);
            closeDrawer();
        });
        $drawer.find('.kb-wf-btn').on('click', function () {
            // An action that moves the card is the same arrival as a drop, so
            // it meets the same limit. An action that leaves the status alone
            // carries no target and is never in its way.
            var to = this.dataset.to || '';
            var full = to ? wipStop(card, to) : null;
            if (full) {
                if (D.wip_enforce === 'block') {
                    toast(fmt(L.wip_blocked, full.label, full.limit), 'fa-ban', 'danger');
                    return;
                }
                if (!window.confirm(fmt(L.wip_full, full.label, full.limit))) { return; }
            }
            openWfModal(card, this.dataset.action);
        });
        $drawer.find('.kb-move-sel').on('change', function () {
            var to = $(this).val();
            closeDrawer();
            // last position of the target column, the same place a drop at the
            // bottom of the list would put it
            moveCard(card.id, to, 999999);
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
    function quickAdd(colKey, text, $input) {
        // A new card is one more card arriving in that column, so the limit has
        // to hold here as well - a full column that can still be typed into is
        // not being limited at all. Nothing but a title is set, so on a board
        // whose limit counts an amount column this adds nothing and never gets
        // in the way.
        var full = wipStop({ values: {}, texts: {} }, colKey);
        if (full) {
            var stop = (D.wip_enforce === 'block');
            if (stop) {
                toast(fmt(L.wip_blocked, full.label, full.limit), 'fa-ban', 'danger');
            }
            if (stop || !window.confirm(fmt(L.wip_full, full.label, full.limit))) {
                // the box was disabled on Enter: give it back, text and all
                if ($input) { $input.prop('disabled', false).focus(); }
                return;
            }
        }

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
            // give the box back, text included, so it can be fixed and resent
            if ($input) { $input.prop('disabled', false).focus(); }
        });
    }

    /* -------------------------------------------------------- controls --- */
    function syncMineBtn() {
        $(root).find('.kb-mine-btn').toggleClass('active', onlyMine);
        $(root).find('.kb-f-mine').prop('checked', onlyMine);
    }
    function setMine(on) {
        onlyMine = !!on;
        syncMineBtn();
        savePrefs();
        render();
    }

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
            html += '<label><input type="checkbox" class="kb-f-over"' +
                (onlyOver ? ' checked' : '') + '>' + esc(L.only_over) + '</label>';
        }
        if (D.assignee_column) {
            html += '<label><input type="checkbox" class="kb-f-unassigned"' +
                (onlyUnassigned ? ' checked' : '') + '>' + esc(L.only_unassigned) + '</label>';
        }
        // left out rather than shown dead when the board cannot tell who is
        // looking: an assignee column holding words has no "me" to match
        if (D.assignee_column && D.me.length) {
            html += '<label><input type="checkbox" class="kb-f-mine"' +
                (onlyMine ? ' checked' : '') + '>' + esc(L.only_mine) + '</label>';
        }
        if (D.blocked.length) {
            html += '<label><input type="checkbox" class="kb-f-blocked"' +
                (onlyBlocked ? ' checked' : '') + '>' + esc(L.only_blocked) + '</label>';
        }
        if (D.expedite.length) {
            html += '<label><input type="checkbox" class="kb-f-expedite"' +
                (onlyExpedite ? ' checked' : '') + '>' + esc(L.only_expedite) + '</label>';
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
            searchServer();
        });
        $root.on('change', '.kb-f-select', function () {
            filters[$(this).data('name')] = $(this).val();
            render();
        });
        $root.on('change', '.kb-f-groupby', function () { groupBy = $(this).val(); render(); });
        $root.on('change', '.kb-f-swim', function () { swimBy = $(this).val(); render(); });
        $root.on('change', '.kb-f-over', function () { onlyOver = this.checked; render(); });
        $root.on('change', '.kb-f-unassigned', function () { onlyUnassigned = this.checked; render(); });
        $root.on('change', '.kb-f-blocked', function () { onlyBlocked = this.checked; render(); });
        $root.on('change', '.kb-f-expedite', function () { onlyExpedite = this.checked; render(); });

        // The one filter worth a button of its own: on a shared board it is
        // the first thing anyone does. Kept in step with the checkbox in the
        // panel so the two can never show different states.
        $root.on('change', '.kb-f-mine', function () { setMine(this.checked); });
        $root.on('click', '.kb-mine-btn', function () { setMine(!onlyMine); });

        $root.on('click', '.kb-reset', function () {
            keyword = ''; filters = {}; onlyOver = false; onlyUnassigned = false;
            onlyMine = false; onlyBlocked = false; onlyExpedite = false;
            groupBy = D.group_column; swimBy = D.swimlane_column || '';
            if (searchTimer) { window.clearTimeout(searchTimer); searchTimer = null; }
            savePrefs();
            buildFilterPanel();
            syncMineBtn();
            $root.find('.kb-search').val('');
            render();
        });

        // Folding is layout, not a filter: reset leaves it alone, and it
        // survives a reload. Nothing about it reaches the saved view.
        $root.on('click', '.kb-fold', function (e) {
            e.stopPropagation();
            var key = foldKey(String($(this).closest('.kb-col').attr('data-col')));
            if (collapsed[key]) { delete collapsed[key]; } else { collapsed[key] = true; }
            savePrefs();
            render();
        });
        $root.on('click', '.kb-ai-btn', autoAssign);

        // one column at a time: the lane that holds thousands of finished
        // records never has to arrive with the rest of the board
        $root.on('click', '.kb-more-btn', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) { return; }
            var key = String($btn.attr('data-col'));
            var lane = String($btn.attr('data-lane') || '');
            var offset = parseInt($btn.attr('data-offset'), 10) || 0;
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');

            $.getJSON(D.more_url, { view: D.view_suuid, key: key, offset: offset, lane: lane })
                .done(function (res) {
                    var cards = (res && res.cards) || [];
                    var seen = {};
                    $.each(D.cards, function (i, c) { seen[c.id] = true; });
                    $.each(cards, function (i, c) {
                        if (!seen[c.id]) { D.cards.push(c); }
                    });
                    // count what the server read past, not what was new here:
                    // a card the search already brought in must not shift it
                    if (lane !== '') {
                        cellSeq[cellKey(lane, key)] = offset + cards.length;
                    } else {
                        colSeq[key] = offset + cards.length;
                        // a slice of a column arrives in column order, so per
                        // lane it is a slice too: every cell it touched has now
                        // been read that much further
                        if (D.swimlane_column) {
                            $.each(cards, function (i, c) {
                                var ck = cellKey(c.values[D.swimlane_column] || EMPTY, key);
                                cellSeq[ck] = (cellSeq[ck] || 0) + 1;
                            });
                        }
                    }
                    render();
                })
                .fail(function (xhr) {
                    $btn.prop('disabled', false);
                    toast(errorMessage(xhr), 'fa-exclamation-triangle', 'danger');
                });
        });

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
            bulkSave(groupBy, value === EMPTY ? '' : value,
                fmt(L.bulk_moved, selCount(), labelOfValue(groupBy, value) || D.empty_label));
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
            // keep the text: on success the board reloads anyway, on failure
            // the user gets it back to fix instead of typing it again
            $(this).prop('disabled', true);
            quickAdd(this.dataset.col, text, $(this));
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

        // A folded column is 44px of head with its list hidden, so there is no
        // list left for a drop to land on. The column itself takes it instead:
        // folding one away is about screen room, not about refusing work.
        $root.on('dragover', '.kb-col.kb-folded', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $(this).addClass('drag-over');
        });
        $root.on('dragleave', '.kb-col.kb-folded', function () { $(this).removeClass('drag-over'); });
        $root.on('drop', '.kb-col.kb-folded', function (e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
            if (!dragId) { return; }
            clearDropTargets();
            // its cards are hidden, so there is no gap to read a position from:
            // the card goes to the end, the way a drop below the last one would
            moveCard(dragId, String(this.dataset.col), 999999);
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

        // twenty cards through a select box is still twenty cards arriving
        if (column === groupBy) {
            var moving = [];
            $.each(ids, function (i, id) {
                var one = cardById(id);
                if (one) { moving.push(one); }
            });
            var full = wipStopMany(moving, value === '' ? EMPTY : value);
            if (full) {
                if (D.wip_enforce === 'block') {
                    toast(fmt(L.wip_blocked, full.label, full.limit), 'fa-ban', 'danger');
                    return;
                }
                if (!window.confirm(fmt(L.wip_full, full.label, full.limit))) { return; }
            }
        }

        var calls = [];
        $.each(ids, function (i, id) {
            var card = cardById(id);
            if (!card) { return; }
            // a bulk change of the board column moves cards the same way a drag does
            if (column === groupBy) { shiftColSeq(card.values[column], value, card); }
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
    initColSeq();
    // before the panel is built, so a remembered "only mine" comes back with
    // its checkbox already ticked instead of filtering behind the user's back
    loadPrefs();
    buildFilterPanel();
    bindControls();
    syncMineBtn();
    render();
});
</script>
