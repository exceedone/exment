<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Auth\Permission as Checker;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Services\Dashboard\ChartColors;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Illuminate\Support\Collection;

class DashboardBoxController extends AdminControllerBase
{
    use HasResourceActions {
        destroy as resourceDestroy;
    }
    // @phpstan-ignore-next-line
    protected $dashboard;
    // @phpstan-ignore-next-line
    protected $dashboard_box_type;
    // @phpstan-ignore-next-line
    protected $row_no;
    // @phpstan-ignore-next-line
    protected $column_no;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("dashboard.header"), exmtrans("dashboard.header"));
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
     * Saves an edited box: only of a dashboard this user may edit, and into that same
     * dashboard (the request was open to anyone who may view the dashboard).
     *
     * @param int $id
     * @return mixed
     */
    public function update($id)
    {
        $box = DashboardBox::getEloquent($id);
        $this->denyUnlessEditable($box ? $box->dashboard : null);
        // the form posts the box's own dashboard (a hidden field): a box never moves into
        // another one, which this user may not even edit
        $posted = request()->input('dashboard_id');
        if (request()->has('dashboard_id') && (!is_scalar($posted) || (string) $posted !== (string) $box->dashboard_id)) {
            abort(403, exmtrans('common.message.notfound_or_deny'));
        }
        return $this->form($id)->update($id);
    }

    /**
     * Saves a new box: only into a dashboard this user may edit.
     *
     * @return mixed
     */
    public function store()
    {
        $this->denyUnlessEditable(Dashboard::getEloquent(request()->input('dashboard_id')));
        return $this->form()->store();
    }

    /**
     * 403 unless $dashboard is one this user may edit (null: none, or one this user may not
     * even view — Dashboard's showable scope).
     *
     * @param mixed $dashboard
     */
    protected function denyUnlessEditable($dashboard): void
    {
        if (!$dashboard instanceof Dashboard || !$dashboard->hasEditPermission()) {
            abort(403, exmtrans('common.message.notfound_or_deny'));
        }
    }

    /**
     * Deletes boxes (DELETE admin/dashboardbox/{id}, ids comma separated): like delete(),
     * only boxes of a dashboard this user may edit.
     *
     * @param mixed $id
     * @return mixed
     */
    public function destroy($id)
    {
        $boxes = [];
        foreach (collect(explode(',', (string) $id))->filter() as $key) {
            $box = DashboardBox::getEloquent($key);
            if (!$box instanceof DashboardBox || !isset($box->dashboard) || !$box->dashboard->hasEditPermission()) {
                return response()->json([
                    'status'  => false,
                    'message' => trans('admin.deny'),
                ], 403);
            }
            $boxes[] = $box;
        }
        return $this->deleteBoxes(function () use ($id) {
            return $this->resourceDestroy($id);
        }, $boxes);
    }

    /**
     * Runs $delete, then forgets the boxes of $boxes it deleted among their dashboards'
     * filter targets (Dashboard::forgetFilterBarTargets) — one save per dashboard — in one
     * transaction: a box never goes while a filter item still targets it.
     *
     * @param callable $delete  answers the response
     * @param DashboardBox[] $boxes
     * @return mixed
     */
    private function deleteBoxes(callable $delete, array $boxes)
    {
        try {
            return \ExmentDB::transaction(function () use ($delete, $boxes) {
                $response = $delete();
                $dashboards = [];
                $deleted = [];
                foreach ($boxes as $box) {
                    if (!DashboardBox::where('id', $box->id)->exists()) {
                        $dashboards[$box->dashboard_id] = $dashboards[$box->dashboard_id] ?? $box->dashboard;
                        $deleted[$box->dashboard_id][] = (string) $box->suuid;
                    }
                }
                foreach ($deleted as $dashboardId => $suuids) {
                    $dashboards[$dashboardId]->forgetFilterBarTargets($suuids);
                }
                return $response;
            });
        } catch (\Exception $ex) {
            \Log::error($ex);
            return response()->json([
                'status'  => false,
                'message' => trans('admin.delete_failed'),
            ]);
        }
    }

    /**
     * Delete interface.
     *
     * @param Request $request
     * @param $suuid
     * @return \Illuminate\Http\JsonResponse
     */
    // @phpstan-ignore-next-line
    public function delete(Request $request, $suuid)
    {
        // get suuid
        $box = DashBoardBox::findBySuuid($suuid);
        // whoever may edit the dashboard removes its boxes: the icon is shown to them alone,
        // the request is open to anyone who knows the suuid. A dashboard this user cannot
        // view resolves to null (Dashboard's showable scope).
        if ($box instanceof DashboardBox && (!isset($box->dashboard) || !$box->dashboard->hasEditPermission())) {
            return response()->json([
                'status'  => false,
                'message' => trans('admin.deny'),
            ], 403);
        }
        if ($box instanceof DashboardBox) {
            // a filter item targeting this box would otherwise narrow no box at all
            return $this->deleteBoxes(function () use ($box) {
                $box->delete();
                return response()->json([
                    'status'  => true,
                    'message' => trans('admin.delete_succeeded'),
                ]);
            }, [$box]);
        } else {
            return response()->json([
                'status'  => false,
                'message' => trans('admin.delete_failed'),
            ]);
        }
    }

    /**
     * get box html from ajax
     */
    // @phpstan-ignore-next-line
    public function getHtml($suuid)
    {
        // get dashboardbox object; a box of a dashboard this user cannot view (null:
        // Dashboard's showable scope) renders as a missing one
        $box = DashBoardBox::findBySuuid($suuid);
        if ($box instanceof DashboardBox && !isset($box->dashboard)) {
            $box = null;
        }

        // get box html --------------------------------------------------
        if ($box instanceof DashboardBox) {
            $dashboard_box_item = $box->dashboard_box_item;
            // null for an unknown box type (a removed plugin): render an empty box instead of erroring
            if (isset($dashboard_box_item)) {
                $header = $this->rednerHtml($dashboard_box_item->header());
                $body = $this->rednerHtml($dashboard_box_item->body());
                $footer = $this->rednerHtml($dashboard_box_item->footer());
                if (isset($body)) {
                    $body = $this->filterBadge($box) . $body;
                }
            }
        }

        // get dashboard box
        return [
            'header' => $header ?? null,
            'body' => $body ?? null,
            'footer' => $footer ?? null,
            'suuid' => $suuid,
        ];
    }

    /**
     * Remembers the toolbar choices of a chart box for the current user — chart type, sort,
     * display options — as user setting `dashboard_chart.{dashboard suuid}.{box suuid}`;
     * the next dashboard entry starts the box with them (DashboardController::home). A
     * choice equal to the box setting is not kept, so the box follows its setting again.
     *
     * @param Request $request
     * @param string $suuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function chartState(Request $request, $suuid)
    {
        $box = DashboardBox::findBySuuid($suuid);
        $user = Admin::user();
        if (!$box instanceof DashboardBox || $box->dashboard_box_type != DashboardBoxType::CHART || !isset($box->dashboard) || $user === null) {
            return response()->json(['status' => false], 404);
        }
        $state = ChartItem::toolbarState($request->only(['ct', 'cs', 'cd']), array_get($box, 'options.chart_type'));
        $key = 'dashboard_chart.' . $box->dashboard->suuid . '.' . $box->suuid;
        if ($user->getSettingValue($key) !== $state) {
            $user->setSettingValue($key, $state);
        }
        return response()->json(['status' => true]);
    }

    /**
     * Paints one color on a chart box (right-click a point / series on the chart, like
     * Excel's Fill) or takes every painted color back (`reset`), for everyone who views the
     * box: box option `chart_colors` (ChartColors). Dashboard editors only.
     *
     * Posted: kind (points | series), key (the category text / series name; '' = the one
     * series of a one-color chart), color (#rrggbb; empty = back to the palette), or reset=1.
     *
     * @param Request $request
     * @param string $suuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function chartColor(Request $request, $suuid)
    {
        $box = DashboardBox::findBySuuid($suuid);
        if (!$box instanceof DashboardBox || $box->dashboard_box_type != DashboardBoxType::CHART || !isset($box->dashboard)) {
            return response()->json(['status' => false], 404);
        }
        if (!$box->dashboard->hasEditPermission()) {
            return response()->json(['status' => false], 403);
        }
        $kind = $request->input('kind');
        $key = $request->input('key') ?? ''; // posted empty arrives as null
        if (!$request->boolean('reset') && (!in_array($kind, [ChartColors::POINT, ChartColors::SERIES], true) || !is_scalar($key) || mb_strlen((string) $key) > 255)) {
            return response()->json(['status' => false], 422);
        }
        $options = $box->options ?? [];
        $painted = ChartColors::fromOption(array_get($options, 'chart_colors'));
        if (!$request->boolean('reset') && !$painted->accepts($kind, (string) $key, $request->input('color'))) {
            return response()->json(['status' => false], 422);
        }
        $colors = $request->boolean('reset') ? [] : $painted->with($kind, (string) $key, $request->input('color'));
        if (empty($colors)) {
            unset($options['chart_colors']);
        } else {
            $options['chart_colors'] = $colors;
        }
        $box->options = $options;
        $box->save();
        return response()->json(['status' => true]);
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @return Form|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    // @phpstan-ignore-next-line
    protected function form($id = null)
    {
        $form = new Form(new DashboardBox());
        // set info with query --------------------------------------------------
        // get request
        $request = request();
        // get dashboard, row_no, column_no, ... from query "dashboard_suuid"
        list($dashboard, $dashboard_box_type, $row_no, $column_no) = $this->getDashboardInfo($id);
        if (!isset($dashboard)) {
            return redirect(admin_url(''));
        }

        $form->display('dashboard_view_name', exmtrans('dashboard.dashboard_view_name'))->default($dashboard->dashboard_view_name);
        $form->hidden('dashboard_id')->default($dashboard->id);

        $form->display('row_no', exmtrans('dashboard.row_no'))->default($row_no);
        $form->hidden('row_no')->default($row_no);

        $form->display('column_no', exmtrans('dashboard.column_no'))->default($column_no);
        $form->hidden('column_no')->default($column_no);

        $form->display('dashboard_box_type_display', exmtrans('dashboard.dashboard_box_type'))->default(exmtrans("dashboard.dashboard_box_type_options.$dashboard_box_type"));
        $form->hidden('dashboard_box_type')->default($dashboard_box_type);

        $form->text('dashboard_box_view_name', exmtrans("dashboard.dashboard_box_view_name"))->rules("max:40")->required();

        // Option Setting --------------------------------------------------
        $form->embeds('options', function ($form) use ($dashboard, $dashboard_box_type) {
            $classname = DashboardBoxType::getEnum($dashboard_box_type)->getDashboardBoxItemClass();
            $classname::setAdminOptions($form, $dashboard);
        })->disableHeader();

        $form->tools(function (Form\Tools $tools) {
            $tools->disableList();

            // addhome button
            $tools->append('<a href="'.admin_url('').'" class="btn btn-sm btn-default"  style="margin-right: 5px"><i class="fa fa-home"></i>&nbsp;'. exmtrans('common.home').'</a>');
        });
        // add form saving and saved event
        $this->manageFormSaving($form);
        return $form;
    }

    // @phpstan-ignore-next-line
    protected function manageFormSaving($form)
    {
        // colors painted on the chart itself (chartColor) are no field of this form, whose
        // embedded options keep only the fields it declares: carry them over the save
        $painted = null;

        // before saving
        $form->saving(function ($form) use (&$painted) {
            $painted = array_get($form->model()->options ?? [], 'chart_colors');
            $classname = DashboardBoxType::getEnum($form->dashboard_box_type)->getDashboardBoxItemClass();
            $classname::saving($form);
        });

        // in the save's transaction, so the box never stays saved without its colors
        $form->savedInTransaction(function ($form) use (&$painted) {
            $model = $form->model();
            if (!empty($painted) && isset($model) && !array_has($model->options ?? [], 'chart_colors')) {
                $options = $model->options ?? [];
                $options['chart_colors'] = $painted;
                $model->options = $options;
                $model->save();
            }
        });

        // saved. redirect to top
        $form->saved(function ($form) {
            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url());
        });
    }

    /**
     * get dashboard info using id, or query
     */
    // @phpstan-ignore-next-line
    protected function getDashboardInfo($id)
    {
        // set info with query --------------------------------------------------
        // get request
        $request = request();
        // get dashboard_id from query "dashboard_suuid"
        if (isset($id)) {
            $dashboard_box = DashboardBox::getEloquent($id);
            if (!isset($dashboard_box)) {
                Checker::notFoundOrDeny();
                return false;
            }

            $dashboard = $dashboard_box->dashboard;
            return [$dashboard, $dashboard_box->dashboard_box_type, $dashboard_box->row_no, $dashboard_box->column_no];
        }

        if (!is_null($request->input('dashboard_id'))) {
            $dashboard = Dashboard::getEloquent($request->input('dashboard_id'));
        } else {
            // get dashboard_suuid from query
            $dashboard_suuid = $request->query('dashboard_suuid');
            if (is_nullorempty($dashboard_suuid)) {
                return [null, null, null, null];
            }
            $dashboard = Dashboard::findBySuuid($dashboard_suuid) ?? null;
        }
        if (!isset($dashboard)) {
            return [null, null, null, null];
        }

        if (!is_null($request->input('dashboard_box_type'))) {
            $dashboard_box_type = $request->input('dashboard_box_type');
        } else {
            // get dashboard_box_type from query
            $dashboard_box_type = $request->query('dashboard_box_type');
        }

        // row_no
        if (!is_null($request->input('row_no'))) {
            $row_no = $request->input('row_no');
        } else {
            // get from query
            $row_no = $request->query('row_no');
        }

        // column_no
        if (!is_null($request->input('column_no'))) {
            $column_no = $request->input('column_no');
        } else {
            // get from query
            $column_no = $request->query('column_no');
        }
        return [$dashboard, $dashboard_box_type, $row_no, $column_no];
    }

    /**
     * get views using table id
     *
     * @param Request $request
     * @param $dashboard_type
     * @return array|Collection
     */
    // @phpstan-ignore-next-line
    public function tableViews(Request $request, $dashboard_type)
    {
        $id = $request->get('q');
        if (!isset($id)) {
            return [];
        }
        $dashboard_suuid = $request->get('dashboard_suuid');
        $dashboard = Dashboard::findBySuuid($dashboard_suuid);
        if (!isset($dashboard)) {
            return [];
        }

        // get custom views: of a table this user may view (the form lists no other), the
        // request itself is open to anyone
        $custom_table = is_scalar($id) ? CustomTable::getEloquent($id) : null;
        if (!isset($custom_table) || !$custom_table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return [];
        }
        $views = $custom_table->custom_views
            ->where('view_kind_type', '<>', ViewKindType::FILTER)
            ->filter(function ($value) use ($dashboard_type) {
                if ($dashboard_type == DashboardBoxType::CALENDAR) {
                    return array_get($value, 'view_kind_type') == ViewKindType::CALENDAR;
                } else {
                    return array_get($value, 'view_kind_type') != ViewKindType::CALENDAR;
                }
            })
            ->filter(function ($value) use ($dashboard) {
                if ($dashboard->dashboard_type != DashboardType::SYSTEM) {
                    return true;
                }
                return array_get($value, 'view_type') == ViewType::SYSTEM;
            })
            ->map(function ($value) {
                return array('id' => $value->id, 'text' => $value->view_view_name);
            });
        // if count > 0, return value.
        // @phpstan-ignore-next-line
        if (!is_null($views) && count($views) > 0) {
            return $views;
        }

        // create default view
        $view = CustomView::createDefaultView($custom_table);
        $view->createDefaultViewColumns();
        return [['id' => $view->id, 'text' => $view->view_view_name]];
    }

    /**
     * get view columns using view id
     *
     * @param Request $request
     * @param $axis_type
     * @return array
     */
    // @phpstan-ignore-next-line
    public function chartAxis(Request $request, $axis_type)
    {
        $id = $request->get('q');
        if (!isset($id)) {
            return [];
        }
        // get custom views
        $custom_view = is_scalar($id) ? CustomView::getEloquent($id) : null;
        if (!isset($custom_view) || !$custom_view->custom_table?->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return [];
        }
        // series column of a multi-series chart = the view's group columns
        if ($axis_type == 'series') {
            return ChartItem::seriesSelectOptions($custom_view);
        }

        return $custom_view->getViewColumnsSelectOptions($axis_type == 'y');
    }

    /**
     * Linkage endpoint of the chart box form: columns of the table picked in
     * target_table_id (sent as `q`), offered as chart filter fields. A table this user may
     * not read answers with an empty list.
     *
     * @param Request $request
     * @return array<int, array{id:string, text:string}>
     */
    public function chartFilterColumns(Request $request)
    {
        $q = $request->get('q');
        // a table id or name: getEloquent() would answer an array (?q[id][]=1) with itself
        $custom_table = is_scalar($q) ? CustomTable::getEloquent($q) : null;
        if ($custom_table !== null && !$custom_table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            $custom_table = null;
        }
        $results = [];
        foreach ($custom_table ? $custom_table->custom_columns : [] as $custom_column) {
            $results[] = [
                'id' => $custom_column->column_name,
                'text' => $custom_column->column_view_name . ' (' . $custom_column->column_name . ')',
            ];
        }
        return $results;
    }

    /**
     * A muted "not affected by filters" tag prepended to a box body when the dashboard
     * filter bar has a selection that this box does not (fully) honour — so unfiltered
     * numbers are never read as filtered ones. Only chart boxes apply the filter bar.
     *
     * @param DashboardBox $box
     * @return string '' when nothing to disclose
     */
    protected function filterBadge($box)
    {
        $filter = DashboardFilter::fromRequest($box->dashboard);
        $custom_table = CustomTable::getEloquent(array_get($box->options ?? [], 'target_table_id'));
        if ($filter->isEmpty() || !isset($custom_table)) {
            return '';
        }
        $ignored = $box->dashboard_box_type == DashboardBoxType::CHART
            ? $filter->ignoredFor($custom_table, $box)
            : array_keys($filter->values());
        if (empty($ignored)) {
            return '';
        }
        $text = count($ignored) === count($filter->values())
            ? exmtrans('dashboard.filter_bar.not_affected')
            : exmtrans('dashboard.filter_bar.partially_affected') . ': ' . implode(', ', $filter->labels($ignored));
        return '<div class="exment-filter-badge"><span>' . esc_html($text) . '</span></div>';
    }

    // @phpstan-ignore-next-line
    protected function rednerHtml($item)
    {
        return $item instanceof \Illuminate\Contracts\Support\Renderable ? $item->render() : $item;
    }
}
