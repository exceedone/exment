/**
 * ワークフロー デザイナー（/admin/workflow/{id}/design）。
 *
 * 方針
 *  - フロー図の上でステータスとアクションを直接作る・直す・消す。
 *    保存先はステップ1・ステップ2とまったく同じテーブルなので、どちらから
 *    編集しても結果は同じになる（デザイナー専用のデータは持たない）。
 *  - ノードの座標は workflows.options.designer_layout に入れる。ステップ2の
 *    フロー プレビュー（workflow_designer.js）が読むキーと同じなので、
 *    両方の画面で並びが一致する。
 *  - 保存は ajax。送信内容はまるごと JSON 文字列 1 個にして送る
 *    （深い配列を form 形式で送ると max_input_vars に当たるため）。
 *
 * 注意
 *  - ステータスの参照は必ず「キー」（既存＝s+ID、新規＝n1, n2…）で行う。
 *    生の ID を混ぜると、新規ステータスを保存した後につなぎ先が迷子になる。
 *  - select2 の候補一覧は body 直下に出る。モーダルより後ろに隠れないよう、
 *    開いている間だけ body.wfd-modal-open を付けて CSS で前に出している。
 */
var Exment;
(function (Exment) {
    'use strict';

    /* =====================================================================
     * 定数
     * ===================================================================== */

    var NODE_W = 160;
    var NODE_H = 58;
    var START_W = 112;
    var START_H = 44;
    var WORLD_W = 6000;
    var WORLD_H = 4000;
    var GRID = 10;

    var START = 'start';
    var TYPE_FIX = 'fix';
    var TYPE_ACTION_SELECT = 'action_select';
    var TYPE_GET_BY_USERINFO = 'get_by_userinfo';

    // 実行可能ユーザーの内訳。キーは WorkflowAuthority.related_type と同じ。
    var TARGET_KEYS = ['user', 'organization', 'column', 'system', 'login_user_column'];
    // 種別ごとに、どの内訳を入力させるか（ステップ2の targetModal と同じ）
    var TARGET_VISIBLE = {
        fix: ['user', 'organization', 'column', 'system'],
        get_by_userinfo: ['login_user_column'],
        action_select: []
    };

    /* =====================================================================
     * 状態
     * ===================================================================== */

    var D = null;        // サーバーから受け取ったそのままのデータ
    var S = null;        // 画面で編集している状態
    var T = {};          // 文言
    var els = {};        // DOM への参照
    var panX = 0, panY = 0, scale = 1;
    var sel = null;      // {type:'node'|'edge', key}
    var drag = null;
    var issueList = [];
    var seq = 0;         // 新規キーの採番
    var baseSig = '';    // 最後に保存した時点の内容
    var saving = false;
    var toastTimer = null;
    var booted = false;

    /* =====================================================================
     * 小物
     * ===================================================================== */

    function $id(id) {
        return document.getElementById(id);
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** :name のような置き換え付きの文言取得。キーが無ければキーをそのまま返す。 */
    function t(key, params) {
        var s = T[key];
        if (s === undefined || s === null) {
            s = key;
        }
        s = String(s);
        if (params) {
            Object.keys(params).forEach(function (k) {
                s = s.split(':' + k).join(String(params[k]));
            });
        }
        return s;
    }

    /** PHP の max:30 は文字数なので、サロゲートペアを 1 文字として数える */
    function clen(str) {
        var n = 0, i, c;
        str = String(str);
        for (i = 0; i < str.length; i++) {
            n++;
            c = str.charCodeAt(i);
            if (c >= 0xD800 && c <= 0xDBFF) {
                i++;
            }
        }
        return n;
    }

    function snap(v) {
        return Math.round(v / GRID) * GRID;
    }

    function nextKey(prefix) {
        seq++;
        return prefix + seq;
    }

    function alive() {
        return !!els.app && document.body.contains(els.app);
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content');
        }
        return (typeof LA !== 'undefined' && LA.token) ? LA.token : '';
    }

    /* =====================================================================
     * 状態の組み立て
     * ===================================================================== */

    /** DB の値（ID or 'start'）を画面のキーに直す */
    function keyOfStatusId(v) {
        if (v === null || v === undefined || v === '' || v === START) {
            return START;
        }
        return 's' + v;
    }

    function emptyTargets() {
        var o = {};
        TARGET_KEYS.forEach(function (k) {
            o[k] = [];
        });
        return o;
    }

    function cloneTargets(src) {
        var o = emptyTargets();
        TARGET_KEYS.forEach(function (k) {
            o[k] = ((src || {})[k] || []).map(String);
        });
        return o;
    }

    function newDestination(toKey) {
        return {
            key: nextKey('d'),
            id: null,
            to: toKey || '',
            enabled: true,
            conditionCount: 0,
            conditionJoin: null,
            conditionReverse: null,
            workflowConditions: []
        };
    }

    function buildState(data) {
        var s = {
            name: data.workflow_view_name,
            startName: data.start_status_name,
            // 新規作成のときだけ画面で選ぶ。既存ワークフローでは変えられない項目。
            type: String(data.workflow_type || ''),
            tableId: '',
            start: { x: 60, y: 300 },
            statuses: [],
            actions: []
        };

        (data.statuses || []).forEach(function (x) {
            s.statuses.push({
                key: 's' + x.id,
                id: String(x.id),
                name: String(x.name),
                datalock: !!x.datalock,
                completed: !!x.completed,
                in_use: !!x.in_use,
                x: 0,
                y: 0
            });
        });

        (data.actions || []).forEach(function (a) {
            var dests = (a.destinations || []).map(function (d) {
                return {
                    key: nextKey('d'),
                    id: d.id ? String(d.id) : null,
                    to: keyOfStatusId(d.status_to),
                    enabled: !!d.enabled,
                    conditionCount: Number(d.condition_count) || 0,
                    conditionJoin: d.condition_join || null,
                    conditionReverse: d.condition_reverse || null,
                    workflowConditions: d.workflow_conditions || []
                };
            });
            if (!dests.length) {
                dests.push(newDestination(''));
            }

            s.actions.push({
                key: 'a' + a.id,
                id: String(a.id),
                name: String(a.name),
                from: keyOfStatusId(a.status_from),
                ignore: !!a.ignore_work,
                targetType: a.work_target_type || TYPE_FIX,
                targets: cloneTargets(a.targets),
                flowType: a.flow_next_type || 'some',
                flowCount: Number(a.flow_next_count) || 1,
                commentType: a.comment_type || 'nullable',
                destinations: dests
            });
        });

        return s;
    }

    /* =====================================================================
     * 参照
     * ===================================================================== */

    function statusOf(key) {
        for (var i = 0; i < S.statuses.length; i++) {
            if (S.statuses[i].key === key) {
                return S.statuses[i];
            }
        }
        return null;
    }

    function actionOf(key) {
        for (var i = 0; i < S.actions.length; i++) {
            if (S.actions[i].key === key) {
                return S.actions[i];
            }
        }
        return null;
    }

    function geom(key) {
        if (key === START) {
            return { x: S.start.x, y: S.start.y, w: START_W, h: START_H, name: S.startName, isStart: true };
        }
        var s = statusOf(key);
        if (!s) {
            return null;
        }
        return { x: s.x, y: s.y, w: NODE_W, h: NODE_H, name: s.name, completed: s.completed, datalock: s.datalock };
    }

    function nameOf(key) {
        var g = geom(key);
        return g ? g.name : t('deleted_status');
    }

    function actionLabel(a) {
        return a.name || t('noname_action');
    }

    /** 描画用の矢印一覧。1つのアクションが複数の実行後ステータスを持つと複数本になる。 */
    function edgeList() {
        var out = [];
        S.actions.forEach(function (a) {
            var enabled = a.destinations.filter(function (d) {
                return d.enabled && d.to;
            });
            enabled.forEach(function (d, i) {
                out.push({
                    key: a.key + '#' + d.key,
                    akey: a.key,
                    action: a,
                    dest: d,
                    branchNo: enabled.length > 1 ? (i + 1) : 0
                });
            });
        });
        return out;
    }

    /* =====================================================================
     * 描画
     * ===================================================================== */

    function applyTf() {
        els.world.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + scale + ')';
        els.zoomlv.textContent = Math.round(scale * 100) + '%';
    }

    function nodeHtml(key) {
        var g = geom(key);
        if (!g) {
            return '';
        }
        var cls = 'wfd-node' + (g.isStart ? ' start' : '') + (g.completed ? ' done' : '') +
            (sel && sel.type === 'node' && sel.key === key ? ' sel' : '');
        var chips = '';
        if (!g.isStart) {
            chips = '<div class="chips">' +
                (g.completed ? '<span class="c-done">' + esc(t('chip_completed')) + '</span>' : '') +
                (g.datalock ? '<span class="c-lock">' + esc(t('chip_datalock')) + '</span>' : '') +
                '</div>';
        }
        var handle = '<div class="wfd-handle" title="' + esc(t('hint_connect')) + '">●</div>';

        return '<div class="' + cls + '" data-key="' + esc(key) + '" style="left:' + g.x + 'px;top:' + g.y + 'px">' +
            '<div class="nm">' + esc(g.name) + '</div>' + chips + handle + '</div>';
    }

    function renderNodes() {
        var html = nodeHtml(START);
        S.statuses.forEach(function (s) {
            html += nodeHtml(s.key);
        });
        els.nodes.innerHTML = html;
    }

    function cubicAt(u, x0, y0, x1, y1, x2, y2, x3, y3) {
        var m = 1 - u;
        return {
            x: m * m * m * x0 + 3 * m * m * u * x1 + 3 * m * u * u * x2 + u * u * u * x3,
            y: m * m * m * y0 + 3 * m * m * u * y1 + 3 * m * u * u * y2 + u * u * u * y3
        };
    }

    /** 1本の矢印の線と、ラベルを置く場所を決める */
    function edgeGeo(fromKey, toKey, idx, cnt) {
        var s = geom(fromKey), e = geom(toKey);
        if (!s || !e) {
            return null;
        }
        var off = (cnt > 1) ? (idx - (cnt - 1) / 2) * 44 : 0;
        var scx = s.x + s.w / 2, tcx = e.x + e.w / 2;
        var scy = s.y + s.h / 2, tcy = e.y + e.h / 2;
        var sx, sy, tx, ty, c1x, c1y, c2x, c2y, k, m0;

        sy = scy;
        ty = tcy;

        // 上下にはっきり離れているときは、横へ回り込まず素直に縦へ流す。
        // （横へ回すのは、真横に並んでいるか、重なっている時だけ）
        var stacked = (e.y >= s.y + s.h || e.y + e.h <= s.y)
            && Math.abs(tcy - scy) >= Math.abs(tcx - scx);

        if (stacked && e.y >= s.y + s.h) {
            sx = scx; sy = s.y + s.h; tx = tcx; ty = e.y;
            k = Math.max(40, Math.abs(ty - sy) / 2);
            c1x = sx + off; c1y = sy + k; c2x = tx + off; c2y = ty - k;
        } else if (stacked) {
            sx = scx; sy = s.y; tx = tcx; ty = e.y + e.h;
            k = Math.max(40, Math.abs(ty - sy) / 2);
            c1x = sx + off; c1y = sy - k; c2x = tx + off; c2y = ty + k;
        } else if (tcx >= scx + (s.w + e.w) / 2 - 20) {
            // 右へ進む
            sx = s.x + s.w;
            tx = e.x;
            k = Math.max(50, Math.min(150, (tx - sx) / 2));
            c1x = sx + k; c1y = sy + off; c2x = tx - k; c2y = ty + off;
        } else if (tcx <= scx - (s.w + e.w) / 2 + 20) {
            // 左へ戻る（差戻し等）
            sx = s.x;
            tx = e.x + e.w;
            k = Math.max(50, Math.min(150, (sx - tx) / 2));
            c1x = sx - k; c1y = sy + off; c2x = tx + k; c2y = ty + off;
        } else if (e.y >= s.y + s.h) {
            sx = scx; sy = s.y + s.h; tx = tcx; ty = e.y;
            k = Math.max(40, Math.abs(ty - sy) / 2);
            c1x = sx + off; c1y = sy + k; c2x = tx + off; c2y = ty - k;
        } else if (e.y + e.h <= s.y) {
            sx = scx; sy = s.y; tx = tcx; ty = e.y + e.h;
            k = Math.max(40, Math.abs(ty - sy) / 2);
            c1x = sx + off; c1y = sy - k; c2x = tx + off; c2y = ty + k;
        } else {
            // 重なっている: 右へ回り込む
            sx = s.x + s.w; sy = s.y + s.h / 2;
            tx = e.x + e.w; ty = e.y + e.h / 2;
            c1x = sx + 90 + off; c1y = sy; c2x = tx + 90 + off; c2y = ty;
            m0 = cubicAt(0.5, sx, sy, c1x, c1y, c2x, c2y, tx, ty);
            return {
                d: 'M' + sx + ',' + sy + ' C' + c1x + ',' + c1y + ' ' + c2x + ',' + c2y + ' ' + tx + ',' + ty,
                lx: m0.x, ly: m0.y
            };
        }

        var mid = cubicAt(0.5, sx, sy, c1x, c1y, c2x, c2y, tx, ty);
        // 横に短い線はラベルが接続用の●に重なるので、少し上へ逃がす
        if (Math.abs(ty - sy) < 30 && Math.abs(tx - sx) < 170) {
            mid.y -= 32;
        }
        return {
            d: 'M' + sx + ',' + sy + ' C' + c1x + ',' + c1y + ' ' + c2x + ',' + c2y + ' ' + tx + ',' + ty,
            lx: mid.x, ly: mid.y
        };
    }

    function targetTypeText(type) {
        var list = (D.options.work_target_type || []);
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === type) {
                return list[i].text;
            }
        }
        return type;
    }

    function edgeTitle(e) {
        var lines = [nameOf(e.action.from) + ' → ' + nameOf(e.dest.to)];
        lines.push(t('label_work_targets') + '：' + targetTypeText(e.action.targetType));
        if (e.action.flowType === 'some') {
            lines.push(t('tip_flow_some', { count: e.action.flowCount }));
        } else {
            lines.push(t('tip_flow_all'));
        }
        if (e.action.ignore) {
            lines.push(t('tip_ignore'));
        }
        if (e.dest.conditionCount > 0) {
            lines.push(t('cond_count', { count: e.dest.conditionCount }));
        }
        return lines.join('\n');
    }

    function renderEdges() {
        var list = edgeList();
        var groups = {};

        list.forEach(function (e) {
            var pk = [e.action.from, e.dest.to].sort().join('|');
            (groups[pk] = groups[pk] || []).push(e.key);
        });

        var paths = '', labels = '';
        var badKeys = badActionKeys();

        list.forEach(function (e) {
            var pk = [e.action.from, e.dest.to].sort().join('|');
            var g = edgeGeo(e.action.from, e.dest.to, groups[pk].indexOf(e.key), groups[pk].length);
            if (!g) {
                return;
            }
            var isSel = sel && sel.type === 'edge' && sel.key === e.akey;
            paths += '<path class="wfd-edge' + (e.action.ignore ? ' dash' : '') + (isSel ? ' sel' : '') +
                '" data-akey="' + esc(e.akey) + '" d="' + g.d +
                '" marker-end="url(#' + (isSel ? 'wfd-arr-sel' : 'wfd-arr') + ')"></path>';

            // 「○人以上実行で次へ」「特殊なアクション」は図が読みにくくなるので札には出さない。
            // 情報自体は線の形（特殊なアクションは破線）と、ホバー時の説明に残している。
            var badge = e.branchNo ? '<span class="b">' + esc(t('branch', { no: e.branchNo })) + '</span>' : '';
            if (e.dest.conditionCount > 0) {
                badge += '<span class="b">' + esc(t('label_has_condition')) + '</span>';
            }

            labels += '<div class="wfd-albl' + (isSel ? ' sel' : '') + (badKeys[e.akey] ? ' bad' : '') +
                '" data-akey="' + esc(e.akey) + '" style="left:' + g.lx + 'px;top:' + g.ly + 'px" title="' +
                esc(edgeTitle(e)) + '">' + esc(actionLabel(e.action)) + badge + '</div>';
        });

        els.edges.innerHTML = paths;
        els.labels.innerHTML = labels;
    }

    function render() {
        applyTf();
        refreshIssues();
        renderNodes();
        renderEdges();
        els.name.textContent = S.name || t('basic_info');
        syncDirty();
    }

    /* =====================================================================
     * 図の検査（保存前に気づけるように）
     * ===================================================================== */

    function badActionKeys() {
        var map = {};
        issueList.forEach(function (i) {
            if (i.level === 'err' && i.akey) {
                map[i.akey] = true;
            }
        });
        return map;
    }

    function computeIssues() {
        var list = [];
        var i;

        var outMap = {};      // from -> [to]
        var hasStart = false;

        S.actions.forEach(function (a) {
            var label = actionLabel(a);

            if (!a.name) {
                list.push({ level: 'err', akey: a.key, text: t('issue_no_name') });
            } else if (clen(a.name) > 30) {
                list.push({ level: 'err', akey: a.key, text: t('issue_long_name', { action: label }) });
            }

            if (!a.from) {
                list.push({ level: 'err', akey: a.key, text: t('issue_no_status_from', { action: label }) });
            } else if (a.from !== START && !statusOf(a.from)) {
                list.push({ level: 'err', akey: a.key, text: t('issue_unknown_status', { action: label }) });
            }

            if (a.from === START) {
                hasStart = true;
            }

            var dests = a.destinations.filter(function (d) {
                return d.enabled && d.to;
            });
            if (!dests.length) {
                list.push({ level: 'err', akey: a.key, text: t('issue_no_status_to', { action: label }) });
            }

            dests.forEach(function (d) {
                if (!statusOf(d.to)) {
                    list.push({ level: 'err', akey: a.key, text: t('issue_unknown_status', { action: label }) });
                    return;
                }
                if (d.to === a.from) {
                    list.push({ level: 'err', akey: a.key, text: t('issue_same_action', { action: label }) });
                }
                (outMap[a.from] = outMap[a.from] || []).push(d.to);
            });

            // 実行可能ユーザー
            if (a.targetType !== TYPE_ACTION_SELECT) {
                var has = false;
                TARGET_KEYS.forEach(function (k) {
                    if ((a.targets[k] || []).length) {
                        has = true;
                    }
                });
                if (!has) {
                    list.push({ level: 'err', akey: a.key, text: t('issue_no_target', { action: label }) });
                }
            } else if (a.ignore) {
                list.push({ level: 'err', akey: a.key, text: t('issue_ignore_action_select', { action: label }) });
            }

            // 人数
            var c = Number(a.flowCount);
            if (a.flowType === 'some' && (!isFinite(c) || c < 0 || c > 10)) {
                list.push({ level: 'err', akey: a.key, text: t('issue_flow_next_count', { action: label }) });
            }
        });

        // 同じ実行前ステータスで「前アクションの実行ユーザーが選択」と他の設定は併用できない
        S.actions.forEach(function (a) {
            if (a.targetType !== TYPE_ACTION_SELECT) {
                return;
            }
            for (var j = 0; j < S.actions.length; j++) {
                var b = S.actions[j];
                if (b === a || b.from !== a.from || b.ignore) {
                    continue;
                }
                if (b.targetType === a.targetType) {
                    continue;
                }
                list.push({
                    level: 'err', akey: a.key,
                    text: t('issue_target_mix', { action: actionLabel(a), status: nameOf(a.from) })
                });
                break;
            }
        });

        // 必須の名称。取込データ等で空のまま来ることがあるので、保存前に気付けるようにする
        if (D.is_new) {
            if (!S.type) {
                list.push({ level: 'err', text: t('issue_no_type') });
            } else if (isTableType(S.type) && !S.tableId) {
                list.push({ level: 'err', text: t('issue_no_table') });
            }
        }
        if (!String(S.name || '').trim()) {
            list.push({ level: 'err', text: t('issue_no_view_name') });
        }
        if (!String(S.startName || '').trim()) {
            list.push({ level: 'err', text: t('issue_no_start_name') });
        }

        if (S.actions.length && !hasStart) {
            list.push({ level: 'err', text: t('issue_no_start', { status: S.startName }) });
        }

        // 到達できるか
        var seen = {}, queue = [START];
        seen[START] = true;
        while (queue.length) {
            var u = queue.shift();
            (outMap[u] || []).forEach(function (v) {
                if (!seen[v]) {
                    seen[v] = true;
                    queue.push(v);
                }
            });
        }

        for (i = 0; i < S.statuses.length; i++) {
            var s = S.statuses[i];
            if (!seen[s.key]) {
                list.push({ level: 'warn', skey: s.key, text: t('issue_unreachable', { status: s.name }) });
            }
            if (!(outMap[s.key] || []).length && !s.completed) {
                list.push({ level: 'warn', skey: s.key, text: t('issue_deadend', { status: s.name }) });
            }
        }

        // 保存を止めるものを先に見せる
        list.sort(function (x, y) {
            return (x.level === 'err' ? 0 : 1) - (y.level === 'err' ? 0 : 1);
        });

        return list;
    }

    function refreshIssues() {
        issueList = computeIssues();
        var n = issueList.length;
        els.issues.textContent = n ? t('issue_count', { count: n }) : t('no_issue');
        els.issues.className = 'wfd-issues' + (n ? ' warn' : '');
        if (els.ipop.style.display === 'block') {
            renderIssuePop();
        }
    }

    function renderIssuePop() {
        els.ipop.querySelector('.tit').textContent = issueList.length
            ? t('issue_pop_title', { count: issueList.length })
            : t('issue_none');

        var html = '';
        issueList.forEach(function (it, i) {
            html += '<div class="it" data-i="' + i + '">' + esc(it.text) + '</div>';
        });
        els.ipop.querySelector('.lst').innerHTML = html;
    }

    /* =====================================================================
     * 表示位置
     * ===================================================================== */

    function centerOn(x, y) {
        var r = els.canvas.getBoundingClientRect();
        panX = r.width / 2 - x * scale;
        panY = r.height / 2 - y * scale;
        applyTf();
    }

    function focusStatus(key) {
        var g = geom(key);
        if (!g) {
            return;
        }
        sel = { type: 'node', key: key };
        render();
        centerOn(g.x + g.w / 2, g.y + g.h / 2);
    }

    function focusAction(akey) {
        var a = actionOf(akey);
        if (!a) {
            return;
        }
        sel = { type: 'edge', key: akey };
        render();
        var g = geom(a.from);
        if (g) {
            centerOn(g.x + g.w / 2, g.y + g.h / 2);
        }
    }

    function zoomAt(mx, my, ns) {
        ns = Math.max(0.3, Math.min(2, ns));
        var wx = (mx - panX) / scale, wy = (my - panY) / scale;
        panX = mx - wx * ns;
        panY = my - wy * ns;
        scale = ns;
        applyTf();
    }

    /**
     * 図全体が見えるようにする。
     * ・拡大はしない（100% が上限）。図が小さいだけなのに大きく映すと、
     *   新規作成のように開始だけしか無いとき不自然に見える。
     * ・横は、図が画面より狭ければ左に寄せる。フローは左から右へ読むので、
     *   真ん中に置くと右へ押しやられたように見えてしまう。縦は中央でよい。
     */
    function fit() {
        var xs = [S.start.x], ys = [S.start.y];
        var xe = [S.start.x + START_W], ye = [S.start.y + START_H];

        S.statuses.forEach(function (s) {
            xs.push(s.x); ys.push(s.y);
            xe.push(s.x + NODE_W); ye.push(s.y + NODE_H);
        });

        var x0 = Math.min.apply(null, xs) - 40, y0 = Math.min.apply(null, ys) - 40;
        var x1 = Math.max.apply(null, xe) + 40, y1 = Math.max.apply(null, ye) + 60;
        var r = els.canvas.getBoundingClientRect();

        scale = Math.max(0.3, Math.min(1, Math.min(r.width / (x1 - x0), r.height / (y1 - y0))));

        var w = (x1 - x0) * scale, h = (y1 - y0) * scale;
        panX = (w < r.width ? 0 : (r.width - w) / 2) - x0 * scale;
        panY = (r.height - h) / 2 - y0 * scale;
        applyTf();
    }

    /** 開始から数えた深さで列を作る。手で動かした配置はここで上書きされる。 */
    function autoLayout() {
        var depth = {}, outMap = {}, i;
        depth[START] = 0;

        S.actions.forEach(function (a) {
            a.destinations.forEach(function (d) {
                if (d.enabled && d.to) {
                    (outMap[a.from] = outMap[a.from] || []).push(d.to);
                }
            });
        });

        var queue = [START];
        while (queue.length) {
            var u = queue.shift();
            (outMap[u] || []).forEach(function (v) {
                if (depth[v] === undefined) {
                    depth[v] = depth[u] + 1;
                    queue.push(v);
                }
            });
        }

        var maxD = 0;
        Object.keys(depth).forEach(function (k) {
            if (depth[k] > maxD) {
                maxD = depth[k];
            }
        });

        // どこからも来ないステータスは一番右にまとめる
        S.statuses.forEach(function (s) {
            if (depth[s.key] === undefined) {
                depth[s.key] = maxD + 1;
            }
        });

        var rows = {};
        S.statuses.forEach(function (s) {
            var d = depth[s.key];
            rows[d] = rows[d] || 0;
            s.x = 60 + d * 270;
            s.y = 90 + rows[d] * 150;
            rows[d]++;
        });

        var firstYs = [];
        S.statuses.forEach(function (s) {
            if (depth[s.key] === 1) {
                firstYs.push(s.y);
            }
        });

        S.start.x = 60;
        S.start.y = firstYs.length
            ? (Math.min.apply(null, firstYs) + Math.max.apply(null, firstYs)) / 2 + (NODE_H - START_H) / 2
            : 300;
    }

    /** DB に入っている座標を重ねる（無いものは自動整列の位置のまま） */
    function applyLayout(layout) {
        var pos = (layout || {}).pos || {};
        Object.keys(pos).forEach(function (k) {
            var p = pos[k];
            if (!p || !isFinite(p.x) || !isFinite(p.y)) {
                return;
            }
            if (k === START) {
                S.start.x = p.x;
                S.start.y = p.y;
                return;
            }
            var s = statusOf('s' + k) || statusOf(k);
            if (s) {
                s.x = p.x;
                s.y = p.y;
            }
        });
    }

    /* =====================================================================
     * 通知・メニュー・モーダル
     * ===================================================================== */

    function toast(msg) {
        els.toast.textContent = msg;
        els.toast.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            els.toast.style.display = 'none';
        }, 3200);
    }

    function closeMenu() {
        els.menu.style.display = 'none';
        els.menu.innerHTML = '';
    }

    function openMenu(e, cap, items) {
        var html = cap ? '<div class="cap">' + esc(cap) + '</div>' : '';
        items.forEach(function (it, i) {
            if (it === '-') {
                html += '<div class="sep"></div>';
                return;
            }
            html += '<div class="mi' + (it.danger ? ' danger' : '') + (it.disabled ? ' dis' : '') +
                '" data-i="' + i + '">' +
                '<span>' + esc(it.icon || '') + '</span><span>' + esc(it.label) +
                (it.note ? '<i class="note">' + esc(it.note) + '</i>' : '') + '</span></div>';
        });

        els.menu.innerHTML = html;
        els.menu.style.display = 'block';

        var mw = els.menu.offsetWidth, mh = els.menu.offsetHeight;
        els.menu.style.left = Math.max(4, Math.min(e.clientX, window.innerWidth - mw - 8)) + 'px';
        els.menu.style.top = Math.max(4, Math.min(e.clientY, window.innerHeight - mh - 8)) + 'px';

        els.menu.onclick = function (ev) {
            var mi = ev.target.closest('.mi');
            if (!mi) {
                return;
            }
            var item = items[Number(mi.getAttribute('data-i'))];
            if (item && item.disabled) {
                return;         // 使用中で消せない等。閉じずに理由を出したまま残す
            }
            closeMenu();
            if (item && item.run) {
                item.run();
            }
        };
    }

    function closeModal() {
        els.modal.classList.remove('on');
        els.modal.innerHTML = '';
        document.body.classList.remove('wfd-modal-open');
    }

    /**
     * 簡易モーダル。buttons は [{label, cls, run(modalEl)}]。
     * run が false を返したときは閉じない（入力エラー時など）。
     */
    function openModal(title, body, buttons, wide) {
        var html = '<div class="wfd-dlg' + (wide ? ' wide' : '') + '">' +
            '<div class="wfd-dlg-h">' + esc(title) + '<button type="button" class="wfd-x" data-close>×</button></div>' +
            '<div class="wfd-dlg-b">' + body + '</div><div class="wfd-dlg-f">';

        buttons.forEach(function (b, i) {
            html += '<button type="button" class="btn btn-sm ' + (b.cls || 'btn-default') + '" data-bi="' + i + '">' +
                esc(b.label) + '</button>';
        });
        html += '</div></div>';

        els.modal.innerHTML = html;
        els.modal.classList.add('on');
        document.body.classList.add('wfd-modal-open');

        els.modal.onclick = function (ev) {
            if (ev.target.closest('[data-close]') || ev.target === els.modal) {
                closeModal();
                return;
            }
            var b = ev.target.closest('[data-bi]');
            if (!b) {
                return;
            }
            var def = buttons[Number(b.getAttribute('data-bi'))];
            if (!def.run) {
                closeModal();
                return;
            }
            if (def.run(els.modal) !== false) {
                closeModal();
            }
        };

        els.modal.onkeydown = function (ev) {
            if (ev.key === 'Escape') {
                closeModal();
                return;
            }
            if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && ev.target.type === 'text') {
                var prim = els.modal.querySelector('.btn-primary') || els.modal.querySelector('.btn-success');
                if (prim) {
                    ev.preventDefault();
                    prim.click();
                }
            }
        };

        var first = els.modal.querySelector('input[type=text]');
        if (first) {
            setTimeout(function () {
                first.focus();
                first.select();
            }, 30);
        }
    }

    function readText(m, id) {
        var el = m.querySelector('#' + id);
        return el ? el.value.trim() : '';
    }

    function markErr(m, id) {
        var el = m.querySelector('#' + id);
        if (el) {
            el.classList.add('err');
            el.focus();
        }
    }

    /* =====================================================================
     * ステータスの追加・編集・削除
     * ===================================================================== */

    function statusFormHtml(s) {
        s = s || { name: '', datalock: false, completed: false };
        return '' +
            '<label class="fl">' + esc(t('label_status_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-sname" maxlength="30" value="' + esc(s.name) + '">' +
            '<div class="help">' + esc(t('label_help_status_name')) + '</div>' +
            '<div class="opt" style="margin-top:14px"><input type="checkbox" id="wfd-slock"' +
            (s.datalock ? ' checked' : '') + '>' +
            '<span><b>' + esc(t('label_datalock_flg')) + '</b>' +
            '<div class="help">' + esc(t('label_help_datalock')) + '</div></span></div>' +
            '<div class="opt"><input type="checkbox" id="wfd-sdone"' + (s.completed ? ' checked' : '') + '>' +
            '<span><b>' + esc(t('completed_flg')) + '</b>' +
            '<div class="help">' + esc(t('help_completed_flg')) + '</div></span></div>';
    }

    function readStatusForm(m, target) {
        var name = readText(m, 'wfd-sname');
        if (!name || clen(name) > 30) {
            markErr(m, 'wfd-sname');
            return null;
        }
        target.name = name;
        target.datalock = m.querySelector('#wfd-slock').checked;

        // 完了ステータスは複数あってよい（承認済み／却下 など）
        var doneEl = m.querySelector('#wfd-sdone');
        target.completed = !!(doneEl && doneEl.checked);
        return target;
    }

    function addStatusModal(wx, wy, connectFrom) {
        var title = connectFrom ? t('connect_new') : t('status_add');
        var extra = '';
        if (connectFrom) {
            extra = '<label class="fl" style="margin-top:16px">' + esc(t('label_action_name')) +
                ' (' + esc(nameOf(connectFrom)) + ' → ?)<span class="req">*</span></label>' +
                '<input type="text" id="wfd-aname" maxlength="30" value="">';
        }

        openModal(title, statusFormHtml(null) + extra, [
            { label: t('label_cancel') },
            {
                label: connectFrom ? t('create_and_connect') : t('add'),
                cls: 'btn-success',
                run: function (m) {
                    var s = {
                        key: nextKey('n'), id: null, name: '', datalock: false, completed: false,
                        x: snap(Math.max(0, Math.min(WORLD_W - NODE_W, wx))),
                        y: snap(Math.max(0, Math.min(WORLD_H - NODE_H, wy)))
                    };
                    if (!readStatusForm(m, s)) {
                        return false;
                    }

                    var aname = '';
                    if (connectFrom) {
                        aname = readText(m, 'wfd-aname');
                        if (!aname || clen(aname) > 30) {
                            markErr(m, 'wfd-aname');
                            return false;
                        }
                    }

                    S.statuses.push(s);
                    if (connectFrom) {
                        S.actions.push(newAction(aname, connectFrom, s.key));
                        toast(t('hint_action'));
                    }
                    sel = { type: 'node', key: s.key };
                    render();
                }
            }
        ]);
    }

    function editStatusModal(key) {
        var s = statusOf(key);
        if (!s) {
            return;
        }
        openModal(t('status_edit') + '：' + s.name, statusFormHtml(s), [
            { label: t('label_cancel') },
            {
                label: t('label_save'), cls: 'btn-primary',
                run: function (m) {
                    if (!readStatusForm(m, s)) {
                        return false;
                    }
                    render();
                }
            }
        ]);
    }

    function editStartModal() {
        openModal(t('label_start_status_name'),
            '<label class="fl">' + esc(t('label_start_status_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-stname" maxlength="30" value="' + esc(S.startName) + '">', [
            { label: t('label_cancel') },
            {
                label: t('label_save'), cls: 'btn-primary',
                run: function (m) {
                    var v = readText(m, 'wfd-stname');
                    if (!v || clen(v) > 30) {
                        markErr(m, 'wfd-stname');
                        return false;
                    }
                    S.startName = v;
                    render();
                }
            }
        ]);
    }

    function createOptionHtml(list, current) {
        var html = '';
        (list || []).forEach(function (o) {
            html += '<option value="' + esc(o.id) + '"' +
                (String(current) === String(o.id) ? ' selected' : '') + '>' + esc(o.text) + '</option>';
        });
        return html;
    }

    function isTableType(value) {
        return String(value) === String((D.create_options || {}).table_value);
    }

    /**
     * 新規作成のときの基本情報。ステップ1 の新規作成フォームと同じ項目をまとめて聞く。
     * 種類と対象テーブルは作ったあと変えられないので、ここで確定させる。
     */
    function basicInfoModal() {
        var co = D.create_options || {};

        openModal(t('basic_info'),
            '<label class="fl">' + esc(t('label_workflow_view_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-wname" maxlength="40" value="' + esc(S.name) + '">' +
            '<label class="fl">' + esc(t('label_workflow_type')) + '<span class="req">*</span></label>' +
            '<select id="wfd-wtype"><option value="">' + esc(t('none')) + '</option>' +
            createOptionHtml(co.workflow_type, S.type) + '</select>' +
            '<div id="wfd-wtable-w">' +
            '<label class="fl">' + esc(t('label_table')) + '<span class="req">*</span></label>' +
            '<select id="wfd-wtable"><option value="">' + esc(t('none')) + '</option>' +
            createOptionHtml(co.custom_table, S.tableId) + '</select></div>' +
            '<label class="fl">' + esc(t('label_start_status_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-stname2" maxlength="30" value="' + esc(S.startName) + '">', [
            { label: t('label_cancel') },
            {
                label: t('label_save'), cls: 'btn-primary',
                run: function (m) {
                    var name = readText(m, 'wfd-wname');
                    var type = m.querySelector('#wfd-wtype').value;
                    var table = m.querySelector('#wfd-wtable').value;
                    var start = readText(m, 'wfd-stname2');

                    if (!name || clen(name) > 40) {
                        markErr(m, 'wfd-wname');
                        return false;
                    }
                    if (!type) {
                        markErr(m, 'wfd-wtype');
                        return false;
                    }
                    if (isTableType(type) && !table) {
                        markErr(m, 'wfd-wtable');
                        return false;
                    }
                    if (!start || clen(start) > 30) {
                        markErr(m, 'wfd-stname2');
                        return false;
                    }

                    S.name = name;
                    S.type = type;
                    S.tableId = isTableType(type) ? table : '';
                    S.startName = start;
                    render();
                }
            }
        ]);

        // 対象テーブルは「テーブル専用」を選んだときだけ聞く
        var typeEl = els.modal.querySelector('#wfd-wtype');
        var wrap = els.modal.querySelector('#wfd-wtable-w');
        var sync = function () {
            wrap.style.display = isTableType(typeEl.value) ? '' : 'none';
        };
        typeEl.addEventListener('change', sync);
        sync();
    }

    function editWorkflowNameModal() {
        openModal(t('label_workflow_view_name'),
            '<label class="fl">' + esc(t('label_workflow_view_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-wname" maxlength="40" value="' + esc(S.name) + '">', [
            { label: t('label_cancel') },
            {
                label: t('label_save'), cls: 'btn-primary',
                run: function (m) {
                    var v = readText(m, 'wfd-wname');
                    if (!v || clen(v) > 40) {
                        markErr(m, 'wfd-wname');
                        return false;
                    }
                    S.name = v;
                    render();
                }
            }
        ]);
    }

    function deleteStatusFlow(key) {
        var s = statusOf(key);
        if (!s) {
            return;
        }
        var used = S.actions.filter(function (a) {
            if (a.from === key) {
                return true;
            }
            return a.destinations.some(function (d) {
                return d.to === key;
            });
        });

        openModal(t('status_delete'),
            '<p>' + esc(t('delete_status_confirm', { name: s.name, count: used.length })) + '</p>', [
            { label: t('label_cancel') },
            {
                label: t('label_delete'), cls: 'btn-danger',
                run: function () {
                    S.statuses = S.statuses.filter(function (x) {
                        return x.key !== key;
                    });
                    S.actions = S.actions.filter(function (a) {
                        if (a.from === key) {
                            return false;
                        }
                        a.destinations = a.destinations.filter(function (d) {
                            return d.to !== key;
                        });
                        return a.destinations.length > 0;
                    });
                    sel = null;
                    render();
                }
            }
        ]);
    }

    /* =====================================================================
     * アクションの追加・編集・削除
     * ===================================================================== */

    function newAction(name, fromKey, toKey) {
        return {
            key: nextKey('m'),
            id: null,
            name: name,
            from: fromKey,
            ignore: false,
            targetType: TYPE_FIX,
            targets: emptyTargets(),
            flowType: 'some',
            flowCount: 1,
            commentType: 'nullable',
            destinations: [newDestination(toKey)]
        };
    }

    function statusSelectHtml(id, current, withStart, cls) {
        var html = '<select id="' + id + '" class="' + (cls || '') + '">';
        if (withStart) {
            html += '<option value="' + START + '"' + (current === START ? ' selected' : '') + '>' +
                esc(S.startName) + '</option>';
        } else {
            html += '<option value=""' + (current ? '' : ' selected') + '>' + esc(t('none')) + '</option>';
        }
        S.statuses.forEach(function (s) {
            html += '<option value="' + esc(s.key) + '"' + (current === s.key ? ' selected' : '') + '>' +
                esc(s.name) + '</option>';
        });
        return html + '</select>';
    }

    function multiSelectHtml(id, group, selected) {
        var html = '<select id="' + id + '" class="wfd-ms" multiple="multiple" data-group="' + esc(group) + '">';
        var items = (D.options[group] || {}).items || [];
        var known = {};

        items.forEach(function (o) {
            known[o.id] = true;
            html += '<option value="' + esc(o.id) + '"' +
                (selected.indexOf(o.id) >= 0 ? ' selected' : '') + '>' + esc(o.text) + '</option>';
        });
        // 一覧に無い（ajax 検索対象の）既存値も消えないように残す
        selected.forEach(function (v) {
            if (!known[v]) {
                html += '<option value="' + esc(v) + '" selected>#' + esc(v) + '</option>';
            }
        });

        return html + '</select>';
    }

    function targetGroupHtml(key, a) {
        var labelMap = {
            user: 'label_user',
            organization: 'label_organization',
            column: 'label_custom_column',
            system: 'label_system',
            login_user_column: 'label_custom_column'
        };
        var group = D.options[key] || {};
        if (key === 'organization' && !group.available) {
            return '';
        }

        return '<div class="wfd-tg" data-tgroup="' + esc(key) + '">' +
            '<label class="fl">' + esc(t(labelMap[key])) + '</label>' +
            multiSelectHtml('wfd-tg-' + key, key, a.targets[key] || []) +
            '</div>';
    }

    function destinationHtml(a, d, index, count) {
        var canRemove = count > 1;
        var head = '';
        if (D.max_branch > 1) {
            head = '<div class="bh"><span>' + esc(t('branch', { no: index + 1 })) + '</span>' +
                (canRemove ? '<button type="button" class="rm" data-rmdest="' + esc(d.key) + '">×&nbsp;' +
                    esc(t('branch_remove')) + '</button>' : '') + '</div>';
        }

        var enabled = (index === 0) ? '' :
            '<div class="opt"><input type="checkbox" class="wfd-den" data-dest="' + esc(d.key) + '"' +
            (d.enabled ? ' checked' : '') + '><span>' + esc(t('branch_enabled')) + '</span></div>';

        var cond = d.conditionCount > 0
            ? '<div class="cond">' + esc(t('cond_count', { count: d.conditionCount })) + '</div>'
            : '';

        return '<div class="wfd-branch" data-destrow="' + esc(d.key) + '">' + head + enabled +
            statusSelectHtml('wfd-to-' + d.key, d.to, false, 'wfd-dto') + cond + '</div>';
    }

    function actionFormHtml(a) {
        var i;
        var rd = function (type) {
            return '<div class="opt"><input type="radio" name="wfd-tt" value="' + esc(type.id) + '"' +
                (a.targetType === type.id ? ' checked' : '') + '><span><b>' + esc(type.text) + '</b></span></div>';
        };

        var destHtml = '';
        a.destinations.forEach(function (d, idx) {
            destHtml += destinationHtml(a, d, idx, a.destinations.length);
        });

        var addBranch = (D.max_branch > 1 && a.destinations.length < D.max_branch)
            ? '<button type="button" class="btn btn-sm btn-default" id="wfd-addbranch">＋&nbsp;' +
              esc(t('branch_add')) + '</button>'
            : '';

        var targetGroups = '';
        TARGET_KEYS.forEach(function (k) {
            targetGroups += targetGroupHtml(k, a);
        });

        var typeRadios = '';
        (D.options.work_target_type || []).forEach(function (type) {
            typeRadios += rd(type);
        });

        var commentOpts = '';
        (D.options.comment_type || []).forEach(function (o) {
            commentOpts += '<option value="' + esc(o.id) + '"' +
                (a.commentType === o.id ? ' selected' : '') + '>' + esc(o.text) + '</option>';
        });

        var ignoreBlock = (a.from === START) ? '' :
            '<div class="opt" style="margin-top:12px"><input type="checkbox" id="wfd-ig"' +
            (a.ignore ? ' checked' : '') + '><span><b>' + esc(t('label_ignore_work')) + '</b>' +
            '<div class="help">' + esc(t('label_help_ignore_work')) + '</div></span></div>';

        return '' +
            '<label class="fl">' + esc(t('label_action_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-aname" maxlength="30" value="' + esc(a.name) + '">' +

            '<label class="fl">' + esc(t('label_status_from')) + '</label>' +
            statusSelectHtml('wfd-from', a.from, true) +

            '<label class="fl">' + esc(t('label_status_to')) + '<span class="req">*</span></label>' +
            '<div id="wfd-dests">' + destHtml + '</div>' + addBranch +
            (D.is_table ? '<div class="note">' + esc(t('branch_note', { label: t('label_work_conditions') || '' })) + '</div>' : '') +

            '<label class="fl">' + esc(t('label_work_targets')) + '<span class="req">*</span></label>' +
            '<div class="box" id="wfd-tbox">' + typeRadios + targetGroups + '</div>' +

            '<label class="fl">' + esc(t('label_flow_next_type')) + '</label>' +
            '<div class="box">' +
            '<div class="opt"><input type="radio" name="wfd-ft" value="some"' +
            (a.flowType === 'some' ? ' checked' : '') + '><span>' +
            '<input type="number" id="wfd-fc" class="wfd-num" min="0" max="10" value="' + esc(a.flowCount) + '">' +
            esc(t('label_upper_user')) + '</span></div>' +
            '<div class="opt"><input type="radio" name="wfd-ft" value="all"' +
            (a.flowType === 'all' ? ' checked' : '') + '><span>' + esc(t('label_all_user')) + '</span></div>' +
            '</div>' +

            '<label class="fl">' + esc(t('label_comment')) + '</label>' +
            '<select id="wfd-ct">' + commentOpts + '</select>' +

            ignoreBlock;
    }

    /** モーダルの中の select2 と、分岐の追加・削除を動かす */
    function bindActionForm(m, a) {
        function initSelects() {
            TARGET_KEYS.forEach(function (k) {
                var el = m.querySelector('#wfd-tg-' + k);
                if (!el || !window.jQuery || !jQuery.fn.select2) {
                    return;
                }
                var group = D.options[k] || {};
                // dropdownParent は指定しない。.wfd-dlg-b が overflow:auto なので
                // 中に入れると候補が切れる。body 直下のまま CSS で前面に出している。
                var conf = {
                    width: '100%',
                    placeholder: t('search_placeholder')
                };
                if (group.ajax) {
                    conf.minimumInputLength = 1;
                    conf.ajax = {
                        url: group.ajax,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return { q: params.term, page: params.page };
                        },
                        processResults: function (data, params) {
                            params.page = params.page || 1;
                            return {
                                results: jQuery.map(data.data || [], function (d) {
                                    return { id: String(d.id), text: d.text };
                                }),
                                pagination: { more: !!data.next_page_url }
                            };
                        },
                        cache: true
                    };
                }
                jQuery(el).select2(conf);
            });
        }

        function syncVisible() {
            var checked = m.querySelector('input[name=wfd-tt]:checked');
            var type = checked ? checked.value : TYPE_FIX;
            var show = TARGET_VISIBLE[type] || [];
            Array.prototype.forEach.call(m.querySelectorAll('[data-tgroup]'), function (el) {
                el.style.display = show.indexOf(el.getAttribute('data-tgroup')) >= 0 ? '' : 'none';
            });
        }

        m.addEventListener('change', function (ev) {
            if (ev.target.name === 'wfd-tt') {
                syncVisible();
            }
        });

        m.addEventListener('click', function (ev) {
            var rm = ev.target.closest('[data-rmdest]');
            if (rm) {
                readActionForm(m, a, true);
                a.destinations = a.destinations.filter(function (d) {
                    return d.key !== rm.getAttribute('data-rmdest');
                });
                reopenActionModal(a);
                return;
            }
            if (ev.target.closest('#wfd-addbranch')) {
                readActionForm(m, a, true);
                if (a.destinations.length < D.max_branch) {
                    a.destinations.push(newDestination(''));
                }
                reopenActionModal(a);
            }
        });

        initSelects();
        syncVisible();
    }

    /** 画面の入力を a に取り込む。draft=true のときは名前などの必須チェックをしない。 */
    function readActionForm(m, a, draft) {
        var name = readText(m, 'wfd-aname');
        if (!draft) {
            if (!name || clen(name) > 30) {
                markErr(m, 'wfd-aname');
                return false;
            }
        }
        a.name = name;
        a.from = m.querySelector('#wfd-from').value;

        a.destinations.forEach(function (d, idx) {
            var sel2 = m.querySelector('#wfd-to-' + d.key);
            if (sel2) {
                d.to = sel2.value;
            }
            var en = m.querySelector('.wfd-den[data-dest="' + d.key + '"]');
            d.enabled = (idx === 0) ? true : (en ? en.checked : d.enabled);
        });

        var tt = m.querySelector('input[name=wfd-tt]:checked');
        a.targetType = tt ? tt.value : TYPE_FIX;

        TARGET_KEYS.forEach(function (k) {
            var el = m.querySelector('#wfd-tg-' + k);
            if (!el) {
                return;
            }
            a.targets[k] = Array.prototype.filter.call(el.options, function (o) {
                return o.selected;
            }).map(function (o) {
                return o.value;
            });
        });

        var ft = m.querySelector('input[name=wfd-ft]:checked');
        a.flowType = ft ? ft.value : 'some';
        a.flowCount = Math.max(0, Math.min(10, Number(m.querySelector('#wfd-fc').value) || 0));
        a.commentType = m.querySelector('#wfd-ct').value;

        var ig = m.querySelector('#wfd-ig');
        a.ignore = ig ? ig.checked : false;

        if (draft) {
            return true;
        }

        // 実行前と実行後が同じは保存できない
        var bad = a.destinations.some(function (d) {
            return d.enabled && d.to && d.to === a.from;
        });
        if (bad) {
            toast(t('label_msg_same_action'));
            return false;
        }
        if (!a.destinations.some(function (d) {
            return d.enabled && d.to;
        })) {
            toast(t('issue_no_status_to', { action: actionLabel(a) }));
            return false;
        }
        if (a.targetType === TYPE_ACTION_SELECT && a.ignore) {
            toast(t('label_msg_ignore_action_select'));
            return false;
        }

        return true;
    }

    function reopenActionModal(a) {
        closeModal();
        setTimeout(function () {
            editActionModal(a.key, a);
        }, 10);
    }

    function editActionModal(key, draftAction) {
        var a = draftAction || actionOf(key);
        if (!a) {
            return;
        }

        openModal(t('action_menu', { name: actionLabel(a) }), actionFormHtml(a), [
            { label: t('label_cancel') },
            {
                label: t('label_save'), cls: 'btn-primary',
                run: function (m) {
                    if (!readActionForm(m, a, false)) {
                        return false;
                    }
                    sel = { type: 'edge', key: a.key };
                    render();
                }
            }
        ], true);

        // #wfd-modal は使い回す要素なので、ここに addEventListener を足すと
        // 開き直すたびに重なる。毎回作り直される .wfd-dlg に付ける。
        bindActionForm(els.modal.querySelector('.wfd-dlg'), a);
    }

    function quickActionModal(fromKey, toKey) {
        openModal(t('connect_title', { from: nameOf(fromKey), to: nameOf(toKey) }),
            '<label class="fl">' + esc(t('label_action_name')) + '<span class="req">*</span></label>' +
            '<input type="text" id="wfd-aname" maxlength="30" value="">' +
            '<div class="help">' + esc(t('hint_action')) + '</div>', [
            { label: t('label_cancel') },
            {
                label: t('create_and_setting'),
                run: function (m) {
                    var name = readText(m, 'wfd-aname');
                    if (!name || clen(name) > 30) {
                        markErr(m, 'wfd-aname');
                        return false;
                    }
                    var a = newAction(name, fromKey, toKey);
                    S.actions.push(a);
                    sel = { type: 'edge', key: a.key };
                    render();
                    setTimeout(function () {
                        editActionModal(a.key);
                    }, 60);
                }
            },
            {
                label: t('create'), cls: 'btn-success',
                run: function (m) {
                    var name = readText(m, 'wfd-aname');
                    if (!name || clen(name) > 30) {
                        markErr(m, 'wfd-aname');
                        return false;
                    }
                    var a = newAction(name, fromKey, toKey);
                    S.actions.push(a);
                    sel = { type: 'edge', key: a.key };
                    render();
                }
            }
        ]);
    }

    function deleteActionFlow(key) {
        var a = actionOf(key);
        if (!a) {
            return;
        }
        openModal(t('action_delete'),
            '<p>' + esc(t('delete_action_confirm', { name: actionLabel(a) })) + '</p>', [
            { label: t('label_cancel') },
            {
                label: t('label_delete'), cls: 'btn-danger',
                run: function () {
                    S.actions = S.actions.filter(function (x) {
                        return x.key !== key;
                    });
                    sel = null;
                    render();
                }
            }
        ]);
    }

    /* =====================================================================
     * 保存
     * ===================================================================== */

    function payload() {
        var pos = {};
        pos[START] = { x: S.start.x, y: S.start.y };
        S.statuses.forEach(function (s) {
            pos[s.key] = { x: s.x, y: s.y };
        });

        var p = {
            updated_at: D.updated_at,
            workflow_view_name: S.name,
            start_status_name: S.startName,
            statuses: S.statuses.map(function (s) {
                return {
                    id: s.id, key: s.key, name: s.name,
                    datalock: s.datalock ? 1 : 0,
                    completed: s.completed ? 1 : 0
                };
            }),
            actions: S.actions.map(function (a) {
                return {
                    id: a.id, key: a.key, name: a.name,
                    status_from: a.from,
                    ignore_work: a.ignore ? 1 : 0,
                    work_target_type: a.targetType,
                    targets: a.targets,
                    flow_next_type: a.flowType,
                    flow_next_count: a.flowCount,
                    comment_type: a.commentType,
                    destinations: a.destinations.filter(function (d) {
                        return d.to;
                    }).map(function (d) {
                        return {
                            id: d.id,
                            status_to: d.to,
                            enabled: d.enabled ? 1 : 0,
                            condition_join: d.conditionJoin,
                            condition_reverse: d.conditionReverse,
                            workflow_conditions: d.workflowConditions
                        };
                    })
                };
            }),
            layout: { enabled: true, pos: pos }
        };

        if (D.is_new) {
            p.workflow_type = S.type;
            p.custom_table_id = S.tableId;
        }

        return p;
    }

    function signature() {
        return JSON.stringify(payload());
    }

    function dirty() {
        return signature() !== baseSig;
    }

    function syncDirty() {
        els.dirtyEl.className = 'wfd-dirty' + (dirty() ? ' on' : '');
    }

    function showErrors(list) {
        var html = '<div class="errbox"><b>' + esc(t('message.validation_error') || '') + '</b><ul>';
        list.forEach(function (e) {
            html += '<li>' + esc(e) + '</li>';
        });
        html += '</ul></div>';
        openModal(t('header'), html, [{ label: t('label_cancel'), cls: 'btn-default' }]);
    }

    /**
     * 保存でステータス・アクションが揃うと、ステップ3 が押せる状態に変わる。
     * 画面を読み込み直さずにボタンだけ差し替える（modal.js は document 側で
     * data-widgetmodal_url を拾うので、要素を作り直しても動く）。
     */
    function stepLabel(no, text) {
        // blade 側と同じ「3.（改行しない空白）ラベル」の形にそろえる
        return no + '.\u00A0' + text;
    }

    function replaceStep(id, tag, cls, label, attrs) {
        var old = $id(id);
        if (!old) {
            return;
        }
        var el = document.createElement(tag);
        el.id = id;
        el.className = cls;
        el.textContent = label;
        Object.keys(attrs).forEach(function (k) {
            if (attrs[k] !== null && attrs[k] !== undefined) {
                el.setAttribute(k, attrs[k]);
            }
        });
        old.parentNode.replaceChild(el, old);
    }

    function syncStepButtons(info) {
        // 設定完了する（番号なし）。完了済みなら出す意味がないので隠す。
        if (info.can_activate && D.urls.step3) {
            replaceStep('wfd-activate', 'a', 'btn btn-sm btn-success', t('label_setting_complete'), {
                href: 'javascript:void(0);',
                'data-widgetmodal_url': D.urls.step3,
                'data-widgetmodal_method': 'GET',
                title: t('activate_hint')
            });
        } else {
            replaceStep('wfd-activate', 'span',
                'btn btn-sm btn-default wfd-off' + (info.activated ? ' wfd-hide' : ''),
                t('label_setting_complete'), { title: t('activate_wait') });
        }

        // ステップ3（通知設定）。設定完了して初めて開ける。
        if (info.activated && D.urls.notify) {
            replaceStep('wfd-step3', 'a', 'btn btn-sm btn-default',
                stepLabel('3', t('label_notify')), { href: D.urls.notify, title: t('to_step3') });
        } else {
            replaceStep('wfd-step3', 'span', 'btn btn-sm btn-default wfd-off',
                stepLabel('3', t('label_notify')), { title: t('step3_wait') });
        }

        if (info.activated) {
            replaceStep('wfd-step4', 'a', 'btn btn-sm btn-default',
                stepLabel('4', t('label_beginning')), { href: D.urls.step4, title: t('to_step4') });
        } else {
            replaceStep('wfd-step4', 'span', 'btn btn-sm btn-default wfd-off',
                stepLabel('4', t('label_beginning')), { title: t('step4_wait') });
        }
    }

    function save() {
        if (saving) {
            return;
        }
        var errs = issueList.filter(function (i) {
            return i.level === 'err';
        });
        if (errs.length) {
            showErrors(errs.map(function (i) {
                return i.text;
            }));
            return;
        }

        saving = true;
        var html = els.save.innerHTML;
        els.save.disabled = true;
        els.save.textContent = t('saving');

        jQuery.ajax({
            url: D.urls.save,
            type: 'POST',
            dataType: 'json',
            data: { _token: token(), design: JSON.stringify(payload()) }
        }).done(function (res) {
            if (!res || !res.result) {
                showErrors((res && res.errors) || [t('message.validation_error')]);
                return;
            }
            var map = res.data || {};

            // 新規作成。ここで初めてワークフローができるので、その画面へ移る。
            if (map.redirect) {
                baseSig = signature();
                syncDirty();
                window.onbeforeunload = null;
                location.href = map.redirect;
                return;
            }

            D.updated_at = map.updated_at || D.updated_at;

            S.statuses.forEach(function (s) {
                if (map.status_map && map.status_map[s.key]) {
                    s.id = String(map.status_map[s.key]);
                }
            });
            S.actions.forEach(function (a) {
                if (map.action_map && map.action_map[a.key]) {
                    a.id = String(map.action_map[a.key]);
                }
            });

            baseSig = signature();
            syncDirty();
            syncStepButtons(map);
            toast(res.toastr || 'OK');
        }).fail(function (xhr) {
            var res = xhr.responseJSON || {};
            showErrors(res.errors || [res.toastr || ('HTTP ' + xhr.status)]);
        }).always(function () {
            saving = false;
            els.save.disabled = false;
            els.save.innerHTML = html;
        });
    }

    /* =====================================================================
     * 操作の結線
     * ===================================================================== */

    function worldPoint(clientX, clientY) {
        var r = els.canvas.getBoundingClientRect();
        return {
            x: (clientX - r.left - panX) / scale,
            y: (clientY - r.top - panY) / scale
        };
    }

    function bindCanvas() {
        els.canvas.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            var lbl = e.target.closest('.wfd-albl');
            var nd = e.target.closest('.wfd-node');
            var w = worldPoint(e.clientX, e.clientY);

            if (lbl) {
                var a = actionOf(lbl.getAttribute('data-akey'));
                if (!a) {
                    return;
                }
                sel = { type: 'edge', key: a.key };
                render();
                openMenu(e, t('action_menu', { name: actionLabel(a) }), [
                    { icon: '⚙', label: t('action_edit'), run: function () { editActionModal(a.key); } },
                    '-',
                    { icon: '✖', label: t('action_delete'), danger: true, run: function () { deleteActionFlow(a.key); } }
                ]);
                return;
            }

            if (nd && nd.getAttribute('data-key') === START) {
                openMenu(e, t('start_menu', { name: S.startName }), [
                    { icon: '✎', label: t('start_edit'), run: editStartModal }
                ]);
                return;
            }

            if (nd) {
                var key = nd.getAttribute('data-key');
                sel = { type: 'node', key: key };
                render();
                var st = statusOf(key);
                var statusItems = [
                    { icon: '⚙', label: t('status_edit'), run: function () { editStatusModal(key); } },
                    '-'
                ];
                if (st && st.in_use) {
                    // 使用中のステータスを消すと、そのデータの状態と履歴まで消えるので出さない
                    statusItems.push({
                        icon: '✖', label: t('status_delete'),
                        disabled: true, note: t('status_in_use')
                    });
                } else {
                    statusItems.push({
                        icon: '✖', label: t('status_delete'), danger: true,
                        run: function () { deleteStatusFlow(key); }
                    });
                }
                openMenu(e, t('status_menu', { name: nameOf(key) }), statusItems);
                return;
            }

            var items = [{
                icon: '＋', label: t('status_add'),
                run: function () { addStatusModal(w.x - NODE_W / 2, w.y - NODE_H / 2, null); }
            }, '-'];
            items.push({ icon: '✦', label: t('auto_layout'), run: function () { autoLayout(); render(); fit(); } });
            items.push({ icon: '⤢', label: t('zoom_fit'), run: fit });
            openMenu(e, null, items);
        });

        els.nodes.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) {
                return;
            }
            var handle = e.target.closest('.wfd-handle');
            var nd = e.target.closest('.wfd-node');
            if (!nd) {
                return;
            }
            var key = nd.getAttribute('data-key');
            e.stopPropagation();
            e.preventDefault();

            // ●の上、または右端 18px のどこでも接続を始められるようにする
            var rect = nd.getBoundingClientRect();
            if (handle || e.clientX > rect.right - 18) {
                var g = geom(key);
                drag = { type: 'rubber', from: key, sx: g.x + g.w, sy: g.y + g.h / 2, hover: null };
                return;
            }

            var pos = (key === START) ? S.start : statusOf(key);
            if (!pos) {
                return;
            }
            drag = {
                type: 'move', key: key, el: nd,
                mx: e.clientX, my: e.clientY, ox: pos.x, oy: pos.y, moved: false
            };
        });

        els.labels.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) {
                return;
            }
            var lbl = e.target.closest('.wfd-albl');
            if (!lbl) {
                return;
            }
            e.stopPropagation();
            sel = { type: 'edge', key: lbl.getAttribute('data-akey') };
            render();
        });

        els.labels.addEventListener('dblclick', function (e) {
            var lbl = e.target.closest('.wfd-albl');
            if (lbl) {
                editActionModal(lbl.getAttribute('data-akey'));
            }
        });

        els.canvas.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) {
                return;
            }
            if (e.target.closest('.wfd-node') || e.target.closest('.wfd-albl') || e.target.closest('#wfd-ipop')) {
                return;
            }
            drag = { type: 'pan', mx: e.clientX, my: e.clientY, ox: panX, oy: panY, moved: false };
            els.canvas.classList.add('panning');
        });

        els.canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            if (drag) {
                return;     // ドラッグ中に倍率が動くと狙った所へ落とせない
            }
            var r = els.canvas.getBoundingClientRect();
            zoomAt(e.clientX - r.left, e.clientY - r.top, scale * (e.deltaY < 0 ? 1.12 : 1 / 1.12));
        }, { passive: false });

        els.ipop.addEventListener('click', function (e) {
            var it = e.target.closest('.it');
            if (!it) {
                return;
            }
            var issue = issueList[Number(it.getAttribute('data-i'))];
            if (!issue) {
                return;
            }
            els.ipop.style.display = 'none';
            if (issue.akey) {
                focusAction(issue.akey);
            } else if (issue.skey) {
                focusStatus(issue.skey);
            }
        });

        els.issues.addEventListener('click', function () {
            if (els.ipop.style.display === 'block') {
                els.ipop.style.display = 'none';
                return;
            }
            renderIssuePop();
            els.ipop.style.display = 'block';
        });

        els.name.addEventListener('click', function () {
            if (D.is_new) {
                basicInfoModal();
                return;
            }
            editWorkflowNameModal();
        });

        $id('wfd-zin').addEventListener('click', function () {
            var r = els.canvas.getBoundingClientRect();
            zoomAt(r.width / 2, r.height / 2, scale * 1.2);
        });
        $id('wfd-zout').addEventListener('click', function () {
            var r = els.canvas.getBoundingClientRect();
            zoomAt(r.width / 2, r.height / 2, scale / 1.2);
        });
        $id('wfd-fit').addEventListener('click', fit);
        $id('wfd-relayout').addEventListener('click', function () {
            openModal(t('reset_layout'), '<p>' + esc(t('reset_layout_confirm')) + '</p>', [
                { label: t('label_cancel') },
                {
                    label: t('reset_layout'), cls: 'btn-primary',
                    run: function () {
                        autoLayout();
                        render();
                        fit();
                    }
                }
            ]);
        });
        $id('wfd-hint-x').addEventListener('click', function () {
            els.hint.style.display = 'none';
        });
        els.save.addEventListener('click', save);
    }

    function bindWindow() {
        jQuery(window).off('.wfd').on('pointermove.wfd', function (ev) {
            if (!drag || !alive()) {
                return;
            }
            var e = ev.originalEvent || ev;

            if (drag.type === 'move') {
                var dx = (e.clientX - drag.mx) / scale, dy = (e.clientY - drag.my) / scale;
                if (Math.abs(e.clientX - drag.mx) + Math.abs(e.clientY - drag.my) > 3) {
                    drag.moved = true;
                }
                var pos = (drag.key === START) ? S.start : statusOf(drag.key);
                if (!pos) {
                    return;
                }
                pos.x = Math.max(0, Math.min(WORLD_W - NODE_W, snap(drag.ox + dx)));
                pos.y = Math.max(0, Math.min(WORLD_H - NODE_H, snap(drag.oy + dy)));
                drag.el.style.left = pos.x + 'px';
                drag.el.style.top = pos.y + 'px';
                renderEdges();
                return;
            }

            if (drag.type === 'rubber') {
                var w = worldPoint(e.clientX, e.clientY);
                var k = Math.max(40, Math.abs(w.x - drag.sx) / 2);
                els.ovl.innerHTML = '<path class="wfd-rubber" d="M' + drag.sx + ',' + drag.sy +
                    ' C' + (drag.sx + k) + ',' + drag.sy + ' ' + (w.x - k) + ',' + w.y + ' ' + w.x + ',' + w.y +
                    '" marker-end="url(#wfd-arr-new)"></path>';

                var el = document.elementFromPoint(e.clientX, e.clientY);
                var nd = el && el.closest ? el.closest('.wfd-node') : null;
                var hoverKey = nd ? nd.getAttribute('data-key') : null;
                if (drag.hover !== hoverKey) {
                    var old = els.nodes.querySelector('.droptarget');
                    if (old) {
                        old.classList.remove('droptarget');
                    }
                    if (nd && hoverKey !== drag.from && hoverKey !== START) {
                        nd.classList.add('droptarget');
                    }
                    drag.hover = hoverKey;
                }
                return;
            }

            if (drag.type === 'pan') {
                if (Math.abs(e.clientX - drag.mx) + Math.abs(e.clientY - drag.my) > 3) {
                    drag.moved = true;
                }
                panX = drag.ox + (e.clientX - drag.mx);
                panY = drag.oy + (e.clientY - drag.my);
                applyTf();
            }
        }).on('pointerup.wfd', function (ev) {
            if (!drag || !alive()) {
                drag = null;
                return;
            }
            var e = ev.originalEvent || ev;
            var d = drag;
            drag = null;
            els.canvas.classList.remove('panning');

            if (d.type === 'move') {
                if (!d.moved) {
                    sel = { type: 'node', key: d.key };
                }
                render();
                return;
            }

            if (d.type === 'rubber') {
                els.ovl.innerHTML = '';
                var old = els.nodes.querySelector('.droptarget');
                if (old) {
                    old.classList.remove('droptarget');
                }

                var el = document.elementFromPoint(e.clientX, e.clientY);
                var nd = el && el.closest ? el.closest('.wfd-node') : null;
                var inCanvas = el && el.closest ? el.closest('#wfd-canvas') : null;

                if (nd) {
                    var to = nd.getAttribute('data-key');
                    if (to === START || to === d.from) {
                        toast(t('label_msg_same_action'));
                        return;
                    }
                    quickActionModal(d.from, to);
                } else if (inCanvas) {
                    // 何もない所へ落としたら、ステータスを作ってそのままつなぐ
                    var w = worldPoint(e.clientX, e.clientY);
                    addStatusModal(w.x - NODE_W / 2, w.y - NODE_H / 2, d.from);
                }
                return;
            }

            if (d.type === 'pan' && !d.moved) {
                sel = null;
                render();
            }
        });

        jQuery(document).off('.wfd').on('pointerdown.wfd', function (ev) {
            if (!alive()) {
                return;
            }
            var e = ev.originalEvent || ev;
            if (!e.target.closest('#wfd-menu')) {
                closeMenu();
            }
            if (els.ipop.style.display === 'block' &&
                !e.target.closest('#wfd-ipop') && !e.target.closest('#wfd-issues')) {
                els.ipop.style.display = 'none';
            }
        }).on('keydown.wfd', function (ev) {
            if (!alive() || els.modal.classList.contains('on')) {
                return;
            }
            if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
                ev.preventDefault();
                save();
            }
        }).on('pjax:beforeSend.wfd', function (ev) {
            if (!alive() || !dirty()) {
                return;
            }
            if (!window.confirm(t('unsaved_leave'))) {
                ev.preventDefault();
                return false;
            }
        });

        window.onbeforeunload = function () {
            if (alive() && dirty()) {
                return t('unsaved_leave');
            }
        };
    }

    /* =====================================================================
     * 起動
     * ===================================================================== */

    function boot(data) {
        els = {
            app: $id('wfd-app'),
            canvas: $id('wfd-canvas'),
            world: $id('wfd-world'),
            edges: $id('wfd-edges'),
            ovl: $id('wfd-ovl'),
            nodes: $id('wfd-nodes'),
            labels: $id('wfd-labels'),
            menu: $id('wfd-menu'),
            modal: $id('wfd-modal'),
            toast: $id('wfd-toast'),
            issues: $id('wfd-issues'),
            ipop: $id('wfd-ipop'),
            zoomlv: $id('wfd-zoomlv'),
            name: $id('wfd-name'),
            dirtyEl: $id('wfd-dirty'),
            hint: $id('wfd-hint'),
            notice: $id('wfd-notice'),
            save: $id('wfd-save')
        };
        if (!els.app || !els.canvas) {
            return;
        }

        D = data;
        T = data.texts || {};
        seq = 0;
        sel = null;
        drag = null;
        saving = false;

        S = buildState(data);

        els.world.style.width = WORLD_W + 'px';
        els.world.style.height = WORLD_H + 'px';
        els.canvas.querySelector('#wfd-svg').setAttribute('width', WORLD_W);
        els.canvas.querySelector('#wfd-svg').setAttribute('height', WORLD_H);

        autoLayout();
        applyLayout(data.layout);

        var notices = [];
        if (data.is_new) {
            notices.push(t('new_note'));
        }
        if (data.activated) {
            notices.push(t('activated_note'));
        }
        if (data.multi_completed) {
            // この画面では保つが、ステップ1で保存すると 1 件に絞られる
            notices.push(t('multi_completed_note'));
        }
        if (notices.length > 0) {
            els.notice.textContent = notices.join('  ');
            els.notice.style.display = '';
        }

        bindCanvas();
        bindWindow();

        render();
        fit();
        baseSig = signature();
        syncDirty();
        booted = true;

        // 新規作成は名称も種類も空なので、最初に基本情報を聞く
        if (data.is_new && !String(S.name || '').trim()) {
            setTimeout(basicInfoModal, 0);
        }
    }

    class WorkflowDesignEdit {
        /** @param {object} data サーバーから渡す design_data */
        static boot(data) {
            boot(data);
        }

        /** 外部（テスト等）から中身を覗くためのヘルパ */
        static state() {
            if (!booted) {
                return null;
            }
            return {
                statuses: S.statuses,
                actions: S.actions,
                selection: sel,
                issues: issueList,
                dirty: dirty(),
                payload: payload()
            };
        }
    }

    Exment.WorkflowDesignEdit = WorkflowDesignEdit;
})(Exment || (Exment = {}));
