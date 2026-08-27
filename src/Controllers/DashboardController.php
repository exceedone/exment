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
use Exceedone\Exment\Model\DataShareAuthoritable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Form\Tools\DashboardMenu;
use Exceedone\Exment\Form\Tools\ShareButton;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Services\Dashboard\FilterBarConfig;
use Exceedone\Exment\Services\Dashboard\FilterBarForm;
use Exceedone\Exment\Services\Dashboard\FilterBarView;
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

        // dashboard filter bar (options.filter_bar); nothing when not configured
        $bar = FilterBarView::build($this->dashboard, DashboardFilter::fromRequest($this->dashboard));

        // bar-only render for a selective (pushState) filter change: the boxes that keep
        // their content still need the bar's fresh option lists (dashboard.js refreshFilterBar)
        if ($request->boolean('_df_bar')) {
            return response($bar === null ? '' : view('exment::dashboard.filter_bar', $bar)->render());
        }

        if ($bar !== null) {
            $content->row(view('exment::dashboard.filter_bar', $bar)->render());
        }

        //set row
        for ($i = 1; $i <= intval(config('exment.dashboard_rows', 4)); $i++) {
            $row_name = 'row'.$i;
            $row_column = intval($this->dashboard->getOption($row_name));
            if ($row_column > 0) {
                $this->setDashboardBox($content, $row_column, $i);
            }
        }

        // dashboard runtime (public/vendor/exment/js/dashboard.js): box loading, filter bar,
        // chart toolbar and AI summary strip — only the texts are passed from here
        $lang = [
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm' => trans('admin.confirm'),
            'cancel' => trans('admin.cancel'),
            'error' => exmtrans('error.header'),
            'ai_generating' => exmtrans('dashboard.ai.generating'),
            'ai_regenerate' => exmtrans('dashboard.ai.regenerate'),
            'ai_error' => exmtrans('dashboard.ai.error_generic'),
            'ai_highest' => exmtrans('dashboard.ai.stat_highest'),
            'ai_lowest' => exmtrans('dashboard.ai.stat_lowest'),
            'ai_average' => exmtrans('dashboard.ai.stat_average'),
            'ai_range' => exmtrans('dashboard.ai.stat_range'),
            'ai_anomalies' => exmtrans('dashboard.ai.anomaly_title'),
            'ai_expected_range' => exmtrans('dashboard.ai.expected_range'),
            'ai_stable' => exmtrans('dashboard.ai.stable'),
            'filter_not_affected' => exmtrans('dashboard.filter_bar.not_affected'),
            'filter_partially_affected' => exmtrans('dashboard.filter_bar.partially_affected'),
        ];
        Admin::script('ExmentDashboard.init(' . json_encode(['lang' => $lang], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');');
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

        // AI summary strip under the charts: opt-in per dashboard (default OFF), enforced
        // server-side too (AiSummaryService::enabledForBox)
        $form->switchbool('ai_summary', exmtrans('dashboard.ai.switch'))
            ->help(exmtrans('dashboard.ai.switch_help'))
            ->default(false);

        // create row select options
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

        FilterBarForm::build($form, $model ?? null);

        // the form binds virtual attributes (each merges into options on save, so keys no
        // field manages survive); expose them on the model the form reads from
        $form->editing(function ($form) {
            $form->model()->append(['row_setting', 'ai_summary', 'filter_bar_table', 'filter_bar_dims']);
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
     * Linkage endpoint of the filter bar setting section: filter columns of the table
     * picked in `filter_bar_table` (sent as `q`).
     *
     * @param Request $request
     * @return array<int, array{id:string, text:string}>
     */
    public function filterBarColumns(Request $request)
    {
        $results = [];
        foreach (FilterBarForm::columnOptions($request->get('q')) as $id => $text) {
            $results[] = ['id' => $id, 'text' => $text];
        }
        return $results;
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
            $filter_bar = FilterBarConfig::fromDashboard($this->dashboard);
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

                $attributes = isset($dashboard_box) ? $dashboard_box->getBoxHtmlAttr() : [];
                // data-df-dims: the filter bar items that narrow this box — dashboard.js only
                // reloads the boxes whose items changed on a bar change (empty = never narrowed)
                if (isset($dashboard_box) && $filter_bar !== null) {
                    $dims = [];
                    if ($dashboard_box->dashboard_box_type == DashboardBoxType::CHART) {
                        $table = CustomTable::getEloquent(array_get($dashboard_box->options ?? [], 'target_table_id'));
                        $dims = $filter_bar->dimsFor($table, $dashboard_box);
                    }
                    $attributes['data-df-dims'] = implode(',', $dims);
                }

                $row->column($grids, view('exment::dashboard.box', [
                    'title' => $dashboard_box->dashboard_box_view_name ?? null,
                    'id' => $id,
                    'suuid' => $dashboard_box->suuid ?? null,
                    'dashboard_suuid' => $this->dashboard->suuid,
                    'dashboardboxes_newbuttons' => $dashboardboxes_newbuttons,
                    'icons' => $icons,
                    'attributes' => \Exment::formatAttributes($attributes),
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
