/**
 * ワークフロー設定 Step2「アクション設定」の右ペインに、フロー図のプレビューを描画する。
 *
 * 方針
 *  - データ元は画面の hasManyTable そのもの（DOM）。行を編集すると図が追従する。
 *    ステータスの表示名・完了区分・ロック区分だけは画面に出ていないため、
 *    blade から JSON（boot の options.statuses）で受け取る。
 *  - 図は閲覧専用。矢印やステータスをクリックすると左テーブルの該当行までスクロールして
 *    ハイライトするだけで、編集は既存のフォームと「変更」モーダルで行う。
 *  - Exment 側の DOM は移動させるだけで作り替えない（hasmanytable-validation.js などが
 *    .has-many-table-div / form を closest() で辿るため、入れ子の外側だけを変える）。
 *
 * 注意（この画面固有の罠）
 *  - AdminLTE の `.box{width:100%}` は SVG2 のジオメトリプロパティとして <rect> に効く。
 *    SVG 内では box / accent / chip のような一般名を class に使わない（nbox / nacc / nchip）。
 *  - 「変更」モーダルは値を .val() で書き込むだけで change イベントを飛ばさない。
 *    そのため click を拾った遅延更新と、低頻度のシグネチャ監視の二段構えで追従する。
 */
var Exment;
(function (Exment) {
    'use strict';

    /* =====================================================================
     * 定数・状態
     * ===================================================================== */
    var BLOCK = '#has-many-table-workflow_actions';
    var ROW = 'tr.has-many-table-workflow_actions-row';
    var NS = 'http://www.w3.org/2000/svg';
    var LS_SPLIT = 'exment.workflow_designer.split';
    var LS_OPEN = 'exment.workflow_designer.open';
    // WorkflowWorkTargetType::ACTION_SELECT（前アクションの実行ユーザーが選択）
    var TARGET_SELECT = 'action_select';

    // レイアウト寸法（縦型: 上から下へ流す）
    var NODE_W = 188, NODE_H = 62, START_W = 120, START_H = 44;
    var LAYER_H = 150, SIB_W = 230, PAD = 40;

    var texts = {};                 // blade から渡す表示文言
    var statusList = [];            // [{id, name, completed, datalock}]（start を含まない）
    var startName = 'start';
    var actions = [];               // 走査した行
    var selection = null;           // {type:'start'|'status'|'action', id, hi}
    var manualPos = {};             // ノードキー -> {x,y}（自動整列でクリア）
    var savePos = false;            // 手で動かした配置を DB に保存するか（ツールバーのピン）
    var layoutBase = '';            // 読み込んだ時点の配置。未保存の変更の判定に使う
    var layoutInput = null;         // options[designer_layout] の hidden（フォームで一緒に送る）
    var leaveBound = false;         // 離脱時の確認を結線済みか（pjax でも 1 回だけ）
    var tf = { x: PAD, y: PAD, k: 1 };
    var lastLayout = null, lastBounds = null, edgeFan = {};
    var previewOpen = true, curSplit = 0.56;
    var sig = '', pollTimer = null, schedTimer = null, resizeTimer = null;
    var splitEl, leftEl, rightEl, splitbar, svg, world, issuesEl, issuePop, toggleBtn, saveBtn;

    /* =====================================================================
     * 小道具
     * ===================================================================== */
    function t(key, params) {
        var s = texts[key];
        if (s === undefined || s === null) {
            s = key;
        }
        if (params) {
            Object.keys(params).forEach(function (k) {
                s = String(s).split(':' + k).join(params[k]);
            });
        }
        return String(s);
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

    function cut(s, n) {
        s = String(s === null || s === undefined ? '' : s);
        return s.length > n ? s.slice(0, n) + '…' : s;
    }

    function cssEsc(s) {
        if (window.CSS && CSS.escape) {
            return CSS.escape(String(s));
        }
        return String(s).replace(/([^\w-])/g, '\\$1');
    }

    // PHP の max:30 は文字数なので、サロゲートペアを 1 文字として数える
    function clen(str) {
        str = String(str === null || str === undefined ? '' : str);
        try {
            return Array.from(str).length;
        } catch (ex) {
            return str.length;
        }
    }

    function nw(key) { return key === 'start' ? START_W : NODE_W; }
    function nh(key) { return key === 'start' ? START_H : NODE_H; }

    function statusById(id) {
        id = String(id);
        for (var i = 0; i < statusList.length; i++) {
            if (String(statusList[i].id) === id) {
                return statusList[i];
            }
        }
        return null;
    }

    function actionById(id) {
        id = String(id);
        for (var i = 0; i < actions.length; i++) {
            if (String(actions[i].id) === id) {
                return actions[i];
            }
        }
        return null;
    }

    function nodeLabel(key) {
        if (key === 'start') {
            return startName;
        }
        var s = statusById(key);
        return s ? s.name : t('deleted_status');
    }

    /* =====================================================================
     * SCAN — hasManyTable の DOM からアクション一覧を読み取る
     * ===================================================================== */
    function fieldEl(tr, suffix) {
        return tr.querySelector('[name$="[' + suffix + ']"]');
    }

    function fieldVal(tr, suffix) {
        var el = fieldEl(tr, suffix);
        return el ? String(el.value === null || el.value === undefined ? '' : el.value) : '';
    }

    // ラジオ（flow_next_type）は最初の要素の value を読むと常に 'some' になるので
    // checked のものを探す。iCheck は元の input の checked を同期している。
    function radioVal(tr, suffix) {
        var els = tr.querySelectorAll('[name$="[' + suffix + ']"]');
        for (var i = 0; i < els.length; i++) {
            if (els[i].checked) {
                return String(els[i].value);
            }
        }
        return '';
    }

    function rowKey(tr) {
        var el = tr.querySelector('[name^="workflow_actions["]');
        if (!el) {
            return null;
        }
        var m = /^workflow_actions\[([^\]]+)\]/.exec(el.getAttribute('name') || '');
        return m ? m[1] : null;
    }

    function isRemoved(tr) {
        var el = fieldEl(tr, '_remove_');
        return !!(el && String(el.value) === '1');
    }

    // work_conditions の中身は 2 通りある。
    //   (A) 画面を開いた直後…PHP の hiddenFormat が作った配列
    //       [{"id":54,"status_to":"22","enabled_flg":"1","workflow_conditions":[...]}]
    //       条件番号が飛ぶと {"0":{...},"2":{...}} とオブジェクトになる。
    //   (B) 「変更」モーダル送信後…フォームをそのまま直列化した平たい形
    //       {"enabled_flg_0":"1","status_to_0":"22","workflow_conditions_0[new_1][x]":"y"}
    //   (B) を放置すると行を編集したとたん矢印が消えるので、
    //   PHP 側 Condition::getWorkConditions() と同じほぐし方で (A) にそろえる。
    function normalizeWorkConditions(parsed) {
        if (Object.prototype.toString.call(parsed) === '[object Array]') {
            return parsed;
        }
        if (!parsed || typeof parsed !== 'object') {
            return [];
        }
        var keys = Object.keys(parsed);
        if (!keys.length) {
            return [];
        }
        var byNo = function (a, b) { return Number(a) - Number(b); };
        var flat = keys.some(function (k) { return /^.+_\d+(\[|$)/.test(k); });
        if (!flat) {
            return keys.sort(byNo).map(function (k) { return parsed[k]; })
                .filter(function (v) { return v && typeof v === 'object'; });
        }
        var slots = {};
        function slot(no) {
            if (!slots[no]) {
                slots[no] = { _conds: {} };
            }
            return slots[no];
        }
        keys.forEach(function (k) {
            var m = /^(.+)_(\d+)\[([^\]]+)\]\[([^\]]+)\]$/.exec(k);
            if (m) {
                if (m[1] === 'workflow_conditions') {
                    var c = slot(m[2])._conds;
                    if (!c[m[3]]) {
                        c[m[3]] = {};
                    }
                    c[m[3]][m[4]] = parsed[k];
                }
                return;
            }
            m = /^(.+)_(\d+)$/.exec(k);
            if (m) {
                slot(m[2])[m[1]] = parsed[k];
            }
        });
        return Object.keys(slots).sort(byNo).map(function (no) {
            var s = slots[no], conds = [];
            Object.keys(s._conds).forEach(function (ck) {
                if (String(s._conds[ck]['_remove_']) === '1') {   // 削除した条件行
                    return;
                }
                conds.push(s._conds[ck]);
            });
            delete s._conds;
            s.workflow_conditions = conds;
            return s;
        });
    }

    // 実行後ステータス（テーブル専用=JSON / 汎用=単一セレクト）を共通の形にそろえる
    function readDestinations(tr) {
        var raw = fieldVal(tr, 'work_conditions');
        if (fieldEl(tr, 'work_conditions')) {
            if (!raw) {
                return [];
            }
            var arr;
            try {
                arr = normalizeWorkConditions(JSON.parse(raw));
            } catch (ex) {
                return [];
            }
            if (!arr || !arr.length) {
                return [];
            }
            return arr.map(function (c) {
                var conds = c.workflow_conditions || [];
                return {
                    status_to: String(c.status_to === null || c.status_to === undefined ? '' : c.status_to),
                    enabled: String(c.enabled_flg) === '1' || c.enabled_flg === 1 || c.enabled_flg === true,
                    join: c.condition_join || null,
                    reverse: !!c.condition_reverse,
                    conds: conds.length
                };
            });
        }
        var sel = fieldVal(tr, 'work_condition_select');
        if (!sel) {
            return [];
        }
        return [{ status_to: sel, enabled: true, join: null, reverse: false, conds: 0 }];
    }

    // 実行可能ユーザー（work_targets）。Exment の保存時チェックと同じ見方でほぐす。
    //   work_targets が空            → 必須エラー
    //   fix / get_by_userinfo の場合 → 種別以外に値が 1 つも無ければ必須エラー
    //   action_select の場合         → 値は不要
    function readTargets(tr) {
        var raw = fieldVal(tr, 'work_targets'), obj = null;
        if (raw) {
            try {
                obj = JSON.parse(raw);
            } catch (ex) {
                obj = null;
            }
        }
        if (!obj || typeof obj !== 'object') {
            return { type: '', filled: false };
        }
        var filled = false;
        Object.keys(obj).forEach(function (k) {
            if (filled || k === 'work_target_type') {
                return;
            }
            var v = obj[k];
            if (v === null || v === undefined || v === '') {
                return;
            }
            if (Object.prototype.toString.call(v) === '[object Array]' && !v.length) {
                return;
            }
            filled = true;
        });
        return { type: String(obj.work_target_type || ''), filled: filled };
    }

    function scan() {
        var block = document.querySelector(BLOCK);
        actions = [];
        if (!block) {
            return;
        }
        var trs = block.querySelectorAll(ROW);
        Array.prototype.forEach.call(trs, function (tr) {
            if (isRemoved(tr)) {
                return;
            }
            var key = rowKey(tr);
            if (!key) {
                return;
            }
            var ign = fieldEl(tr, 'ignore_work');
            var raw_name = fieldVal(tr, 'action_name');
            tr.setAttribute('data-wd-act', key);
            actions.push({
                id: key,
                el: tr,
                name: raw_name || t('noname_action'),
                name_raw: raw_name,
                status_from: fieldVal(tr, 'status_from'),
                ignore_work: !!(ign && ign.checked),
                flow_next_type: radioVal(tr, 'flow_next_type'),
                // 画面に無い（＝行の形が違う）ときは検査対象外にするため null
                flow_next_count: fieldEl(tr, 'flow_next_count') ? fieldVal(tr, 'flow_next_count') : null,
                targets: readTargets(tr),
                to: readDestinations(tr)
            });
        });
        // 選択中の行が消えていたら選択を落とす
        if (selection && selection.type === 'action' && !actionById(selection.id)) {
            selection = null;
        }
    }

    // 変更検知用のシグネチャ（モーダルは change を飛ばさないため値そのものを見る）
    function signature() {
        var block = document.querySelector(BLOCK);
        if (!block) {
            return '';
        }
        var out = [];
        var trs = block.querySelectorAll(ROW);
        Array.prototype.forEach.call(trs, function (tr) {
            var ign = fieldEl(tr, 'ignore_work');
            out.push(
                rowKey(tr),
                fieldVal(tr, '_remove_'),
                fieldVal(tr, 'action_name'),
                fieldVal(tr, 'status_from'),
                fieldVal(tr, 'work_conditions'),
                fieldVal(tr, 'work_condition_select'),
                fieldVal(tr, 'work_targets'),
                radioVal(tr, 'flow_next_type'),
                fieldVal(tr, 'flow_next_count'),
                ign && ign.checked ? '1' : '0'
            );
        });
        return out.join('');
    }

    /* =====================================================================
     * GRAPH / ISSUES
     * ===================================================================== */
    function graph() {
        var nodes = [{ key: 'start', kind: 'start' }];
        statusList.forEach(function (s) {
            nodes.push({ key: String(s.id), kind: s.completed ? 'completed' : 'normal', status: s });
        });
        var known = {};
        nodes.forEach(function (n) { known[n.key] = true; });

        var edges = [], badEdges = [];
        actions.forEach(function (a) {
            a.to.forEach(function (h, hi) {
                if (!h.enabled || !h.status_to) {
                    return;
                }
                var e = {
                    key: a.id + '#' + hi,
                    from: String(a.status_from),
                    to: String(h.status_to),
                    a: a,
                    hi: hi,
                    // 実行前＝実行後は Exment が保存時に弾く（workflow.message.same_action）
                    same: !!a.status_from && String(a.status_from) === String(h.status_to)
                };
                if (!a.status_from || !known[e.from] || !known[e.to]) {
                    badEdges.push(e);
                } else {
                    edges.push(e);
                }
            });
        });
        return { nodes: nodes, edges: edges, badEdges: badEdges };
    }

    // g は renderCanvas から使い回す（描画のたびに 2 回組み立てない）
    function issues(g) {
        var out = [], inn = {}, outn = {};
        g = g || graph();
        g.nodes.forEach(function (n) { inn[n.key] = 0; outn[n.key] = 0; });
        g.edges.forEach(function (e) { outn[e.from]++; inn[e.to]++; });

        if (!outn['start']) {
            out.push({ level: 'err', node: 'start', text: t('issue_no_start', { status: startName }) });
        }
        // start から複数のアクションが出るのは正常（CustomValue::getWorkflowActions は filter）。
        // 以前ここで出していた「複数あります」の警告は誤報だったので出さない。

        // start から到達できるか（幅優先）
        var seen = { start: true }, q = ['start'];
        while (q.length) {
            var u = q.shift();
            g.edges.forEach(function (e) {
                if (e.from === u && !seen[e.to]) {
                    seen[e.to] = true;
                    q.push(e.to);
                }
            });
        }
        statusList.forEach(function (s) {
            var k = String(s.id);
            if (!seen[k]) {
                out.push({ level: 'warn', node: k, text: t('issue_unreachable', { status: s.name }) });
            }
            if (!outn[k] && !s.completed) {
                out.push({ level: 'warn', node: k, text: t('issue_deadend', { status: s.name }) });
            }
        });

        // 以下は Exment の WorkflowController::validateData と同じ判定。
        // 図としては描けても保存で弾かれるものを、保存前に気づけるようにする。
        actions.forEach(function (a) {
            if (!a.name_raw) {
                out.push({ level: 'err', act: a.id, text: t('issue_no_name') });
            } else if (clen(a.name_raw) > 30) {
                out.push({ level: 'err', act: a.id, text: t('issue_long_name', { action: a.name }) });
            }
            if (!a.status_from) {
                out.push({ level: 'err', act: a.id, text: t('issue_no_status_from', { action: a.name }) });
            }
            if (!a.to.some(function (h) { return h.enabled && h.status_to; })) {
                out.push({ level: 'err', act: a.id, text: t('issue_no_status_to', { action: a.name }) });
            }
            // 実行前＝実行後。無効な分岐でも保存時は弾かれるので enabled は見ない。
            if (a.status_from && a.to.some(function (h) {
                return h.status_to && String(h.status_to) === String(a.status_from);
            })) {
                out.push({ level: 'err', act: a.id, text: t('issue_same_action', { action: a.name }) });
            }
            // 実行可能ユーザー。action_select 以外は値が 1 つ以上必要。
            var tg = a.targets || { type: '', filled: false };
            if (tg.type !== TARGET_SELECT && !tg.filled) {
                out.push({ level: 'err', act: a.id, text: t('issue_no_target', { action: a.name }) });
            }
            // 「次のステータスへ進む条件」の人数。PHP は numeric|min:0|max:10。
            if (a.flow_next_count !== null && a.flow_next_count !== undefined) {
                var fc = String(a.flow_next_count).trim(), fn = Number(fc);
                if (fc === '' || !isFinite(fn) || fn < 0 || fn > 10) {
                    out.push({ level: 'err', act: a.id, text: t('issue_flow_next_count', { action: a.name }) });
                }
            }
            if (tg.type === TARGET_SELECT && a.ignore_work) {
                out.push({ level: 'err', act: a.id, text: t('issue_ignore_action_select', { action: a.name }) });
            }
            if (tg.type === TARGET_SELECT && a.status_from) {
                var mixed = actions.some(function (b) {
                    return b !== a && String(b.status_from) === String(a.status_from) &&
                        !b.ignore_work && (b.targets || {}).type !== TARGET_SELECT;
                });
                if (mixed) {
                    out.push({
                        level: 'err', act: a.id,
                        text: t('issue_target_mix', { action: a.name, status: nodeLabel(a.status_from) })
                    });
                }
            }
        });
        g.badEdges.forEach(function (e) {
            if (!e.a.status_from) {
                return; // 上で報告済み
            }
            out.push({ level: 'err', act: e.a.id, text: t('issue_unknown_status', { action: e.a.name }) });
        });
        // 保存を止めるもの（err）を先に見せる
        out.sort(function (x, y) {
            return (x.level === 'err' ? 0 : 1) - (y.level === 'err' ? 0 : 1);
        });
        return out;
    }

    /* =====================================================================
     * LAYOUT — Sugiyama 法（①閉路除去 ②階層割当 ③並び替え ④座標）
     * ===================================================================== */
    function sugiyama(nodes, edges) {
        var keys = nodes.map(function (n) { return n.key; });
        var idx0 = {};
        nodes.forEach(function (n, i) { idx0[n.key] = i; });
        var real = edges.filter(function (e) { return e.from !== e.to; });
        var out = {};
        keys.forEach(function (k) { out[k] = []; });
        real.forEach(function (e) { out[e.from].push(e); });

        // ① DFS で後退辺（閉路を作る辺）を検出。層割当のときだけ向きを逆に読む。
        var st = {}, back = {};
        function dfs(u) {
            st[u] = 1;
            out[u].forEach(function (e) {
                if (st[e.to] === 1) {
                    back[e.key] = true;
                } else if (!st[e.to]) {
                    dfs(e.to);
                }
            });
            st[u] = 2;
        }
        dfs('start');
        keys.forEach(function (k) { if (!st[k]) { dfs(k); } });

        // ② 最長パス法: layer[v] = max(layer[u]+1)。閉路が無いので必ず収束する。
        var layer = {};
        keys.forEach(function (k) { layer[k] = (k === 'start') ? 0 : 1; });
        for (var it = 0; it <= keys.length; it++) {
            var changed = false;
            real.forEach(function (e) {
                var u = back[e.key] ? e.to : e.from;
                var v = back[e.key] ? e.from : e.to;
                if (layer[u] + 1 > layer[v]) {
                    layer[v] = layer[u] + 1;
                    changed = true;
                }
            });
            if (!changed) {
                break;
            }
        }

        // ③ 層内の並びをバリセンタ（隣接ノードの平均位置）で数回そろえる。
        var maxL = 0;
        keys.forEach(function (k) { if (layer[k] > maxL) { maxL = layer[k]; } });
        var cols = [];
        for (var l = 0; l <= maxL; l++) {
            cols.push(keys.filter(function (k) { return layer[k] === l; }));
        }
        cols.forEach(function (c) { c.sort(function (a, b) { return idx0[a] - idx0[b]; }); });

        var pos = {}, nbr = {};
        keys.forEach(function (k) { nbr[k] = { up: [], dn: [] }; });
        real.forEach(function (e) {
            var u = back[e.key] ? e.to : e.from;
            var v = back[e.key] ? e.from : e.to;
            nbr[v].up.push(u);
            nbr[u].dn.push(v);
        });
        cols.forEach(function (c) { c.forEach(function (k, i) { pos[k] = i; }); });
        for (var sweep = 0; sweep < 4; sweep++) {
            var list = sweep % 2 === 0 ? cols : cols.slice().reverse();
            /* eslint-disable no-loop-func */
            list.forEach(function (c) {
                var bary = {};
                c.forEach(function (k) {
                    var ns = sweep % 2 === 0 ? nbr[k].up : nbr[k].dn;
                    bary[k] = ns.length ? ns.reduce(function (s, x) { return s + pos[x]; }, 0) / ns.length : pos[k];
                });
                c.sort(function (a, b) { return (bary[a] - bary[b]) || (idx0[a] - idx0[b]); });
                c.forEach(function (k, i) { pos[k] = i; });
            });
            /* eslint-enable no-loop-func */
        }

        // ④ 座標: 層=Y（上から下）、層内の順=X。層ごとに横センタリング。
        var rows = 0;
        cols.forEach(function (c) { if (c.length > rows) { rows = c.length; } });
        var xy = {};
        cols.forEach(function (c, l) {
            c.forEach(function (k) {
                xy[k] = {
                    x: PAD + pos[k] * SIB_W + (rows - c.length) * SIB_W / 2 + (NODE_W - nw(k)) / 2,
                    y: PAD + l * LAYER_H + (NODE_H - nh(k)) / 2
                };
            });
        });

        var backDrawn = edges.filter(function (e) {
            return e.from !== e.to && layer[e.from] >= layer[e.to];
        });
        var lanes = {};
        backDrawn.forEach(function (e, i) { lanes[e.key] = i; });
        var baseW = PAD * 2 + (rows - 1) * SIB_W + NODE_W;

        return {
            xy: xy,
            layer: layer,
            lanes: lanes,
            w: baseW + (backDrawn.length ? 30 + backDrawn.length * 26 : 0),
            h: PAD * 2 + maxL * LAYER_H + NODE_H,
            baseW: baseW
        };
    }

    /* =====================================================================
     * RENDER
     * ===================================================================== */
    // ノードのツールチップ。枠のどこに触れても出るよう <g> 直下に付ける。
    function nodeTitle(n) {
        if (n.kind === 'start') {
            return startName;
        }
        var chips = [];
        if (n.status.completed) {
            chips.push(t('chip_completed'));
        }
        if (n.status.datalock) {
            chips.push(t('chip_datalock'));
        }
        return n.status.name + (chips.length ? '（' + chips.join('・') + '）' : '');
    }

    function P(key) {
        return manualPos[key] || (lastLayout && lastLayout.xy[key]) || { x: PAD, y: PAD };
    }

    function applyTf() {
        if (world) {
            world.setAttribute('transform', 'translate(' + tf.x + ' ' + tf.y + ') scale(' + tf.k + ')');
        }
    }

    function edgePath(e) {
        var a = P(e.from), b = P(e.to);
        var x1 = a.x + nw(e.from) / 2, y1 = a.y + nh(e.from);
        var x2 = b.x + nw(e.to) / 2 + (edgeFan[e.key] || 0), y2 = b.y;

        if (e.from === e.to) { // 自己ループ: ノード右側に小さく回す
            var rx = a.x + nw(e.from), ry = a.y + nh(e.from) / 2;
            return 'M' + rx + ',' + (ry - 12) +
                ' C' + (rx + 56) + ',' + (ry - 40) + ' ' + (rx + 56) + ',' + (ry + 40) + ' ' + rx + ',' + (ry + 12);
        }
        if (y2 >= y1 + 20) { // 前進（下へ）
            var dy = Math.max(40, (y2 - y1) / 2);
            return 'M' + x1 + ',' + y1 + ' C' + x1 + ',' + (y1 + dy) + ' ' + x2 + ',' + (y2 - dy) + ' ' + x2 + ',' + y2;
        }
        // 後退（差戻し等）: 図の右のレーンを回して対象ステータスの右辺へ入る
        var lane = (lastLayout && lastLayout.lanes[e.key]) || 0;
        var lx = (lastLayout ? lastLayout.baseW : 600) + 10 + lane * 26;
        var sx = a.x + nw(e.from), sy = a.y + nh(e.from) / 2;
        var tx = b.x + nw(e.to), ty = b.y + nh(e.to) / 2;
        return 'M' + sx + ',' + sy + ' C' + lx + ',' + sy + ' ' + lx + ',' + ty + ' ' + tx + ',' + ty;
    }

    function edgeTitle(e) {
        var h = e.a.to[e.hi] || {};
        var s = e.a.name + '： ' + nodeLabel(e.from) + ' → ' + nodeLabel(e.to);
        var multi = e.a.to.filter(function (x) { return x.enabled && x.status_to; }).length > 1;
        if (multi) {
            s += '　［' + t('branch', { no: e.hi + 1 }) + '］';
        }
        if (h.conds) {
            s += '\n' + t('cond_count', { count: h.conds }) +
                '（' + (h.join === 'or' ? t('cond_or') : t('cond_and')) +
                (h.reverse ? '・' + t('cond_reverse') : '') + '）';
        }
        // 「次のステータスへ進む条件」は図に出ていないのでここで補う
        if (e.a.flow_next_type === 'all') {
            s += '\n' + t('tip_flow_all');
        } else if (e.a.flow_next_count !== null && String(e.a.flow_next_count) !== '') {
            s += '\n' + t('tip_flow_some', { count: e.a.flow_next_count });
        }
        if (e.a.ignore_work) {
            s += '\n' + t('tip_ignore');
        }
        return s;
    }

    /* 矢印ラベルの置き場所探し
       線の中点にそのまま置くと、層をまたぐ矢印は途中のノードの上に、
       戻り矢印は同じレーン帯で互いに重なる。ラベルはノードより先に
       描かれるので、重なると下に隠れて文字が読めなくなる。
       そこで中点から線に沿って少しずつずらした候補を順に試し、
       どこにも当たらない最初の位置を採る。全部当たるなら重なりが一番少ない所。 */
    var LAB_T = [0.5, 0.44, 0.56, 0.38, 0.62, 0.32, 0.68, 0.26, 0.74, 0.2, 0.8, 0.14, 0.86];

    function overlapArea(a, b) {
        var w = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
        var h = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
        return (w > 0 && h > 0) ? w * h : 0;
    }

    function findLabelSpot(line, lw, lh, placed) {
        var len;
        try {
            len = line.getTotalLength();
        } catch (ex) {
            return { x: 0, y: 0 };
        }
        // 第1周は線の上をすべらせるだけ。それでも空きが無ければ左右にも逃がす。
        var dxs = [0, lw / 2 + 12, -(lw / 2 + 12)];
        var best = null;
        for (var d = 0; d < dxs.length; d++) {
            for (var i = 0; i < LAB_T.length; i++) {
                var pt;
                try {
                    pt = line.getPointAtLength(len * LAB_T[i]);
                } catch (ex2) {
                    return { x: 0, y: 0 };
                }
                var cx = pt.x + dxs[d], cy = pt.y;
                var r = { x: cx - lw / 2 - 3, y: cy - lh / 2 - 3, w: lw + 6, h: lh + 6 };
                var hit = 0;
                for (var j = 0; j < placed.length; j++) {
                    hit += overlapArea(r, placed[j]);
                }
                if (!hit) {
                    return { x: cx, y: cy };
                }
                // 同じ重なり量なら中点寄り・線の上を優先
                var cost = hit + Math.abs(LAB_T[i] - 0.5) * 60 + (d ? 400 : 0);
                if (!best || cost < best.cost) {
                    best = { x: cx, y: cy, cost: cost };
                }
            }
        }
        return best || { x: 0, y: 0 };
    }

    function renderCanvas() {
        if (!previewOpen || !world) {
            return;
        }
        var g = graph();
        lastLayout = sugiyama(g.nodes, g.edges);

        var iss = issues(g), warnNodes = {};
        iss.forEach(function (i) {
            if (i.node) {
                warnNodes[i.node] = (warnNodes[i.node] || '') + '・' + i.text;
            }
        });

        // 同じノードへ入る前進矢印は上辺に少しずつずらして刺す（先端の重なり防止）
        edgeFan = {};
        var byTo = {};
        g.edges.forEach(function (e) {
            if (e.from === e.to) {
                return;
            }
            if (lastLayout.layer[e.from] >= lastLayout.layer[e.to]) {
                return;
            }
            (byTo[e.to] = byTo[e.to] || []).push(e);
        });
        Object.keys(byTo).forEach(function (k) {
            var arr = byTo[k];
            arr.sort(function (a, b) {
                var pa = P(a.from), pb = P(b.from);
                return (pa.x - pb.x) || (pa.y - pb.y);
            });
            arr.forEach(function (e, i) {
                var off = (i - (arr.length - 1) / 2) * 16;
                var lim = nw(k) / 2 - 16;
                edgeFan[e.key] = Math.max(-lim, Math.min(lim, off));
            });
        });

        var s = '<rect x="-6000" y="-6000" width="12000" height="12000" fill="url(#wd-dots)"></rect>';

        g.edges.forEach(function (e) {
            var sel = selection && selection.type === 'action' &&
                String(selection.id) === String(e.a.id) && selection.hi === e.hi;
            s += '<g class="wd-edge' + (sel ? ' wd-sel' : '') + (e.same ? ' wd-bad' : '') +
                '" data-ek="' + esc(e.key) + '">';
            s += '<path class="wd-line' + (e.a.ignore_work ? ' wd-ign' : '') + '" d="' + edgePath(e) +
                '" marker-end="url(#' + (sel ? 'wd-arr-sel' : e.same ? 'wd-arr-bad' : 'wd-arr') + ')"></path>';
            s += '<path class="wd-hit" d="' + edgePath(e) + '"></path>';
            s += '</g>';
        });

        g.nodes.forEach(function (n) {
            var p = P(n.key), w = nw(n.key), h = nh(n.key);
            var sel = selection && (selection.type === 'status' || selection.type === 'start') &&
                String(selection.id) === n.key;
            var cls = 'wd-node' +
                (n.kind === 'start' ? ' wd-start' : n.kind === 'completed' ? ' wd-completed' : '') +
                (sel ? ' wd-sel' : '');
            s += '<g class="' + cls + '" data-node="' + esc(n.key) +
                '" transform="translate(' + p.x + ' ' + p.y + ')">';
            s += '<title>' + esc(nodeTitle(n)) + '</title>';
            if (n.kind === 'start') {
                s += '<rect class="nbox" width="' + w + '" height="' + h + '" rx="' + (h / 2) + '"></rect>';
                s += '<text class="nm" x="' + (w / 2) + '" y="' + (h / 2 + 5) + '" text-anchor="middle">▶ ' +
                    esc(cut(startName, 8)) + '</text>';
            } else {
                s += '<rect class="nbox" width="' + w + '" height="' + h + '" rx="9"></rect>';
                s += '<rect class="nacc" x="0" y="0" width="6" height="' + h + '" rx="3"></rect>';
                s += '<text class="nm" x="18" y="26">' + esc(cut(n.status.name, 11)) + '</text>';
                var cx = 18;
                if (n.status.completed) {
                    s += '<rect x="' + cx + '" y="37" width="42" height="15" rx="7.5" fill="#00a65a"></rect>' +
                        '<text class="nchip" x="' + (cx + 21) + '" y="48" text-anchor="middle">✓ ' +
                        esc(cut(t('chip_completed'), 4)) + '</text>';
                    cx += 48;
                }
                if (n.status.datalock) {
                    s += '<rect x="' + cx + '" y="37" width="46" height="15" rx="7.5" fill="#8095a8"></rect>' +
                        '<text class="nchip" x="' + (cx + 23) + '" y="48" text-anchor="middle">' +
                        esc(cut(t('chip_datalock'), 5)) + '</text>';
                }
            }
            if (warnNodes[n.key]) {
                s += '<g class="wd-warn" data-node="' + esc(n.key) +
                    '" transform="translate(' + (w - 3) + ' 3)"><title>' + esc(warnNodes[n.key].slice(1)) +
                    '</title><circle r="8"></circle><text y="4" text-anchor="middle">!</text></g>';
            }
            s += '</g>';
        });

        world.innerHTML = s;

        // 矢印ラベルは幅を測ってから置き場所を決めるので、DOM 挿入後に作る。
        // ノードの枠を最初から「ふさがっている場所」として登録しておく。
        var placed = [];
        g.nodes.forEach(function (n) {
            var np = P(n.key);
            placed.push({ x: np.x, y: np.y, w: nw(n.key), h: nh(n.key) });
        });

        g.edges.forEach(function (e) {
            var grp = world.querySelector('[data-ek="' + cssEsc(e.key) + '"]');
            if (!grp) {
                return;
            }
            var line = grp.querySelector('.wd-line');
            var lab = document.createElementNS(NS, 'g');
            lab.setAttribute('class', 'wd-elab');

            var h = e.a.to[e.hi] || {};
            var multi = e.a.to.filter(function (x) { return x.enabled && x.status_to; }).length > 1;
            var txt = document.createElementNS(NS, 'text');
            txt.setAttribute('text-anchor', 'middle');
            txt.setAttribute('y', '4');
            txt.textContent = cut(e.a.name, 9);
            if (multi) {
                var tb = document.createElementNS(NS, 'tspan');
                tb.setAttribute('class', 'br');
                tb.textContent = ' ◆' + (e.hi + 1);
                txt.appendChild(tb);
            }
            if (h.conds) {
                var tc = document.createElementNS(NS, 'tspan');
                tc.setAttribute('class', 'cn');
                tc.textContent = ' ※';
                txt.appendChild(tc);
            }
            lab.appendChild(txt);
            grp.appendChild(lab);

            var bb = txt.getBBox();
            var lw = bb.width + 18, lh = 22;
            var spot = findLabelSpot(line, lw, lh, placed);
            lab.setAttribute('transform', 'translate(' + spot.x + ' ' + spot.y + ')');
            placed.push({ x: spot.x - lw / 2, y: spot.y - lh / 2, w: lw, h: lh });

            var r = document.createElementNS(NS, 'rect');
            r.setAttribute('x', -(lw / 2));
            r.setAttribute('y', -11);
            r.setAttribute('width', lw);
            r.setAttribute('height', lh);
            r.setAttribute('rx', 11);
            lab.insertBefore(r, txt);

            // ツールチップは線に触れても出したいので、ラベルではなく矢印グループに付ける
            var ttl = document.createElementNS(NS, 'title');
            ttl.textContent = edgeTitle(e);
            grp.insertBefore(ttl, grp.firstChild);
        });

        // 手で動かしたノードや外へ逃げたラベルも含めた実寸。⤢（全体表示）で使う。
        var bx0 = 0, by0 = 0, bx1 = lastLayout.w, by1 = lastLayout.h;
        placed.forEach(function (r) {
            bx0 = Math.min(bx0, r.x);
            by0 = Math.min(by0, r.y);
            bx1 = Math.max(bx1, r.x + r.w);
            by1 = Math.max(by1, r.y + r.h);
        });
        lastBounds = { x: bx0, y: by0, w: bx1 - bx0, h: by1 - by0 };

        updateIssuesBadge(iss);
    }

    function updateIssuesBadge(iss) {
        if (!issuesEl) {
            return;
        }
        iss = iss || issues();
        if (!iss.length) {
            issuesEl.className = 'wd-issues';
            issuesEl.textContent = '✓ ' + t('no_issue');
        } else {
            issuesEl.className = 'wd-issues wd-bad';
            issuesEl.textContent = '⚠ ' + t('issue_count', { count: iss.length });
        }
        renderIssuePop(iss);
    }

    function renderIssuePop(iss) {
        if (!issuePop || !issuePop.classList.contains('wd-open')) {
            return;
        }
        iss = iss || issues();
        issuePop.querySelector('.wd-tit').textContent = iss.length
            ? '⚠ ' + t('issue_pop_title', { count: iss.length })
            : t('no_issue');
        issuePop.querySelector('.wd-issuelist').innerHTML = iss.length
            ? iss.map(function (i) {
                var attr = i.act ? ' data-act="' + esc(i.act) + '"'
                    : i.node ? ' data-node="' + esc(i.node) + '"' : '';
                return '<div class="wd-it' + (i.level === 'err' ? ' wd-err' : '') + '"' + attr + '>' +
                    esc(i.text) + '</div>';
            }).join('')
            : '<div class="wd-ok">✓ ' + esc(t('issue_none')) + '</div>';
    }

    /* =====================================================================
     * SELECTSYNC — 図の選択 ⇔ 左テーブルの行
     * ===================================================================== */
    // ノードをクリックしたときに選ぶ行。
    // 「そのステータスへ進む」行だけ――つまりアクション設定（実行後ステータス）が
    // 一致する行を返す。実行前ステータスでの一致は含めない。
    // start は擬似ノードで実行後になり得ないので、そこから出る行を返す。
    function relatedRowIds(statusKey) {
        statusKey = String(statusKey);
        var ids = [];
        actions.forEach(function (a) {
            var hit = statusKey === 'start'
                ? String(a.status_from) === 'start'
                : a.to.some(function (h) { return h.enabled && String(h.status_to) === statusKey; });
            if (hit) {
                ids.push(String(a.id));
            }
        });
        return ids;
    }

    function syncRowMarks(scroll) {
        var block = document.querySelector(BLOCK);
        if (!block) {
            return;
        }
        Array.prototype.forEach.call(block.querySelectorAll('tr.wd-sel-row'), function (tr) {
            tr.classList.remove('wd-sel-row');
        });
        var ids = [];
        if (selection) {
            ids = selection.type === 'action'
                ? [String(selection.id)]
                : relatedRowIds(selection.type === 'start' ? 'start' : selection.id);
        }
        var first = null;
        ids.forEach(function (id) {
            var a = actionById(id);
            if (a && a.el) {
                a.el.classList.add('wd-sel-row');
                if (!first) {
                    first = a.el;
                }
            }
        });
        if (scroll && first) {
            // 画面全体に scroll-behavior:smooth が効いているため instant を明示する。
            // 図をクリックした直後に該当行が見えている必要があるので待たせない。
            first.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' });
        }
    }

    // 選択した矢印が画面外ならキャンバスをパンして見える位置へ
    function panToAction(actId) {
        if (!world) {
            return;
        }
        var line = world.querySelector('.wd-edge[data-ek="' + cssEsc(String(actId) + '#0') + '"] .wd-line');
        if (!line) {
            var any = world.querySelector('.wd-edge[data-ek^="' + cssEsc(String(actId)) + '#"] .wd-line');
            line = any;
        }
        if (!line) {
            return;
        }
        var pt;
        try {
            pt = line.getPointAtLength(line.getTotalLength() * 0.5);
        } catch (ex) {
            return;
        }
        var cw = svg.clientWidth || 800, ch = svg.clientHeight || 500;
        var sx = tf.x + pt.x * tf.k, sy = tf.y + pt.y * tf.k;
        if (sx < 40 || sx > cw - 40 || sy < 40 || sy > ch - 40) {
            tf.x = cw / 2 - pt.x * tf.k;
            tf.y = ch / 2 - pt.y * tf.k;
            applyTf();
        }
    }

    function markEdges(actId, on) {
        if (!world) {
            return;
        }
        Array.prototype.forEach.call(world.querySelectorAll('.wd-edge'), function (g) {
            if (String(g.dataset.ek).split('#')[0] === String(actId)) {
                g.classList.toggle('wd-hl', on);
            }
        });
    }

    // 既存の「新規」ボタンを押して行を追加し、追加行までスクロール＋図をパン
    function addActionRow() {
        var btn = document.querySelector(BLOCK + ' .add');
        if (!btn) {
            return;
        }
        var before = {};
        actions.forEach(function (a) { before[a.id] = true; });
        btn.click();
        setTimeout(function () {
            refresh(true);
            var added = null;
            actions.forEach(function (a) { if (!before[a.id]) { added = a; } });
            if (added) {
                selection = { type: 'action', id: String(added.id), hi: 0 };
                renderCanvas();
            }
            syncRowMarks(true);
        }, 0);
    }

    /* =====================================================================
     * 2ペインの組み立て・分割バー
     * ===================================================================== */
    function buildPanes(block) {
        rightEl = document.getElementById('wd-right');
        toggleBtn = document.getElementById('wd-toggle');
        if (!rightEl) {
            return false;
        }
        splitEl = document.getElementById('wd-split');
        if (!splitEl) {
            // フォームの入力欄をまとめて左ペインへ移し、右にプレビューを置く。
            // 中身は作り替えず移動のみ（hasmanytable-validation.js などが
            // .has-many-table-div や form を closest() で辿るため）。
            var host = block.closest('.fields-group') || block.parentNode;
            splitEl = document.createElement('div');
            splitEl.className = 'wd-split';
            splitEl.id = 'wd-split';
            leftEl = document.createElement('div');
            leftEl.className = 'wd-left';
            leftEl.id = 'wd-left';
            splitbar = document.createElement('div');
            splitbar.className = 'wd-splitbar';
            splitbar.id = 'wd-splitbar';

            var kids = Array.prototype.slice.call(host.children);
            host.appendChild(splitEl);
            splitEl.appendChild(leftEl);
            kids.forEach(function (el) {
                if (el === rightEl || el === toggleBtn || el === splitEl || el.tagName === 'SCRIPT') {
                    return;
                }
                // ステップ表示（track-progress）は画面全体のもの。左ペインに入れると
                // 狭い幅で矢印が潰れて文字が欠けるので、上に横一列のまま残す。
                if (el.querySelector && el.querySelector('.track-progress')) {
                    return;
                }
                leftEl.appendChild(el);
            });
            splitEl.appendChild(splitbar);
            splitEl.appendChild(rightEl);
        } else {
            leftEl = document.getElementById('wd-left');
            splitbar = document.getElementById('wd-splitbar');
        }

        svg = document.getElementById('wd-svg');
        world = document.getElementById('wd-world');
        issuesEl = document.getElementById('wd-issues');
        issuePop = document.getElementById('wd-issuepop');
        saveBtn = document.getElementById('wd-savepos');

        // 表示切替ボタンは「アクション設定」の見出し行へ移す
        var header = block.querySelector('h4.field-header');
        if (toggleBtn && header && !header.contains(toggleBtn)) {
            header.appendChild(toggleBtn);
        }
        return true;
    }

    function setSplit(pct) {
        pct = Math.max(0.34, Math.min(0.72, pct));
        curSplit = pct;
        if (previewOpen && leftEl) {
            leftEl.style.flex = '0 0 ' + (pct * 100).toFixed(2) + '%';
            leftEl.style.maxWidth = (pct * 100).toFixed(2) + '%';
        }
        store(LS_SPLIT, String(pct));
        return pct;
    }

    function store(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (ex) { /* プライベートモード等は保存しないだけ */ }
    }

    function load(key) {
        try {
            return localStorage.getItem(key);
        } catch (ex) {
            return null;
        }
    }

    /* =====================================================================
     * 配置の保存（workflows.options.designer_layout）
     *
     * 保存先はフォームの hidden（name="options[designer_layout]"）。値を書いておけば
     * 画面の「保存」でワークフロー本体と一緒に POST され、他の項目と同じ経路で
     * DB に入る。ここから直接 DB を書きに行かないので、保存前に離脱しても
     * 中途半端なデータが残らない。
     * ===================================================================== */
    // 保存する文字列。キーを並べ替えてから作るので、同じ配置なら必ず同じ文字列になる
    //（＝文字列を比べるだけで「未保存の変更あり」を判定できる）。
    function layoutPayload() {
        var pos = {};
        if (savePos) {
            Object.keys(manualPos).sort().forEach(function (k) {
                pos[k] = { x: manualPos[k].x, y: manualPos[k].y };
            });
        }
        return JSON.stringify({ enabled: savePos, pos: pos });
    }

    function layoutDirty() {
        return !!layoutInput && layoutPayload() !== layoutBase;
    }

    // この画面がまだ生きているか（pjax で別ページに差し替わったら false）
    function alive() {
        return !!svg && document.body.contains(svg);
    }

    // hidden とピンの見た目を今の状態に合わせる
    function syncLayout() {
        var v = layoutPayload();
        if (layoutInput) {
            layoutInput.value = v;
        }
        if (saveBtn) {
            var dirty = v !== layoutBase;
            saveBtn.classList.toggle('wd-on', savePos);
            saveBtn.classList.toggle('wd-dirty', dirty);
            saveBtn.setAttribute('aria-pressed', savePos ? 'true' : 'false');
            saveBtn.setAttribute('title',
                t(savePos ? 'save_pos_on' : 'save_pos_off') + (dirty ? t('save_pos_dirty') : ''));
        }
    }

    // hidden に入っている値を読む。通常は DB の値、入力エラーで戻ってきたときは直前の入力。
    function readLayout() {
        layoutInput = document.querySelector('input[name="options[designer_layout]"]');
        savePos = false;
        manualPos = {};
        var o = null;
        try {
            o = JSON.parse((layoutInput && layoutInput.value) || '');
        } catch (ex) {
            o = null;   // 壊れた値は無視して自動整列に戻す
        }
        if (o && typeof o === 'object') {
            savePos = !!o.enabled;
            if (savePos && o.pos && typeof o.pos === 'object') {
                Object.keys(o.pos).forEach(function (k) {
                    var p = o.pos[k], x = p ? Number(p.x) : NaN, y = p ? Number(p.y) : NaN;
                    if (isFinite(x) && isFinite(y)) {
                        manualPos[k] = { x: x, y: y };
                    }
                });
            }
        }
        layoutBase = layoutPayload();
        if (saveBtn) {
            // 古い PHP のまま js だけ差し替わった場合は、押しても保存できないので隠す
            saveBtn.style.display = layoutInput ? '' : 'none';
        }
    }

    // 消したステータスの座標が残り続けないよう、描けたノードの分だけにする。
    // 掃除しただけで「未保存」にはしない（利用者は何も触っていないため）。
    function pruneLayout() {
        if (!lastLayout || !lastLayout.xy) {
            return;
        }
        Object.keys(manualPos).forEach(function (k) {
            if (!(k in lastLayout.xy)) {
                delete manualPos[k];
            }
        });
        layoutBase = layoutPayload();
    }

    // タブを閉じる・別のページへ移る時に、未保存の配置があれば知らせる。
    // 管理画面のリンクは pjax（部分読み込み）で、その時 beforeunload は発生しない。
    // そのため pjax の送信直前でも確認する。結線は window / document なので 1 回だけ。
    function bindLeaveGuard() {
        if (leaveBound) {
            return;
        }
        leaveBound = true;

        window.addEventListener('beforeunload', function (e) {
            if (!alive() || !layoutDirty()) {
                return;
            }
            e.preventDefault();
            e.returnValue = '';     // 文言はブラウザ既定。これが無いと確認が出ない
        });

        // pjax の GET（リンクでの移動）だけを見る。POST は保存そのものなので通す。
        $(document).on('pjax:beforeSend.wdleave', function (e, xhr, settings) {
            if (!alive() || !layoutDirty()) {
                return;
            }
            if (settings && String(settings.type || 'GET').toUpperCase() !== 'GET') {
                return;
            }
            if (!window.confirm(t('leave_confirm'))) {
                e.preventDefault();     // pjax が送信を取りやめ、この画面に留まる
            }
        });

        // 保存（送信）したら、送った内容が新しい基準になる。
        // 画面上部の検索フォームなど、この値を送らないフォームは対象外。
        $(document).on('submit.wdleave', 'form', function () {
            if (!alive() || !layoutInput || !this.contains(layoutInput)) {
                return;
            }
            layoutBase = layoutPayload();
            syncLayout();
        });
    }

    function togglePreview(show) {
        previewOpen = show === undefined ? !previewOpen : !!show;
        if (rightEl) {
            rightEl.style.display = previewOpen ? '' : 'none';
        }
        if (splitEl) {
            splitEl.classList.toggle('wd-hidden', !previewOpen);
        }
        // 右ペインを sticky で固定するため、.wrapper の overflow を
        // プレビュー表示中だけ外す（css の body.wd-sticky 参照）
        document.body.classList.toggle('wd-sticky', previewOpen);
        if (toggleBtn) {
            toggleBtn.textContent = previewOpen ? t('hide') : t('show');
        }
        store(LS_OPEN, previewOpen ? '1' : '0');
        if (previewOpen) {
            setSplit(curSplit);
            lastRightH = 0;
            sizeRight();
            renderCanvas();
            fit();
        } else if (leftEl) {
            leftEl.style.flex = '';
            leftEl.style.maxWidth = '';
        }
    }

    // 右ペインは position:sticky。位置に合わせて高さを決めないと、
    // 下端（凡例）が画面外に出たままになる。
    var lastRightH = 0;
    function sizeRight() {
        if (!rightEl || !previewOpen) {
            return;
        }
        var top = rightEl.getBoundingClientRect().top;
        var h = Math.max(380, Math.round(window.innerHeight - Math.max(8, top) - 12));
        if (Math.abs(h - lastRightH) < 5) {
            return;             // 高さ変更→位置変化→再計算 のループ防止
        }
        lastRightH = h;
        rightEl.style.height = h + 'px';
    }

    function fit() {
        if (!lastBounds || !svg) {
            return;
        }
        var cw = svg.clientWidth || 800, ch = svg.clientHeight || 500;
        var b = lastBounds;
        var k = Math.min((cw - 60) / b.w, (ch - 60) / b.h, 1);
        k = Math.max(0.3, k);
        // 横は中央、縦は上寄せ。右ペインは sticky で下がはみ出すことがあるため、
        // 上寄せにしておくとスクロール前でも図の先頭（開始ステータス）が見える。
        // b.x / b.y は手でノードを動かして図が左や上へ広がったときだけ 0 以外になる。
        var x = b.w * k > cw - 40 ? 24 : (cw - b.w * k) / 2;
        tf = { x: x - b.x * k, y: 20 - b.y * k, k: k };
        applyTf();
    }

    /* =====================================================================
     * 更新のトリガ
     * ===================================================================== */
    function refresh(force) {
        var s = signature();
        if (!force && s === sig) {
            return false;
        }
        sig = s;
        scan();
        renderCanvas();
        syncRowMarks(false);
        return true;
    }

    function schedule() {
        clearTimeout(schedTimer);
        schedTimer = setTimeout(function () { refresh(false); }, 60);
    }

    /* =====================================================================
     * イベント結線
     * ===================================================================== */
    function bindCanvas() {
        if (!svg || svg.getAttribute('data-wd-bound') === '1') {
            return;     // 同じ DOM で boot が 2 回走ったときの二重結線を防ぐ
        }
        svg.setAttribute('data-wd-bound', '1');

        var mode = null, dragRaf = null;

        function snap(v) { return Math.round(v / 8) * 8; }

        svg.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) {
                return;
            }
            try {
                svg.setPointerCapture(e.pointerId);
            } catch (ex) { /* 未対応ブラウザは捕捉なしで動く */ }

            var edge = e.target.closest ? e.target.closest('.wd-edge') : null;
            if (edge) {
                // 矢印クリック → 左の該当行を選択して見える位置へスクロール
                var ek = String(edge.dataset.ek).split('#');
                selection = { type: 'action', id: ek[0], hi: parseInt(ek[1], 10) || 0 };
                renderCanvas();
                syncRowMarks(true);
                mode = null;
                return;
            }
            var node = e.target.closest ? e.target.closest('.wd-node') : null;
            if (node) {
                var key = node.dataset.node;
                mode = {
                    kind: 'node', key: key, sx: e.clientX, sy: e.clientY,
                    orig: { x: P(key).x, y: P(key).y }, moved: false
                };
                e.preventDefault();
                return;
            }
            mode = { kind: 'pan', sx: e.clientX, sy: e.clientY, orig: { x: tf.x, y: tf.y }, moved: false };
            svg.classList.add('wd-panning');
        });

        svg.addEventListener('pointermove', function (e) {
            if (!mode) {
                return;
            }
            var dx = e.clientX - mode.sx, dy = e.clientY - mode.sy;
            if (Math.abs(dx) + Math.abs(dy) > 3) {
                mode.moved = true;
            }
            if (mode.kind === 'pan' && mode.moved) {
                tf.x = mode.orig.x + dx;
                tf.y = mode.orig.y + dy;
                applyTf();
            } else if (mode.kind === 'node' && mode.moved) {
                manualPos[mode.key] = { x: snap(mode.orig.x + dx / tf.k), y: snap(mode.orig.y + dy / tf.k) };
                // 高ポーリングのマウスだと pointermove は 1 フレームに何度も飛ぶ。
                // 描き直しは重い（全ノード・全ラベルの再配置）ので 1 フレーム 1 回にまとめる。
                if (!dragRaf) {
                    dragRaf = requestAnimationFrame(function () {
                        dragRaf = null;
                        renderCanvas();
                        syncLayout();
                    });
                }
            }
        });

        svg.addEventListener('pointerup', function () {
            if (!mode) {
                return;
            }
            var m = mode;
            mode = null;
            svg.classList.remove('wd-panning');
            if (m.kind === 'node' && m.moved) {
                // ドラッグ確定。1 フレームに 1 回の描き直しが間に合っていなくても
                // ここで hidden とピンの表示を必ず合わせる。
                syncLayout();
                return;
            }
            if (m.kind === 'node' && !m.moved) {
                // ステータスクリック → そのステータスを使う行を全てハイライトして先頭へスクロール
                selection = m.key === 'start' ? { type: 'start', id: 'start' } : { type: 'status', id: m.key };
                renderCanvas();
                syncRowMarks(true);
                return;
            }
            if (m.kind === 'pan' && !m.moved) {
                selection = null;
                renderCanvas();
                syncRowMarks(false);
            }
        });

        svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            var r = svg.getBoundingClientRect();
            var mx = e.clientX - r.left, my = e.clientY - r.top;
            var k2 = Math.min(2.5, Math.max(0.35, tf.k * Math.exp(-e.deltaY * 0.0012)));
            tf.x = mx - (mx - tf.x) * k2 / tf.k;
            tf.y = my - (my - tf.y) * k2 / tf.k;
            tf.k = k2;
            applyTf();
        }, { passive: false });

        svg.addEventListener('mouseover', function (e) {
            var g = e.target.closest ? e.target.closest('.wd-edge') : null;
            if (!g) {
                return;
            }
            var a = actionById(String(g.dataset.ek).split('#')[0]);
            if (a && a.el) {
                a.el.classList.add('wd-hl-row');
            }
        });

        svg.addEventListener('mouseout', function (e) {
            var g = e.target.closest ? e.target.closest('.wd-edge') : null;
            if (!g) {
                return;
            }
            var block = document.querySelector(BLOCK);
            if (!block) {
                return;
            }
            Array.prototype.forEach.call(block.querySelectorAll('tr.wd-hl-row'), function (tr) {
                tr.classList.remove('wd-hl-row');
            });
        });
    }

    function bindDocument() {
        var $doc = $(document);
        $doc.off('.wfdesigner');

        // 行の編集で図を更新（select2 / iCheck は jQuery イベントしか出さない）
        $doc.on('change.wfdesigner input.wfdesigner ifChanged.wfdesigner', BLOCK + ' :input', schedule);
        // 行の追加・削除（HasManyTable が発火）
        $doc.on('admin_hasmany_row_change.wfdesigner', schedule);
        // 「変更」モーダルは値を .val() で書くだけなので、クリックを拾って遅延更新する
        $doc.on('click.wfdesigner', '.modal-submit, .modal-reset', function () {
            setTimeout(function () { refresh(false); }, 80);
        });

        // 行クリック → その行の矢印を選択
        $doc.on('click.wfdesigner', BLOCK + ' ' + ROW, function (e) {
            if (e.target.closest('input, select, button, a, label, .select2-container, .btn')) {
                return;
            }
            var a = actionById(this.getAttribute('data-wd-act'));
            if (!a) {
                return;
            }
            selection = { type: 'action', id: String(a.id), hi: 0 };
            if (!previewOpen) {
                togglePreview(true);
            }
            renderCanvas();
            panToAction(a.id);
            syncRowMarks(false);
        });

        // 行ホバー ⇔ 矢印ハイライト
        $doc.on('mouseenter.wfdesigner', BLOCK + ' ' + ROW, function () {
            if (previewOpen) {
                markEdges(this.getAttribute('data-wd-act'), true);
            }
        });
        $doc.on('mouseleave.wfdesigner', BLOCK + ' ' + ROW, function () {
            markEdges(this.getAttribute('data-wd-act'), false);
        });

        // 要確認ポップアップは外側をクリックしたら閉じる
        $doc.on('click.wfdesigner', function (e) {
            if (!issuePop || !issuePop.classList.contains('wd-open')) {
                return;
            }
            if (issuePop.contains(e.target) || (issuesEl && issuesEl.contains(e.target))) {
                return;
            }
            issuePop.classList.remove('wd-open');
        });

        $doc.on('keydown.wfdesigner', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            if (selection || (issuePop && issuePop.classList.contains('wd-open'))) {
                selection = null;
                if (issuePop) {
                    issuePop.classList.remove('wd-open');
                }
                renderCanvas();
                syncRowMarks(false);
            }
        });

        var raf = null;
        $(window).off('.wfdesigner')
            .on('resize.wfdesigner', function () {
                // 入力更新用の schedTimer とは分ける（共用すると片方が取り消される）
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () { lastRightH = 0; sizeRight(); fit(); }, 150);
            })
            .on('scroll.wfdesigner', function () {
                if (raf) {
                    return;
                }
                raf = requestAnimationFrame(function () { raf = null; sizeRight(); });
            });
    }

    function bindToolbar() {
        if (!rightEl || rightEl.getAttribute('data-wd-tb') === '1') {
            return;     // 同じ DOM で boot が 2 回走ったときの二重結線を防ぐ
        }
        rightEl.setAttribute('data-wd-tb', '1');

        var on = function (id, fn) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', fn);
            }
        };
        on('wd-toggle', function () { togglePreview(); });
        on('wd-add', function () {
            if (!previewOpen) {
                togglePreview(true);
            }
            addActionRow();
        });
        on('wd-relayout', function () {
            manualPos = {};
            renderCanvas();
            fit();
            syncLayout();
        });
        on('wd-savepos', function () {
            savePos = !savePos;
            syncLayout();
        });
        on('wd-zin', function () { tf.k = Math.min(2.5, tf.k * 1.2); applyTf(); });
        on('wd-zout', function () { tf.k = Math.max(0.35, tf.k / 1.2); applyTf(); });
        on('wd-fit', fit);
        on('wd-issues', function () {
            if (!issuesEl.classList.contains('wd-bad')) {
                return;
            }
            if (!previewOpen) {
                togglePreview(true);
            }
            issuePop.classList.toggle('wd-open');
            renderIssuePop();
        });

        if (issuePop) {
            issuePop.addEventListener('click', function (e) {
                var it = e.target.closest ? e.target.closest('.wd-it') : null;
                if (!it) {
                    return;
                }
                if (it.dataset.act) {
                    selection = { type: 'action', id: String(it.dataset.act), hi: 0 };
                    renderCanvas();
                    panToAction(it.dataset.act);
                    syncRowMarks(true);
                } else if (it.dataset.node) {
                    selection = it.dataset.node === 'start'
                        ? { type: 'start', id: 'start' }
                        : { type: 'status', id: it.dataset.node };
                    renderCanvas();
                    syncRowMarks(true);
                }
            });
        }

        if (splitbar) {
            splitbar.addEventListener('pointerdown', function (e) {
                if (e.button !== 0) {
                    return;
                }
                e.preventDefault();
                splitbar.classList.add('wd-drag');
                document.body.style.userSelect = 'none';
                var move = function (ev) {
                    var r = splitEl.getBoundingClientRect();
                    if (r.width) {
                        setSplit((ev.clientX - r.left) / r.width);
                    }
                };
                var up = function () {
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', up);
                    splitbar.classList.remove('wd-drag');
                    document.body.style.userSelect = '';
                    fit();
                };
                window.addEventListener('pointermove', move);
                window.addEventListener('pointerup', up);
            });
        }
    }

    /* =====================================================================
     * BOOT
     * ===================================================================== */
    function boot(options) {
        options = options || {};
        texts = options.texts || {};
        statusList = (options.statuses || []).map(function (s) {
            return {
                id: String(s.id),
                name: String(s.name),
                completed: !!s.completed,
                datalock: !!s.datalock
            };
        });
        startName = options.start_name || 'start';

        var block = document.querySelector(BLOCK);
        if (!block) {
            return;     // Step2 以外の画面では何もしない
        }
        if (!buildPanes(block)) {
            return;
        }

        selection = null;
        readLayout();
        sig = '';
        bindCanvas();
        bindDocument();
        bindToolbar();

        var sp = parseFloat(load(LS_SPLIT));
        curSplit = (isFinite(sp) && sp > 0.2 && sp < 0.9) ? sp : 0.56;
        togglePreview(load(LS_OPEN) !== '0');

        refresh(true);
        sizeRight();
        fit();
        pruneLayout();
        syncLayout();
        bindLeaveGuard();

        clearInterval(pollTimer);
        pollTimer = setInterval(function () {
            if (!document.body.contains(svg)) {   // pjax で画面が入れ替わった
                document.body.classList.remove('wd-sticky');
                clearInterval(pollTimer);
                pollTimer = null;
                return;
            }
            if (document.hidden) {
                return;
            }
            refresh(false);
        }, 900);
    }

    class WorkflowDesigner {
        /**
         * @param {object} options {statuses:[], start_name:string, texts:{}}
         */
        static boot(options) {
            boot(options);
        }

        /** 外部（テスト等）から現在の状態を覗くためのヘルパ */
        static state() {
            return {
                statuses: statusList,
                actions: actions.map(function (a) {
                    return { id: a.id, name: a.name, status_from: a.status_from, to: a.to, ignore_work: a.ignore_work };
                }),
                selection: selection,
                issues: issues(),
                layout: lastLayout,
                open: previewOpen,
                split: curSplit,
                save_pos: savePos,
                layout_dirty: layoutDirty(),
                layout_payload: layoutPayload()
            };
        }

        static refresh() {
            refresh(true);
        }
    }

    Exment.WorkflowDesigner = WorkflowDesigner;
})(Exment || (Exment = {}));
