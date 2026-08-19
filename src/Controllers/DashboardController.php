<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Auth\Permission as Checker;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DataShareAuthoritable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Form\Tools\DashboardMenu;
use Exceedone\Exment\Form\Tools\ShareButton;
use Exceedone\Exment\Services\AiChatService;
use Exceedone\Exment\Services\Dashboard\BoxFilterScope;
use Exceedone\Exment\Services\Dashboard\FilterBarContextBuilder;
use Exceedone\Exment\Services\Dashboard\FilterBarCoverage;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\ShareTargetType;

class DashboardController extends AdminControllerBase
{
    use HasResourceActions;
    // @phpstan-ignore-next-line
    protected $dashboard;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("dashboard.header"), exmtrans("dashboard.header"), null, 'fa-home');
    }

    // @phpstan-ignore-next-line
    protected function setDashboardInfo(Request $request)
    {
        $this->dashboard = Dashboard::getDefault();
    }

    /**
     * @param Request $request
     * @param Content $content
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function index(Request $request, Content $content)
    {
        return redirect(admin_url(''));
    }

    /**
     * Edit interface.
     *
     * @param Request $request
     * @param Content $content
     * @param string|int|null $id
     * @return Content|false
     */
    public function edit(Request $request, Content $content, $id)
    {
        $this->setDashboardInfo($request);

        // check has system permission
        $dashboard = Dashboard::find($id);
        if (!$dashboard || !$dashboard->hasEditPermission()) {
            Checker::notFoundOrDeny();
            return false;
        }

        return parent::edit($request, $content, $id);
    }

    /**
     * Create interface.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|false
     */
    public function create(Request $request, Content $content)
    {
        $this->setDashboardInfo($request);
        // check has system permission or acceptable user view
        if (!Dashboard::hasPermission()) {
            Checker::error();
            return false;
        }
        return parent::create($request, $content);
    }

    // @phpstan-ignore-next-line
    public function home(Request $request, Content $content)
    {
        // check permission. if not permission, show message
        if (\Exment::user()->noPermission()) {
            admin_warning(trans('admin.deny'), exmtrans('common.help.no_permission'));
        }
        // if system admin, check version
        $this->showVersionUpdate();

        $this->setDashboardInfo($request);
        $this->AdminContent($content);
        // add dashboard header
        $content->row((new DashboardMenu($this->dashboard))->render());

        // drill-down breadcrumb (shown when this dashboard declares a parent)
        $this->setDrillBreadcrumb($content);

        // dashboard filter bar (config-driven via options.filter_bar); null when not configured
        $filterContext = $this->buildDashboardFilterContext($request);
        if ($filterContext) {
            // Wrapped in an id'd container: a filter change re-renders ONLY this fragment
            // (server-side cascade + breadcrumb) instead of swapping the whole page, so the
            // boxes that a filter cannot change keep their rendered chart untouched. The
            // wrapper must contain the blade's own <script> too — jQuery re-executes it on
            // replace, which is what re-binds the fresh selects.
            $content->row('<div id="exment-df-bar-wrap">'
                . view('exment::dashboard.filter_bar', $filterContext)->render() . '</div>');
        }

        // Dashboard rows flex on md+ so a column hidden by chart_level_visible frees its space
        // to the remaining boxes (every column in a row is equal-width by construction, so
        // equal flex-grow preserves the normal layout). Below md, bootstrap stacking applies.
        // Plus the shared styles of the per-chart TOOLBAR (ChartItem::chartToolbarHtml):
        // one pill row + popovers, in the filter bar's visual language.
        $content->row('<style>@media (min-width:992px){'
            . '.row-dashboard{display:flex;flex-wrap:wrap}'
            . '.row-dashboard>div[class*="col-"]{flex:1 1 0;max-width:none;min-width:0}'
            . '}'
            . '.chart-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:0 8px 6px;padding:6px 8px;background:#fbfaff;border:1px solid #ecebf5;border-radius:8px}'
            . '.chart-toolbar .ct-spacer{flex:1}'
            . '.chart-toolbar .ct-item{position:relative}'
            . '.ct-btn{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 11px;background:#fff;border:1px solid #d7d5e6;border-radius:14px;cursor:pointer;font-size:12px;color:#2b2b3a;white-space:nowrap}'
            . '.ct-btn:hover{border-color:#5b6ef5}'
            . '.ct-btn.open{border-color:#5b6ef5;box-shadow:0 0 0 3px #eef1fd}'
            . '.ct-btn .ct-lbl{font-size:11px;font-weight:600;color:#9a9ab0}'
            . '.ct-btn .ct-val{font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis}'
            . '.ct-btn .ct-car{font-size:9px;color:#b9b9cc}'
            . '.ct-btn .ct-cnt{min-width:16px;height:16px;padding:0 5px;border-radius:8px;background:#5b6ef5;color:#fff;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}'
            . '.ct-sel{height:28px;border:1px solid #d7d5e6;border-radius:14px;background:#fff;font-size:12px;color:#2b2b3a;padding:0 8px;max-width:180px}'
            . '.ct-pop{position:absolute;top:34px;left:0;z-index:1030;min-width:215px;background:#fff;border:1px solid #d7d5e6;border-radius:10px;box-shadow:0 10px 28px rgba(43,43,58,.14);padding:10px 12px;display:none;text-align:left}'
            . '.ct-pop.right{left:auto;right:0;min-width:390px}'
            . '.ct-pop.show{display:block}'
            . '.ct-pop h4{font-size:10px;font-weight:700;letter-spacing:.06em;color:#9a9ab0;margin:0 0 7px}'
            . '.ct-fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:7px 14px;max-height:340px;overflow-y:auto}'
            . '.ct-fitem{display:flex;flex-direction:column;gap:2px}'
            . '.ct-fitem.dis{opacity:.55}'
            . '.ct-fitem span{font-size:10.5px;font-weight:600;color:#9a9ab0}'
            . '.ct-fitem select{height:28px;border:1px solid #d7d5e6;border-radius:6px;background:#fbfaff;font-size:12.5px;color:#2b2b3a;padding:0 6px;min-width:0;width:100%}'
            . '.ct-fitem select.active{border-color:#5b6ef5;background:#eef1fd}'
            . '.ct-fsel{display:inline-block;min-width:15px;text-align:center;margin-left:5px;padding:0 4px;border-radius:8px;background:#5b6ef5;color:#fff;font-size:9.5px;font-style:normal;line-height:14px}'
            // caption under the toolbar naming the box's ACTIVE chart-level filters (児童: 竹内 湊 ・ クラス: 4-B)
            . '.ct-active{display:flex;flex-wrap:wrap;align-items:center;gap:2px 8px;margin:-4px 2px 6px;font-size:11.5px;color:#6b6b80;line-height:16px}'
            . '.ct-active .fa{color:#5b6ef5;font-size:10.5px;margin-right:2px}'
            . '.ct-active b{font-weight:600;color:#3b4a9e}'
            . '.ct-active-sep{color:#b6b6c8}'
            // chart-level filter checklist (multi-select) + from/to range pair
            . '.exment-bf-list{border:1px solid #d7d5e6;border-radius:6px;background:#fbfaff;padding:3px 4px}'
            . '.exment-bf-list.active{border-color:#5b6ef5;background:#eef1fd}'
            . '.exment-bf-opts{max-height:150px;overflow-y:auto}'
            . '.exment-bf-list label{display:flex;align-items:center;gap:6px;margin:0;padding:2px 3px;font-weight:normal;font-size:12.5px;color:#2b2b3a;border-radius:4px;cursor:pointer}'
            . '.exment-bf-list label:hover{background:#e6e9fb}'
            . '.exment-bf-list label.on{font-weight:600}'
            . '.exment-bf-list label.miss{display:none}'
            . '.exment-bf-list label span{font-size:12.5px;font-weight:inherit;color:inherit;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.exment-bf-list input[type=checkbox]{margin:0;flex:none}'
            . '.exment-bf-list small{display:block;padding:4px 3px;color:#9a9ab0;font-size:11px}'
            // type-to-find box on a long checklist (client-side; the list below narrows as you type)
            . '.exment-bf-search{display:block;width:100%;height:26px;margin:1px 0 4px;border:1px solid #d7d5e6;border-radius:5px;background:#fff;font-size:12px;color:#2b2b3a;padding:0 7px}'
            . '.exment-bf-search:focus{outline:none;border-color:#5b6ef5}'
            . '.ct-frange{display:flex;align-items:center;gap:4px}'
            . '.ct-frange i{color:#b6b6c8;font-style:normal;font-size:12px}'
            . '.exment-bf-range{flex:1 1 0;min-width:0;height:28px;border:1px solid #d7d5e6;border-radius:6px;background:#fbfaff;font-size:12.5px;color:#2b2b3a;padding:0 6px}'
            . '.exment-bf-range.active{border-color:#5b6ef5;background:#eef1fd}'
            . '.ct-pfoot{display:flex;justify-content:flex-end;margin-top:8px}'
            . '.ct-pfoot a{font-size:11.5px;color:#5b6ef5;cursor:pointer}'
            . '@media (max-width:600px){.ct-pop.right{min-width:260px}.ct-fgrid{grid-template-columns:1fr}}'
            . '</style>');

        //set row
        for ($i = 1; $i <= intval(config('exment.dashboard_rows', 4)); $i++) {
            $row_name = 'row'.$i;
            $row_column = intval($this->dashboard->getOption($row_name));
            if ($row_column > 0) {
                $this->setDashboardBox($content, $row_column, $i);
            }
        }

        // set dashboard box --------------------------------------------------
        $delete_confirm = trans('admin.delete_confirm');
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');
        $error = exmtrans('error.header');
        // texts the lazy chart-filter popover builds into HTML on the client (see exmentBfLoad)
        $loading = esc_html(exmtrans('dashboard.box_filter.loading'));
        $narrow_first = esc_html(exmtrans('dashboard.filter_bar.narrow_first'));
        $search = esc_html(trans('admin.search'));

        $script = <<<EOT
        $(function () {
            // get suuid inputs
            var suuids = $('[data-suuid]');
            // add 'row-eq-height' class
            suuids.parents('.row').addClass('row-eq-height row-dashboard');
            suuids.each(function(index, element){
                var suuid = $(element).data('suuid');
                // remember which filter state this render corresponds to (see exmentBoxSig)
                $(element).data('dfSig', exmentBoxSig(element));
                loadDashboardBox(suuid);
            });

            ///// delete click event
            $('[data-exment-widget="delete"]').off('click').on('click', function(ev){
                // get suuid
                var suuid = $(ev.target).closest('[data-suuid]').data('suuid');
                var url = admin_url('dashboardbox/delete/' + suuid);
                Exment.CommonEvent.ShowSwal(url, {
                    title: "$delete_confirm",
                    confirm:"$confirm",
                    method: 'delete',
                    cancel:"$cancel",
                });
            });

            ///// reload click event
            $('[data-exment-widget="reload"]').off('click').on('click', function(ev){
                // get suuid
                var target = $(ev.target).closest('[data-suuid]');
                var suuid = target.data('suuid');
                loadDashboardBox(suuid);
            });

            ///// click dashboard link event
            $(document).off('click.exment_dashboard', '[data-ajax-link]').on('click.exment_dashboard', '[data-ajax-link]', [], function(ev){
                // get link
                var url = $(ev.target).closest('[data-ajax-link]').data('ajax-link');
                var suuid = $(ev.target).closest('[data-suuid]').data('suuid');
                loadDashboardBox(suuid, url);
            });

            ///// runtime chart-type switcher (select rendered inside a chart body).
            // Presentation-only and page-lifetime by design: the choice is kept per box in
            // exmentCtSel, forwarded as `ct` on the box AJAX, and re-validated server-side
            // (ChartRendererRegistry). Reload / pjax navigation returns to the configured type.
            $(document).off('change.exment_ct', '.exment-ct-switch').on('change.exment_ct', '.exment-ct-switch', function(){
                var suuid = $(this).closest('[data-suuid]').data('suuid');
                if(!hasValue(suuid)){
                    return;
                }
                exmentCtSel[suuid] = $(this).val();
                loadDashboardBox(suuid);
            });

            ///// chart TOOLBAR popovers (ChartItem::chartToolbarHtml): one delegated click
            // handler toggles the pill buttons' panels and closes them on any outside click
            // (clicks INSIDE a panel keep it open — its selects reload the box).
            $(document).off('click.exment_tb').on('click.exment_tb', function(ev){
                var btn = $(ev.target).closest('.ct-btn[data-pop]');
                if(btn.length){
                    var pop = btn.closest('.ct-item').find('.ct-pop').first();
                    var show = !pop.hasClass('show');
                    $('.ct-pop').removeClass('show');
                    $('.ct-btn').removeClass('open');
                    if(show){
                        pop.addClass('show'); btn.addClass('open'); exmentPlacePop(pop);
                        // the filter panel's option lists load on first open (see exmentBfLoad)
                        if(btn.data('pop') === 'filters'){ exmentBfLoad(btn.closest('[data-suuid]')); }
                    }
                    return;
                }
                if($(ev.target).closest('.ct-pop').length){ return; }
                $('.ct-pop').removeClass('show');
                $('.ct-btn').removeClass('open');
            });

            ///// chart-level filter fields (rendered inside a chart's toolbar popover by
            // ChartItem::toolbarFilterHtml: a checklist per column, or a from/to pair for a
            // number/date column). Page-lifetime like the chart-type switcher: the selection
            // lives in exmentBfSel per box and is forwarded as bf_{column} on THIS box's AJAX
            // only — the page URL, the dashboard filter bar and every other box stay untouched;
            // pjax/filter navigation resets it. The re-rendered body echoes the selection back
            // (checked / filled) server-side, so state survives the reload.
            // Value shapes = the ones FilterState reads: one value → bf_col=v; several →
            // bf_col[]=v…; range → bf_col[from]=x / bf_col[to]=y.
            // (assigned to the outer var so loadDashboardBox can re-collect after a render)
            exmentBfCollect = function(target){
                var sel = {};
                target.find('.exment-bf-list').each(function(){
                    var col = $(this).data('column');
                    if(!hasValue(col)){ return; }
                    var vals = [];
                    $(this).find('.exment-bf-check:checked').each(function(){ vals.push(String($(this).val())); });
                    if(vals.length){ sel[col] = vals; }
                });
                target.find('.exment-bf-range').each(function(){
                    var col = $(this).data('column'), bound = $(this).data('bound'), v = String($(this).val() || '').trim();
                    if(!hasValue(col) || !v){ return; }
                    if(!sel[col] || $.isArray(sel[col])){ sel[col] = {}; }
                    sel[col][bound] = v;
                });
                return sel;
            };
            $(document).off('change.exment_bf', '.exment-bf-check, .exment-bf-range').on('change.exment_bf', '.exment-bf-check, .exment-bf-range', function(){
                var target = $(this).closest('[data-suuid]');
                var suuid = target.data('suuid');
                if(!hasValue(suuid)){
                    return;
                }
                exmentBfSel[suuid] = exmentBfCollect(target);
                exmentPopReopen[suuid] = 'filters';
                loadDashboardBox(suuid);
            });
            $(document).off('keydown.exment_bf', '.exment-bf-range').on('keydown.exment_bf', '.exment-bf-range', function(ev){
                if(ev.key === 'Enter'){ ev.preventDefault(); $(this).blur(); }
            });
            // type-to-find on a long checklist: hides the non-matching rows client-side only —
            // no reload, and a checked row that is hidden stays checked (and active).
            $(document).off('input.exment_bfs keyup.exment_bfs', '.exment-bf-search').on('input.exment_bfs keyup.exment_bfs', '.exment-bf-search', function(ev){
                if(ev.type === 'keyup' && ev.key === 'Enter'){ ev.preventDefault(); return; }
                var q = String($(this).val() || '').toLowerCase();
                $(this).closest('.exment-bf-list').find('.exment-bf-opts label').each(function(){
                    var hit = q === '' || $(this).text().toLowerCase().indexOf(q) >= 0;
                    $(this).toggleClass('miss', !hit);
                });
            });
            $(document).off('keydown.exment_bfs', '.exment-bf-search').on('keydown.exment_bfs', '.exment-bf-search', function(ev){
                if(ev.key === 'Enter'){ ev.preventDefault(); }
            });
            $(document).off('click.exment_bfr', '.exment-bf-reset').on('click.exment_bfr', '.exment-bf-reset', function(){
                var suuid = $(this).closest('[data-suuid]').data('suuid');
                if(!hasValue(suuid)){ return; }
                delete exmentBfSel[suuid];
                exmentPopReopen[suuid] = 'filters';
                loadDashboardBox(suuid);
            });
        });

        // Keep a toolbar popover inside its own card. The panel is anchored to its button
        // (position:relative on .ct-item), so on a narrow box — three charts in one row —
        // a wide panel like the filter one runs past the card edge and the card clips it,
        // cutting the heading and the first column. Measure once on open and pull it back:
        // shrink it when the card itself is narrower than the panel, then align whichever
        // edge overflowed to the card's inner edge.
        function exmentPlacePop(pop){
            if(!pop || !pop.length){
                return;
            }
            var card = pop.closest('.box');
            var item = pop.closest('.ct-item');
            if(!card.length || !item.length){
                return;
            }
            var PAD = 8;
            pop.css({left: '', right: '', 'min-width': '', 'max-width': ''});

            var cardRect = card[0].getBoundingClientRect();
            var room = cardRect.width - PAD * 2;
            // a panel may ask for a width of its own (data-pop-width, e.g. the filter popover
            // sized by its field count) — honoured up to the room the card offers
            var want = parseInt(pop.attr('data-pop-width'), 10) || 0;
            if(want > 0){
                pop.css('min-width', Math.min(want, room) + 'px');
            }
            if(pop[0].getBoundingClientRect().width > room){
                // min-width beats max-width in CSS, so both have to give way
                pop.css({'min-width': 0, 'max-width': room + 'px'});
            }

            var popRect = pop[0].getBoundingClientRect();
            var itemRect = item[0].getBoundingClientRect();
            if(popRect.left < cardRect.left + PAD){
                pop.css({left: (cardRect.left + PAD - itemRect.left) + 'px', right: 'auto'});
            } else if(popRect.right > cardRect.right - PAD){
                pop.css({left: 'auto', right: (itemRect.right - (cardRect.right - PAD)) + 'px'});
            }
        }

        // per-box runtime chart-type override map (suuid -> chart type)
        var exmentCtSel = {};
        // per-box chart-level filter map (suuid -> {column: value})
        var exmentBfSel = {};
        // reads a box's chart-level filter fields into that map shape (set in the ready handler)
        var exmentBfCollect = null;
        // toolbar panel to re-open after the reload it triggered (suuid -> data-pop id)
        var exmentPopReopen = {};

        // Forward the dashboard filter (df_* query params on the dashboard URL) to each box's AJAX
        // request, so ChartItem::applyDashboardFilter can scope the box to the current selection.
        // Column-agnostic: forwards every df_* param the URL carries.
        function dfQuery(){
            var p = new URLSearchParams(window.location.search);
            var out = [];
            p.forEach(function(v, k){
                // df_{column} = the filter bar's equality items; dfa[i][c|o|v] = the Detailed
                // Filter conditions. Both are dashboard-level, so both travel to every box.
                if ((k.indexOf('df_') === 0 || k.indexOf('dfa[') === 0) && v) {
                    out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                }
            });
            return out.length ? ('?' + out.join('&')) : '';
        }

        // Which part of the dashboard filter state THIS box's output depends on:
        //   - the values of the df_ columns that actually narrow it (data-df-cols, computed
        //     server-side by BoxFilterScope with the same targeting gate the query uses), and
        //   - which OTHER columns are active, because those drive its "not affected /
        //     partially affected" badge — that badge is part of the box's output too.
        // Same signature before and after a filter change = the box would re-render byte for
        // byte, so there is nothing to fetch and its chart is left untouched.
        function exmentBoxSig(el){
            var params = new URLSearchParams(window.location.search);
            var cols = (el.getAttribute('data-df-cols') || '').split(',').filter(function(c){ return c !== ''; });
            var own = [], other = [];
            params.forEach(function(v, k){
                if(k.indexOf('df_') !== 0 || !v){
                    return;
                }
                // df_col, df_col[] (several values) and df_col[from]/[to] (range) all belong
                // to the same column — strip the bracket suffix for the membership test
                var col = k.substring(3).replace(/\[.*$/, '');
                if(cols.indexOf(col) >= 0){ own.push(k + '=' + v); } else if(other.indexOf(col) < 0){ other.push(col); }
            });
            own.sort();
            other.sort();
            // Badge granularity mirrors DashboardBoxController::filterUnaffectedBadge exactly:
            // with NO honored filter active the box shows the generic "not affected" tag, so
            // only WHETHER anything is active matters; once some filter does apply, the badge
            // NAMES the dims it had to ignore, so there the exact set matters.
            var badge = own.length ? other.join(',') : (other.length ? '1' : '');
            // Detailed Filter conditions (dfa[...]) can hit ANY column of a box's table, which
            // the box shell does not enumerate — so any change to them refreshes every box.
            var adv = [];
            params.forEach(function(v, k){
                if(k.indexOf('dfa[') === 0){ adv.push(k + '=' + v); }
            });
            adv.sort();
            return own.join('&') + '|' + badge + '|' + adv.join('&');
        }

        // Refresh only the boxes a filter change can actually change. A box marked
        // data-df-dynamic (drill/level swap, pinned swap, level visibility)
        // reads the wider chain state, so it always reloads.
        function exmentSyncBoxes(){
            $('[data-suuid]').each(function(){
                var el = this;
                var suuid = $(el).data('suuid');
                if(!hasValue(suuid)){
                    return;
                }
                var sig = exmentBoxSig(el);
                if($(el).attr('data-df-dynamic') !== '1' && $(el).data('dfSig') === sig){
                    return;
                }
                $(el).data('dfSig', sig);
                loadDashboardBox(suuid);
            });
        }

        // Filter navigation without swapping the whole page: push the new URL, re-render just
        // the filter-bar fragment (its cascade options and breadcrumb are server-side), then
        // sync the boxes selectively. Anything unexpected in the response falls back to a
        // normal navigation, so the bar can never be left half-updated.
        function exmentDfNavigate(url, push){
            $.get(url).done(function(html){
                if(push !== false){
                    history.pushState({exmentDf: 1}, '', url);
                }
                var fresh = null;
                try {
                    fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('exment-df-bar-wrap');
                } catch (e) {
                    fresh = null;
                }
                var current = $('#exment-df-bar-wrap');
                if(!fresh || !current.length){
                    window.location.href = url;
                    return;
                }
                // replaceWith re-executes the fragment's inline script, which re-binds the
                // fresh selects; select2 is re-initialised the same way pjax:complete does it.
                current.replaceWith(fresh.outerHTML);
                if(window.Exment && Exment.CommonEvent && Exment.CommonEvent.addSelect2){
                    Exment.CommonEvent.addSelect2();
                }
                exmentSyncBoxes();
            }).fail(function(){
                window.location.href = url;
            });
        }
        window.exmentDfNavigate = exmentDfNavigate;

        // Back / forward through filter states: same refresh, without pushing a new entry.
        $(window).off('popstate.exment_df').on('popstate.exment_df', function(){
            if($('#exment-df-bar-wrap').length){
                exmentDfNavigate(window.location.href, false);
            }
        });

        // The per-box request URL: base + dashboard filter (df_/dfa) + this box's page-lifetime
        // runtime state (ct / bf_*). Shared by the box render AJAX and the lazy
        // chart-filter option endpoint, so both see exactly the same scope.
        function exmentBoxUrl(base, suuid){
            var url = base + dfQuery();
            if(hasValue(exmentCtSel[suuid])){
                url += (url.indexOf('?') >= 0 ? '&' : '?') + 'ct=' + encodeURIComponent(exmentCtSel[suuid]);
            }
            if(exmentBfSel[suuid]){
                $.each(exmentBfSel[suuid], function(col, v){
                    var key = 'bf_' + encodeURIComponent(col);
                    var add = function(k, val){ url += (url.indexOf('?') >= 0 ? '&' : '?') + k + '=' + encodeURIComponent(val); };
                    if($.isArray(v)){
                        if(v.length === 1){ add(key, v[0]); }
                        else { $.each(v, function(i, x){ add(key + '[]', x); }); }
                    } else if(v && typeof v === 'object'){
                        if(v.from){ add(key + '[from]', v.from); }
                        if(v.to){ add(key + '[to]', v.to); }
                    } else {
                        add(key, v);
                    }
                });
            }
            return url;
        }

        // Lazy option lists of a box's chart-level filter popover. The rendered body carries
        // each checklist as a shell (.exment-bf-list[data-lazy]) holding only the CHECKED
        // values; the full lists are fetched ONCE per rendered body, the first time the
        // フィルター panel opens (a reload re-renders the shell, so the next open fetches the
        // lists for the new scope). One request answers every column of the box; each list is
        // rebuilt in place — checked values first, a type-to-find box over long lists, the
        // "narrow first" hint on a column over the cardinality cap.
        function exmentBfLoad(target){
            var suuid = target.data('suuid');
            var lists = target.find('.exment-bf-list[data-lazy]');
            if(!hasValue(suuid) || !lists.length || target.data('bfLoading')){
                return;
            }
            target.data('bfLoading', true);
            // generation of the rendered body this fetch belongs to (bumped by every reload of
            // the box): an answer that arrives after a reload is for a stale scope — drop it
            var gen = target.data('bfGen') || 0;
            lists.each(function(){
                if(!$(this).find('.exment-bf-loading').length){
                    $(this).append('<small class="exment-bf-loading">$loading</small>');
                }
            });
            $.ajax({
                url: exmentBoxUrl(admin_url('dashboardbox/chart_filter_options/' + suuid), suuid),
                type: 'GET',
                success: function(data){
                    if((target.data('bfGen') || 0) !== gen){
                        target.removeData('bfLoading');
                        if(target.find('.ct-pop[data-pop-id="filters"].show').length){ exmentBfLoad(target); }
                        return;
                    }
                    var cols = (data && data.columns) ? data.columns : {};
                    target.find('.exment-bf-list[data-lazy]').each(function(){
                        var list = $(this);
                        var col = String(list.data('column'));
                        var info = cols[col];
                        list.find('.exment-bf-loading').remove();
                        if(!info){ return; }
                        // keep whatever is checked in the DOM (== the page-lifetime map)
                        var checked = {};
                        list.find('.exment-bf-check:checked').each(function(){ checked[String($(this).val())] = true; });
                        var item = list.closest('.ct-fitem');
                        var disabled = info.capped && !Object.keys(checked).length;
                        item.toggleClass('dis', !!disabled);
                        if(disabled){ item.attr('title', '$narrow_first'); list.attr('data-disabled', '1'); }
                        else { item.removeAttr('title'); list.removeAttr('data-disabled'); }
                        var html = '';
                        if(disabled){ html += '<small>$narrow_first</small>'; }
                        if(info.options.length > 8){
                            html += '<input type="search" class="exment-bf-search" placeholder="$search" autocomplete="off">';
                        }
                        html += '<div class="exment-bf-opts">';
                        $.each(info.options, function(i, opt){
                            var id = String(opt.id), on = !!checked[id];
                            html += '<label' + (on ? ' class="on"' : '') + '><input type="checkbox" class="exment-bf-check" value="' + $('<i>').text(id).html().replace(/"/g, '&quot;') + '"' + (on ? ' checked' : '') + '>'
                                + '<span>' + $('<i>').text(String(opt.name)).html() + '</span></label>';
                        });
                        html += '</div>';
                        list.html(html).removeAttr('data-lazy');
                    });
                    target.removeData('bfLoading');
                    exmentPlacePop(target.find('.ct-pop[data-pop-id="filters"]').first());
                },
                error: function(){
                    target.find('.exment-bf-loading').remove();
                    target.removeData('bfLoading');
                }
            });
        }

        function loadDashboardBox(suuid, url){
            if(!hasValue(suuid)){
                return true;
            }
            if(!hasValue(url)){
                url = exmentBoxUrl(admin_url('dashboardbox/html/' + suuid), suuid);
            }
            var target = $('[data-suuid="' + suuid + '"]');
            if(target.hasClass('loading')){
                return true;
            }
            target.addClass('loading');
            // a new body is coming: any in-flight chart-filter option fetch is for the old scope
            target.data('bfGen', (target.data('bfGen') || 0) + 1);

            // set height
            var inner_body = target.find('.box-body-inner-body');
            var height = inner_body.height();
            inner_body.css('height', height);

            target.find('.box-body-inneritem').html('');
            target.find('.overlay').show();

            $.ajax({
                url: url,
                type: "GET",
                context: {
                    'inner_body': inner_body,
                    'suuid': suuid,
                },
                success: function (data) {
                    var suuid = this.suuid;

                    // get target object
                    var target = $('[data-suuid="' + suuid + '"]');

                    // Box opted out of this drill depth (chart_level_visible) — hide its whole
                    // grid COLUMN, so the flex row (see the .row-dashboard style below) lets the
                    // remaining boxes grow into the space instead of leaving a gap. A later
                    // reload (filters changed via pjax) re-renders everything fresh.
                    var boxCol = target.closest('div[class*="col-"]');
                    var hideEl = boxCol.length ? boxCol : target;
                    if (data.hide) { hideEl.hide(); } else { hideEl.show(); }

                    // if set header
                    if(data.header){
                        target.find('.box-body .box-body-inner-header').html(data.header);
                    }
                    // if set body
                    if(data.body){
                        target.find('.box-body .box-body-inner-body').html(data.body);
                    }
                    // if set footer
                    if(data.footer){
                        target.find('.box-body .box-body-inner-footer').html(data.footer);
                    }

                    // remove height
                    this.inner_body.css('height', '');

                    target.find('.overlay').hide();

                    // a toolbar panel triggered this reload — re-open it on the fresh body
                    // so the user can keep adjusting without an extra click
                    if(exmentPopReopen[suuid]){
                        var pop = exmentPopReopen[suuid];
                        delete exmentPopReopen[suuid];
                        var btn = target.find('.ct-btn[data-pop="' + pop + '"]').first();
                        if(btn.length){
                            btn.addClass('open');
                            var reopened = btn.closest('.ct-item').find('.ct-pop').first().addClass('show');
                            exmentPlacePop(reopened);
                            // fresh body = fresh shell: fetch the option lists for the new scope
                            if(pop === 'filters'){ exmentBfLoad(target); }
                        }
                    }

                    // fire plugin event
                    target.trigger('exment:dashboard_loaded');

                    target.removeClass('loading');

                    Exment.CommonEvent.tableHoverLink();

                    // Chart-level filter values the new dashboard scope no longer offers (a class
                    // ticked before the slicer moved to another grade) were flagged stale by the
                    // server: they can only empty the chart. Rebuild this box's page-lifetime
                    // map from what the fresh popover actually shows (the stale values are not
                    // rendered, so they drop out; ranges are kept) and reload once — a self-
                    // healing "relevant values" rule, mirroring the bar's cascade. The re-collect
                    // itself makes the second render stale-free, so this cannot loop.
                    if(target.find('.exment-bf-list[data-stale]').length && typeof exmentBfCollect === 'function'){
                        exmentBfSel[suuid] = exmentBfCollect(target);
                        if(target.find('.ct-pop[data-pop-id="filters"].show').length){
                            exmentPopReopen[suuid] = 'filters';
                        }
                        loadDashboardBox(suuid);
                    }
                },
                error: function () {
                    var suuid = this.suuid;
                    // get target object
                    var target = $('[data-suuid="' + suuid + '"]');

                    target.find('.overlay').hide();
                    target.removeClass('loading');
                    // Forget the filter signature of a FAILED render, so the next filter change
                    // fetches this box again instead of treating the error message as a valid
                    // render of that state (see exmentSyncBoxes).
                    target.removeData('dfSig');

                    // show error
                    target.find('.box-body .box-body-inner-body').html('$error');
                },
            });
        }
EOT;
        Admin::script($script);

        // AI summary strip support (CSS + fetch/render JS for the 🧠 strip under each chart).
        if (isset($this->dashboard)) {
            $content->row(view('exment::dashboard.insight')->render());
        }

        return $content;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    // @phpstan-ignore-next-line
    protected function form($id = null)
    {
        $form = new Form(new Dashboard());

        if (isset($id)) {
            $model = Dashboard::getEloquent($id);
            $dashboard_type = $model->dashboard_type;
        } else {
            $dashboard_type = null;
        }

        if (!isset($id)) {
            $form->text('dashboard_name', exmtrans("dashboard.dashboard_name"))
                ->required()
                ->default(short_uuid())
                ->rules("max:30|unique:".Dashboard::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
                ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
        } else {
            $form->display('dashboard_name', exmtrans("dashboard.dashboard_name"));
        }

        $form->text('dashboard_view_name', exmtrans("dashboard.dashboard_view_name"))
            ->required()
            ->rules("max:40");

        if (!System::userdashboard_available()) {
            $form->internal('dashboard_type')->default(DashboardType::SYSTEM);
        } elseif (Dashboard::hasSystemPermission() && (is_null($dashboard_type) || $dashboard_type == DashboardType::USER)) {
            $form->select('dashboard_type', exmtrans('dashboard.dashboard_type'))
                ->options(DashboardType::transKeyArray('dashboard.dashboard_type_options'))
                ->disableClear()
                ->default(DashboardType::SYSTEM);
        } else {
            $form->internal('dashboard_type')->default($dashboard_type?? DashboardType::USER);
        }

        $form->switchbool('default_flg', exmtrans("common.default"))->default(false);

        // Per-dashboard "AI summary" OPT-IN (options.ai_summary, virtual attribute):
        // default OFF — only when switched ON do this dashboard's charts show the AI
        // summary strip (and send their data to the AI provider for it).
        // Server-side enforced (AiChatService::summaryEnabledForBox).
        $form->switchbool('ai_summary', exmtrans('dashboard.ai_summary'))
            ->help(exmtrans('dashboard.ai_summary_help'))
            ->default(false);

        // create row select options.
        // Bound to the row_setting virtual attribute (NOT options directly): its mutator
        // merges into options, so keys managed outside this form survive a save.
        $form->embeds('row_setting', exmtrans("dashboard.row"), function ($form) {
            for ($row_count = 1; $row_count <= intval(config('exment.dashboard_rows', 4)); $row_count++) {
                $row = [];
                for ($i = 1; $i <= 4; $i++) {
                    $row[$i] = $i.exmtrans('dashboard.row_optionsX');
                }
                if ($row_count > 1) {
                    $row[0] = exmtrans('dashboard.row_options0');
                }

                // get default
                switch ($row_count) {
                    case 1:
                        $default = 1;
                        break;
                    case 2:
                        $default = 2;
                        break;
                    default:
                        $default = 0;
                        break;
                }

                $form->radio('row'.$row_count, sprintf(exmtrans("dashboard.row"), $row_count))
                    ->options($row)
                    ->help(sprintf(exmtrans("dashboard.description_row"), $row_count))
                    ->required()
                    ->default($default);
            }
        })->disableHeader();

        $this->setFilterBarForm($form, $model ?? null);

        $form->editing(function ($form, $arr) {
            $form->model()->append([
                'row_setting',
                'ai_summary',
                'filter_bar_table',
                'filter_bar_dims',
            ]);
        });

        $form->tools(function (Form\Tools $tools) use ($id, $dashboard_type) {
            $tools->disableList();

            // add share button
            if ($dashboard_type == DashboardType::USER) {
                $tools->append(new ShareButton(
                    $id,
                    admin_urls(ShareTargetType::DASHBOARD()->lowerkey(), $id, "shareClick")
                ));
            }

            // addhome button
            $tools->append('<a href="'.admin_url('').'" class="btn btn-sm btn-default"  style="margin-right: 5px"><i class="fa fa-home"></i>&nbsp;'. exmtrans('common.home').'</a>');
        });

        $form->saved(function ($form) {
            // get form model
            $model = $form->model();
            if (isset($model)) {
                // set setting value
                Admin::user()->setSettingValue(UserSetting::DASHBOARD, array_get($model, 'suuid'));
            }
        });

        return $form;
    }

    /**
     * Dashboard filter bar setting section (options.filter_bar).
     *
     * Lets an admin add / edit / delete the filter items of the generic global filter bar.
     * Nothing here is domain specific: the same screen configures an education hierarchy
     * (地方 → … → 児童) or a sales one (エリア → 支店 → 担当) — it only names columns of the
     * chosen source table. The bar renders from exactly what this section stores; see
     * buildDashboardFilterContext for how the stored config is read.
     *
     * The column choices come from the SAVED source table on page load, and are refreshed
     * live by Exment's linkage mechanism when the source table select changes (so a brand new
     * dashboard can be configured in one pass, without a save in between).
     *
     * @param Form $form
     * @param Dashboard|null $model
     * @return void
     */
    protected function setFilterBarForm($form, $model = null)
    {
        $form->exmheader(exmtrans('dashboard.filter_bar.header'))->hr();
        $form->descriptionHtml(exmtrans('dashboard.filter_bar.description'));

        $tables = CustomTable::filterList()->pluck('table_view_name', 'table_name')->toArray();

        $form->select('filter_bar_table', exmtrans('dashboard.filter_bar.source_table'))
            ->options($tables)
            ->help(exmtrans('dashboard.filter_bar.help.source_table'))
            ->attribute([
                // repoint every dim's column / parent select at the newly chosen table
                'data-linkage' => json_encode([
                    'column' => admin_urls('dashboard', 'filter_bar_columns'),
                ]),
            ]);

        // Columns of the currently stored source table (empty on a new dashboard — the linkage
        // above fills them as soon as a table is picked).
        $columns = $this->filterBarColumnOptions(isset($model) ? $model->getOption('filter_bar.source_table') : null);

        // A select validates its input against the options it RENDERED. Here the choices are
        // reloaded client-side whenever the source table changes, so on submit they can legally
        // be columns of a table that was picked after the page was drawn (in particular the
        // first-ever configuration, where nothing was rendered at all). Validate against the
        // table the request actually carries instead.
        $validationColumns = function () {
            return $this->filterBarColumnOptions(request()->input('filter_bar_table'));
        };

        // Slicer targeting choices: this dashboard's own chart boxes (only chart boxes apply
        // dashboard filters). Empty on a new dashboard — boxes exist only after it is saved.
        $boxOptions = [];
        if (isset($model)) {
            foreach ($model->dashboard_boxes as $box) {
                if ($box->dashboard_box_type == DashboardBoxType::CHART) {
                    $boxOptions[$box->suuid] = $box->dashboard_box_view_name
                        . ' (' . $box->row_no . '-' . $box->column_no . ')';
                }
            }
        }

        $form->hasManyJsonTable('filter_bar_dims', exmtrans('dashboard.filter_bar.dims'), function ($form) use ($columns, $validationColumns, $boxOptions) {
            $form->select('column', exmtrans('dashboard.filter_bar.dim_column'))
                ->options($columns)
                ->validationOptions($validationColumns);
            $form->text('label', exmtrans('dashboard.filter_bar.dim_label'));
            // Deliberately minimal (Power-BI-like): the parent–child relation between items
            // (child reset when the parent narrows, breadcrumb, drill levels) is INFERRED from
            // Exment metadata — see FilterBarConfig::inferredParentOf — and the control style is
            // chosen by column type (number / date → from-to range, else multi-select — see
            // FilterState::style). Dim keys the form does not expose (parent, style, from_master,
            // advanced, note, disables) are still honored by the engine when a stored / imported
            // config carries them, and Dashboard::setFilterBarDimsAttribute carries them over.
            // slicer targeting: with boxes picked, ONLY those are narrowed by this dim;
            // empty = every box whose table has the column (the legacy behavior).
            $form->multipleSelect('targets', exmtrans('dashboard.filter_bar.dim_targets'))
                ->options($boxOptions)
                ->help(exmtrans('dashboard.filter_bar.help.dim_targets'));
            // sentinel: an empty multi-select posts NOTHING — this row-level flag tells the
            // mutator "this form knew about targets", so absent = intentional clear, while a
            // save from a form WITHOUT the field keeps the stored targets untouched.
            $form->hidden('targets_submitted')->default(1);
        })->descriptionHtml('<span class="help-block"><i class="fa fa-info-circle"></i>&nbsp;'
            . exmtrans('dashboard.filter_bar.help.dims') . '</span>');

        // A row added by "+ new" is cloned from a template rendered before any table was picked,
        // so its column / parent selects would come up empty on a dashboard that has no filter
        // bar yet. Fill them from the source table currently selected — reusing the options
        // another row already holds when possible, otherwise one request to the same endpoint
        // the linkage uses.
        $columnsUrl = admin_urls('dashboard', 'filter_bar_columns');
        Admin::script(<<<EOT
$('#has-many-table-filter_bar_dims').on('admin_hasmany_row_change', function (e) {
    // the same event also fires on delete — only a row ADD needs filling, and even then only
    // the selects the user has not answered yet (deleting must never rewrite a kept row).
    if (!$(e.target).closest('.add').length) { return; }

    var \$row = $('#has-many-table-filter_bar_dims-table tbody tr:visible').last();
    var \$targets = \$row.find('select.column').filter(function () { return !this.value; });
    if (!\$targets.length) { return; }

    var fill = function (html) {
        \$targets.each(function () { $(this).html(html).val('').trigger('change.select2'); });
    };

    var \$loaded = $('#has-many-table-filter_bar_dims-table select.column')
        .not(\$targets).filter(function () { return this.options.length > 1; }).first();
    if (\$loaded.length) {
        fill(\$loaded.html());
        return;
    }

    var table = $('select[name="filter_bar_table"]').val();
    if (!table) { return; }
    $.get('{$columnsUrl}', { q: table }, function (data) {
        var html = '<option value=""></option>';
        $.each(data, function (i, d) {
            html += '<option value="' + d.id + '">' + $('<div/>').text(d.text).html() + '</option>';
        });
        fill(html);
    });
});
EOT);

        // "Is this the right source table?" — answered here, before saving, instead of leaving
        // the admin to deduce it (or to save, open the dashboard and read the per-box badges).
        $checkUrl = admin_urls('dashboard', 'filter_bar_check');
        $form->html(
            '<div id="filter-bar-check" data-url="' . $checkUrl . '" data-dashboard="'
            . esc_html(isset($model) ? strval($model->id) : '') . '">'
            . FilterBarCoverage::html($model ?? null, isset($model) ? $model->getOption('filter_bar.source_table') : null)
            . '</div>',
            exmtrans('dashboard.filter_bar.check_header')
        );
        Admin::script(<<<EOT
(function () {
    var \$panel = $('#filter-bar-check');
    if (!\$panel.length) { return; }

    // Re-run the check whenever the source table or the configured columns change. The dim
    // columns are sent along so the report narrows to the items actually being configured
    // (with none chosen yet it falls back to every column of the source table).
    var timer = null;
    var refresh = function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var dims = [];
            $('#has-many-table-filter_bar_dims-table tbody tr:visible select.column').each(function () {
                if (this.value) { dims.push(this.value); }
            });
            $.get(\$panel.data('url'), {
                q: $('select[name="filter_bar_table"]').val() || '',
                dashboard: \$panel.data('dashboard') || '',
                dims: dims
            }, function (html) { \$panel.html(html); });
        }, 250);
    };

    $(document).on('change', 'select[name="filter_bar_table"]', refresh);
    $('#has-many-table-filter_bar_dims').on('change', 'select.column', refresh)
        .on('admin_hasmany_row_change', refresh);
})();
EOT);

        // Config-only bar keys (no form field, honored by the engine when stored / seeded):
        // filter_bar.root_label (breadcrumb root, default "All"), filter_bar.max_options (option
        // cap per dim, default 500), filter_bar.scope (fixed option scope) — see FilterBarConfig.
    }

    /**
     * Selectable filter columns of a source table, as [column_name => "label (column_name)"].
     * Returns an empty list when the table is missing, so the form degrades to "pick a table
     * first" instead of erroring.
     *
     * @param string|null $table_name
     * @return array<string, string>
     */
    protected function filterBarColumnOptions($table_name)
    {
        if (is_nullorempty($table_name)) {
            return [];
        }
        $table = CustomTable::getEloquent($table_name);
        if (is_nullorempty($table)) {
            return [];
        }

        $options = [];
        foreach ($table->custom_columns as $column) {
            $options[$column->column_name] = $column->column_view_name . ' (' . $column->column_name . ')';
        }
        return $options;
    }

    /**
     * Linkage endpoint for the setting form: filter columns of the table picked in
     * `filter_bar_table` (Exment linkage sends the selected value as `q`).
     *
     * @param Request $request
     * @return array<int, array<string, string>>
     */
    // @phpstan-ignore-next-line
    public function filterBarColumns(Request $request)
    {
        $options = $this->filterBarColumnOptions($request->get('q'));

        $results = [];
        foreach ($options as $id => $text) {
            $results[] = ['id' => $id, 'text' => $text];
        }
        return $results;
    }

    /**
     * Live endpoint behind the coverage panel (source table = `q`, configured columns = `dims`).
     *
     * @param Request $request
     * @return string
     */
    // @phpstan-ignore-next-line
    public function filterBarCheck(Request $request)
    {
        // Require an explicit id: Dashboard::getEloquent(null) falls back to the user's current
        // dashboard, which would silently report on a different dashboard's boxes.
        $dashboard_id = $request->get('dashboard');
        $dashboard = is_nullorempty($dashboard_id) ? null : Dashboard::getEloquent($dashboard_id);

        $dims = array_values(array_filter((array) $request->get('dims', []), function ($d) {
            return is_string($d) && $d !== '';
        }));

        return FilterBarCoverage::html($dashboard, $request->get('q'), $dims);
    }

    /**
     * Drill-down breadcrumb: when the current dashboard has option 'parent_dashboard_suuid',
     * render "parent > current" with a link back up (e.g. grade dashboard -> school overview).
     *
     * @param Content $content
     * @return void
     */
    protected function setDrillBreadcrumb($content)
    {
        $parentSuuid = $this->dashboard->getOption('parent_dashboard_suuid');
        if (is_nullorempty($parentSuuid)) {
            return;
        }
        $parent = Dashboard::findBySuuid($parentSuuid);
        if (is_nullorempty($parent)) {
            return;
        }

        $html = '<div class="dashboard-drill-breadcrumb" style="margin:-5px 0 10px;padding:8px 14px;'
            . 'background:#fff;border:1px solid #ecebf5;border-radius:8px;font-size:13px;">'
            . '<a href="' . admin_url('?dashboard=' . $parent->suuid) . '" style="color:#5b6ef5;">'
            . '<i class="fa fa-home"></i>&nbsp;' . esc_html($parent->dashboard_view_name) . '</a>'
            . '<i class="fa fa-angle-right" style="margin:0 9px;color:#b6b6c8;"></i>'
            . '<span style="font-weight:600;color:#23233b;">' . esc_html($this->dashboard->dashboard_view_name) . '</span>'
            . '</div>';
        $content->row($html);
    }

    /**
     * Render context for this dashboard's filter bar (null = no bar configured).
     * The option-list / cascade / breadcrumb work lives in FilterBarContextBuilder
     * (Services\Dashboard); this stays a method so home() and the blade contract are
     * unchanged.
     *
     * @param Request $request
     * @return array|null
     */
    protected function buildDashboardFilterContext(Request $request)
    {
        return (new FilterBarContextBuilder())->build($this->dashboard, $request);
    }

    /**
     * Set daashboard box.
     *
     * @param Content $content
     * @param int $row_column_count
     * @param int $row_no
     * @return void
     */
    protected function setDashboardBox($content, $row_column_count, $row_no)
    {
        $content->row(function ($row) use ($row_column_count, $row_no) {
            // check role.
            $has_role = $this->dashboard->hasEditPermission();
            for ($i = 1; $i <= $row_column_count; $i++) {
                // get $boxes as $row_no
                $boxes = $this->dashboard->dashboard_row_boxes($row_no);

                // get target column by database
                $dashboard_box = $boxes->where('column_no', $i)->first();
                $id = $dashboard_box->id ?? null;

                // new dashboadbox dropdown button list
                $dashboardboxes_newbuttons = [];
                if ($has_role) {
                    foreach (DashboardBoxType::DASHBOARD_BOX_TYPE_OPTIONS() as $options) {
                        // if type is plugin, check has dashboard item
                        if (array_get($options, 'dashboard_box_type') == DashboardBoxType::PLUGIN) {
                            if (count(Plugin::getByPluginTypes(PluginType::DASHBOARD)) == 0) {
                                continue;
                            }
                        }

                        // create query
                        $query = http_build_query([
                            'dashboard_suuid' => $this->dashboard->suuid,
                            'dashboard_box_type' => array_get($options, 'dashboard_box_type'),
                            'row_no' => $row_no,
                            'column_no' => $i,
                        ]);
                        $dashboardboxes_newbuttons[] = [
                            'url' => admin_url("dashboardbox/create?{$query}"),
                            'icon' =>  $options['icon'],
                            'view_name' => exmtrans("dashboard.dashboard_box_type_options.{$options['dashboard_box_type']}"),
                        ];
                    }
                }

                // right-top icons
                $icons = [['widget' => 'reload', 'icon' => 'fa-refresh', 'tooltip' => trans('admin.refresh')]];
                // check role.
                if ($has_role) {
                    $icons = array_prepend($icons, ['link' => admin_url('dashboardbox/'.$id.'/edit'), 'icon' => 'fa-cog', 'tooltip' => trans('admin.edit')]);
                    $icons[] = ['widget' => 'delete', 'icon' => 'fa-trash', 'tooltip' => trans('admin.delete')];
                }

                // set column. use grid system
                $grids = [
                    'xs' => 12,
                    'md' => 12 / $row_column_count
                ];

                // Which part of the filter state this box's output depends on — lets the
                // dashboard JS reload only the boxes a filter click can actually change
                // (see BoxFilterScope + exmentBoxSig below).
                $attributes = '';
                if (isset($dashboard_box)) {
                    $attrs = $dashboard_box->getBoxHtmlAttr();
                    $scope = BoxFilterScope::of($dashboard_box);
                    $attrs['data-df-cols'] = implode(',', $scope['cols']);
                    $attrs['data-df-dynamic'] = $scope['dynamic'] ? '1' : '0';
                    $attributes = \Exment::formatAttributes($attrs);
                }

                $row->column($grids, view('exment::dashboard.box', [
                    'title' => $dashboard_box->dashboard_box_view_name ?? null,
                    'id' => $id,
                    'suuid' => $dashboard_box->suuid ?? null,
                    'dashboard_suuid' => $this->dashboard->suuid,
                    'dashboardboxes_newbuttons' => $dashboardboxes_newbuttons,
                    'icons' => $icons,
                    'attributes' => $attributes,
                ]));
            }
        });
    }

    // @phpstan-ignore-next-line
    protected function showVersionUpdate()
    {
        // if system admin, check version
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            return;
        }

        if (boolval(config('exment.disable_latest_version_dashboard', false))) {
            return;
        }

        $versionCheck = \Exment::checkLatestVersion();
        if ($versionCheck === SystemVersion::HAS_NEXT) {
            list($latest, $current) = \Exment::getExmentVersion();
            admin_info(exmtrans("system.version_old") . '(' . $latest . ')', '<a href="'. admin_url('system').'">'.exmtrans("system.update_guide").'</a>');
        }
    }

    /**
     * create share form
     */
    // @phpstan-ignore-next-line
    public function shareClick(Request $request, $id)
    {
        $model = Dashboard::getEloquent($id);

        $form = DataShareAuthoritable::getShareDialogForm($model);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('common.shared')
        ]);
    }

    /**
     * set share users organizations
     */
    // @phpstan-ignore-next-line
    public function sendShares(Request $request, $id)
    {
        // get custom view
        $model = Dashboard::getEloquent($id);
        return DataShareAuthoritable::saveShareDialogForm($model);
    }
}
