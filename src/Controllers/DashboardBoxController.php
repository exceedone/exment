<?php

namespace Exceedone\Exment\Controllers;

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
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Services\Dashboard\FilterState;
use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Illuminate\Support\Collection;

class DashboardBoxController extends AdminControllerBase
{
    use HasResourceActions;
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
        if (isset($box)) {
            $box->delete();
            return response()->json([
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ]);
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
        // get dashboardbox object
        $box = DashBoardBox::findBySuuid($suuid);

        // get box html --------------------------------------------------
        if (isset($box)) {
            $dashboard_box_item = $box->dashboard_box_item;
            // null for an unknown / legacy box type — render an empty box instead of erroring.
            if (isset($dashboard_box_item)) {
                $header = $this->rednerHtml($dashboard_box_item->header());
                $body = $this->rednerHtml($dashboard_box_item->body());
                $footer = $this->rednerHtml($dashboard_box_item->footer());

                // Level-visibility marker (ChartItem::isVisibleAtCurrentLevel): the box asked
                // to disappear at this drill depth — tell the loader to hide the whole card,
                // and skip the filter badge (a badge on a hidden box makes no sense).
                $hide = isset($body) && strpos($body, 'data-exment-box-hidden') !== false;
                if (!$hide && isset($body) && !is_null($badge = $this->filterUnaffectedBadge($box))) {
                    $body = $badge . $body;
                }
            }
        }

        // get dashboard box
        return [
            'header' => $header ?? null,
            'body' => $body ?? null,
            'footer' => $footer ?? null,
            'hide' => $hide ?? false,
            'suuid' => $suuid,
        ];
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
        // before saving
        $form->saving(function ($form) {
            $classname = DashboardBoxType::getEnum($form->dashboard_box_type)->getDashboardBoxItemClass();
            $classname::saving($form);
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

        // get custom views
        $custom_table = CustomTable::getEloquent($id);
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
        $custom_view = CustomView::getEloquent($id);
        if (!isset($custom_view)) {
            return [];
        }

        // series column list (for multi-series charts) = the view's group-by columns
        if ($axis_type == 'series') {
            return ChartItem::seriesSelectOptions($custom_view);
        }

        return $custom_view->getViewColumnsSelectOptions($axis_type == 'y');
    }

    /**
     * Linkage endpoint for the chart box form: columns of the table picked in
     * target_table_id (sent as `q`), offered as chart-level filter fields
     * (options.chart_filters — stored by column NAME, template-portable).
     *
     * @param Request $request
     * @return array<int, array<string, string>>
     */
    // @phpstan-ignore-next-line
    public function chartFilterColumns(Request $request)
    {
        $id = $request->get('q');
        if (!isset($id)) {
            return [];
        }
        $custom_table = CustomTable::getEloquent($id);
        if (!isset($custom_table)) {
            return [];
        }

        $results = [];
        foreach ($custom_table->custom_columns as $custom_column) {
            $results[] = [
                'id' => $custom_column->column_name,
                'text' => $custom_column->column_view_name . ' (' . $custom_column->column_name . ')',
            ];
        }
        return $results;
    }

    /**
     * Lazy option lists of a chart box's chart-level filter checklists (see
     * ChartItem::chartFilterOptions): the dashboard JS calls this the first time the
     * フィルター popover opens after a render, with the SAME query params as the box AJAX
     * (df_* / bf_* / dfa), so the lists are scoped exactly like the box. Optional `col`
     * narrows the answer to one column.
     *
     * @param Request $request
     * @param string $suuid
     * @return array{columns: array}
     */
    // @phpstan-ignore-next-line
    public function chartFilterOptions(Request $request, $suuid)
    {
        $box = DashBoardBox::findBySuuid($suuid);
        $item = isset($box) ? $box->dashboard_box_item : null;
        if (!isset($item) || !method_exists($item, 'chartFilterOptions')) {
            return ['columns' => []];
        }
        $only = $request->get('col');
        $only = FilterState::isIdentifier($only) ? $only : null;
        return ['columns' => $item->chartFilterOptions($only)];
    }

    // @phpstan-ignore-next-line
    protected function rednerHtml($item)
    {
        return $item instanceof \Illuminate\Contracts\Support\Renderable ? $item->render() : $item;
    }

    /**
     * When the dashboard's filter bar has active selections but this box's query cannot
     * honor them, return a muted "not affected by filters" tag to prepend to the body, so
     * unfiltered numbers are not read as filtered ones. Only chart boxes apply df_* params
     * (ChartItem::applyDashboardFilter); every other data box — and a chart whose table
     * lacks all of the selected columns — keeps its own scope.
     *
     * @param DashboardBox $box
     * @return string|null null = no badge (filter inactive, box reacts, or box has no data table)
     */
    protected function filterUnaffectedBadge($box)
    {
        // Same param source and guards as ChartItem::applyDashboardFilter (both live in
        // FilterState now), so this badge and the actual filtering can never disagree
        // about which selections are "active".
        $active_columns = FilterState::activeColumns();
        if (empty($active_columns)) {
            return null;
        }

        $dashboard = $box->dashboard;
        if (!isset($dashboard) || is_nullorempty(array_get($dashboard->options ?? [], 'filter_bar'))) {
            return null;
        }

        // Boxes without a target table (system news etc.) make no data claim — skip.
        $custom_table = CustomTable::getEloquent(array_get($box->options ?? [], 'target_table_id'));
        if (!isset($custom_table)) {
            return null;
        }

        if ($box->dashboard_box_type == DashboardBoxType::CHART) {
            $matched = [];
            $unmatched = [];
            foreach ($active_columns as $column_name) {
                // a dim filters this box only when the column exists on its table AND the
                // dim's slicer targeting (if configured) includes this box — the exact gate
                // FilterState::applyTo uses, so badge and filtering can never disagree
                if ($custom_table->custom_columns->contains(fn ($c) => $c->column_name === $column_name)
                    && FilterState::targetsAllow($box, $column_name)) {
                    $matched[] = $column_name;
                } else {
                    $unmatched[] = $column_name;
                }
            }
            if (count($unmatched) === 0) {
                return null; // every active selection applies — nothing to disclose
            }
            if (count($matched) > 0) {
                // Partially filtered: the box narrows by some selections but cannot honor the
                // rest (its table lacks those columns — e.g. a student-master pie when a
                // student is selected on the fact table). Name the ignored dims so the number
                // is not read as filtered by them.
                $dim_labels = [];
                $dims = array_get($dashboard->options ?? [], 'filter_bar.dims');
                foreach ($unmatched as $column_name) {
                    $label = $column_name;
                    foreach (is_array($dims) ? $dims : [] as $dim) {
                        if (array_get($dim, 'column') === $column_name) {
                            $label = array_get($dim, 'label', $column_name);
                            break;
                        }
                    }
                    $dim_labels[] = $label;
                }
                return $this->filterBadgeHtml(exmtrans('dashboard.filter_bar.partially_affected') . ': ' . implode(', ', $dim_labels));
            }
        }

        return $this->filterBadgeHtml(exmtrans('dashboard.filter_bar.not_affected'));
    }

    /**
     * @param string $text
     * @return string
     */
    protected function filterBadgeHtml($text)
    {
        return '<div style="text-align:right; margin:0 8px 2px;"><span style="display:inline-block; padding:1px 10px; font-size:11px; color:#888; background:#f4f4f4; border:1px solid #ddd; border-radius:10px;">'
            . esc_html($text) . '</span></div>';
    }
}
