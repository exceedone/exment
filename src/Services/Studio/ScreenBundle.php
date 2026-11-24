<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\CalendarType;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

/**
 * Studio: an app made in one go.
 *
 * Like an app of kintone, a new table is more than the table and its input
 * screen: the builder also makes, when asked, the views and the dashboard the
 * data is looked at with:
 *
 * - a kanban, its lanes the choices of a choice item ("status");
 * - a calendar of a date item;
 * - a chart: the number of data by a choice item (a summary view and a chart);
 * - a dashboard of the app: the list, and the chart and the calendar made.
 *
 * Posted with the screen as
 *
 *     "bundle": {"kanban": <index of a choice item>|null, "calendar": <index of a date item>|null,
 *                "chart": <index of a choice item>|null, "dashboard": true|false}
 *
 * Each is made as the setting pages make it, so it is changed there later.
 */
class ScreenBundle
{
    /**
     * The item types each part is made from.
     */
    public const SOURCES = [
        'kanban' => [ColumnType::SELECT],
        'calendar' => [ColumnType::DATE, ColumnType::DATETIME],
        'chart' => [ColumnType::SELECT, ColumnType::YESNO],
    ];

    /**
     * @param mixed $bundle the posted parts (JSON string or array)
     * @return array{kanban: ?int, calendar: ?int, chart: ?int, dashboard: bool}
     */
    public static function normalize($bundle): array
    {
        if (is_string($bundle)) {
            $bundle = json_decode($bundle, true);
        }
        $bundle = is_array($bundle) ? $bundle : [];
        $indexOf = function (string $part) use ($bundle): ?int {
            $index = array_get($bundle, $part);
            return is_numeric($index) ? intval($index) : null;
        };
        return [
            'kanban' => $indexOf('kanban'),
            'calendar' => $indexOf('calendar'),
            'chart' => $indexOf('chart'),
            'dashboard' => boolval(array_get($bundle, 'dashboard', false)),
        ];
    }

    /**
     * Whether anything is asked for.
     *
     * @param array{kanban: ?int, calendar: ?int, chart: ?int, dashboard: bool} $bundle
     */
    public static function any(array $bundle): bool
    {
        return isset($bundle['kanban']) || isset($bundle['calendar']) || isset($bundle['chart']) || $bundle['dashboard'];
    }

    /**
     * The mistakes, by "bundle.kanban" and so on.
     *
     * @param array{kanban: ?int, calendar: ?int, chart: ?int, dashboard: bool} $bundle
     * @param array<int, array<string, mixed>> $fields the normalized items of the screen
     * @return array<string, string>
     */
    public static function validate(array $bundle, array $fields): array
    {
        $errors = [];
        foreach (static::SOURCES as $part => $types) {
            $index = $bundle[$part];
            if ($index === null) {
                continue;
            }
            $type = strval(array_get($fields, $index . '.type', ''));
            if (!in_array($type, $types, true)) {
                $errors["bundle.{$part}"] = exmtrans('studio.error.bundle_' . $part);
            }
        }
        if ($bundle['dashboard'] && static::dashboardType() === null) {
            $errors['bundle.dashboard'] = exmtrans('studio.error.bundle_dashboard');
        }
        return $errors;
    }

    /**
     * The kind of dashboard the login user can make: shared with everyone for
     * a user with the system permission, the user's own otherwise; null when
     * neither may be made.
     */
    public static function dashboardType(): ?int
    {
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        if (!isset($user)) {
            return null;
        }
        if ($user->hasPermission(\Exceedone\Exment\Enums\Permission::SYSTEM)) {
            return DashboardType::SYSTEM;
        }
        return boolval(System::userdashboard_available()) ? DashboardType::USER : null;
    }

    /**
     * Make the parts asked for.
     *
     * @param array<int, int> $columnIds the custom column of each posted item
     * @param array{kanban: ?int, calendar: ?int, chart: ?int, dashboard: bool} $bundle parts validate() found no mistake in
     * @return array<int, string> the names of what was made, for the message
     */
    public static function build(CustomTable $table, array $columnIds, array $bundle): array
    {
        $made = [];
        $name = strval($table->table_view_name);
        $columnOf = function (?int $index) use ($columnIds): ?CustomColumn {
            $column = $index === null ? null : CustomColumn::getEloquent($columnIds[$index] ?? null);
            return $column instanceof CustomColumn ? $column : null;
        };

        $kanban = $columnOf($bundle['kanban']);
        if (isset($kanban)) {
            $view = static::view($table, ViewKindType::KANBAN, sprintf(exmtrans('studio.bundle_kanban_name'), $name), ['kanban_group_column_id' => intval($kanban->id)]);
            static::addColumns($view, $table, $columnIds);
            $made[] = strval($view->view_view_name);
        }

        $calendar = $columnOf($bundle['calendar']);
        $calendarView = null;
        if (isset($calendar)) {
            // the calendar finds the data by the index of the date (the column setting "index")
            if (!boolval($calendar->getOption('index_enabled'))) {
                $calendar->setOption('index_enabled', '1');
                $calendar->save();
            }
            $calendarView = static::view($table, ViewKindType::CALENDAR, sprintf(exmtrans('studio.bundle_calendar_name'), $name));
            static::viewColumn($calendarView, $table, $calendar, 1, ['color' => '#3c8dbc', 'font_color' => '#ffffff']);
            $made[] = strval($calendarView->view_view_name);
        }

        $chart = $columnOf($bundle['chart']);
        $chartView = null;
        $count = null;
        if (isset($chart)) {
            // the number of data by the choice: grouped by the item, the count of the ids
            $chartView = static::view($table, ViewKindType::AGGREGATE, sprintf(exmtrans('studio.bundle_chart_name'), $name, $chart->column_view_name));
            static::viewColumn($chartView, $table, $chart, 1);
            $count = new CustomViewSummary();
            $count->custom_view_id = $chartView->id;
            $count->view_column_type = ConditionType::SYSTEM;
            $count->view_column_table_id = $table->id;
            $count->view_column_target_id = SystemColumn::getOption(['name' => SystemColumn::ID])['id'];
            $count->view_summary_condition = SummaryCondition::COUNT;
            $count->save();
            $made[] = strval($chartView->view_view_name);
        }

        if ($bundle['dashboard']) {
            $dashboard = static::dashboard($table, CustomView::getDefault($table, false), $chartView, $count, $calendarView);
            if (isset($dashboard)) {
                $made[] = strval($dashboard->dashboard_view_name);
            }
        }
        return $made;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function view(CustomTable $table, string $kind, string $name, array $options = []): CustomView
    {
        $view = new CustomView();
        $view->custom_table_id = $table->id;
        $view->view_view_name = mb_substr($name, 0, BlankFormBuilder::MAX_VIEW_NAME_LENGTH);
        $view->view_type = ViewType::SYSTEM;
        $view->view_kind_type = $kind;
        $view->default_flg = false;
        $view->options = $options + ['condition_join' => 'and'];
        $view->save();
        return $view;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function viewColumn(CustomView $view, CustomTable $table, CustomColumn $column, int $order, array $options = []): void
    {
        $viewColumn = new CustomViewColumn();
        $viewColumn->custom_view_id = $view->id;
        $viewColumn->view_column_type = ConditionType::COLUMN;
        $viewColumn->view_column_table_id = $table->id;
        $viewColumn->view_column_target_id = $column->id;
        $viewColumn->order = $order;
        if (count($options) > 0) {
            $viewColumn->options = $options;
        }
        $viewColumn->save();
    }

    /**
     * The items of the screen, shown on the cards of a kanban.
     *
     * @param array<int, int> $columnIds
     */
    protected static function addColumns(CustomView $view, CustomTable $table, array $columnIds): void
    {
        $order = 1;
        foreach ($columnIds as $columnId) {
            $column = CustomColumn::getEloquent($columnId);
            if ($column instanceof CustomColumn) {
                static::viewColumn($view, $table, $column, $order++);
            }
        }
    }

    /**
     * The dashboard of the app: the list on the first row, with the chart beside
     * it; the calendar on the second row.
     */
    protected static function dashboard(CustomTable $table, CustomView $list, ?CustomView $chartView, ?CustomViewSummary $count, ?CustomView $calendarView): ?Dashboard
    {
        $type = static::dashboardType();
        if ($type === null) {
            return null;
        }
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = $type;
        $dashboard->dashboard_name = 'studio_' . short_uuid();
        $dashboard->dashboard_view_name = mb_substr(strval($table->table_view_name), 0, BlankFormBuilder::MAX_VIEW_NAME_LENGTH);
        $dashboard->default_flg = false;
        if ($type === DashboardType::USER && isset($user)) {
            $dashboard->created_user_id = $user->getUserId();
        }
        $dashboard->options = [
            'row1' => isset($chartView) ? 2 : 1,
            'row2' => isset($calendarView) ? 1 : 0,
            'row3' => 0,
            'row4' => 0,
        ];
        $dashboard->save();

        try {
            static::boxes($dashboard, $table, $list, $chartView, $count, $calendarView);
        } catch (\Throwable $e) {
            $dashboard->delete();
            throw $e;
        }
        return $dashboard;
    }

    protected static function boxes(Dashboard $dashboard, CustomTable $table, CustomView $list, ?CustomView $chartView, ?CustomViewSummary $count, ?CustomView $calendarView): void
    {
        static::box($dashboard, DashboardBoxType::LIST, strval($table->table_view_name), 1, 1, [
            'target_table_id' => $table->id,
            'target_view_id' => $list->id,
        ]);
        if (isset($chartView) && isset($count)) {
            static::box($dashboard, DashboardBoxType::CHART, strval($chartView->view_view_name), 1, 2, [
                'chart_type' => ChartType::PIE,
                'target_table_id' => $table->id,
                'target_view_id' => $chartView->id,
                'chart_axisx' => Define::CHARTITEM_LABEL,
                'chart_axisy' => ViewKindType::AGGREGATE . '_' . $count->id,
                'chart_options' => [],
            ]);
        }
        if (isset($calendarView)) {
            static::box($dashboard, DashboardBoxType::CALENDAR, strval($calendarView->view_view_name), 2, 1, [
                'calendar_type' => CalendarType::MONTH,
                'target_table_id' => $table->id,
                'target_view_id' => $calendarView->id,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function box(Dashboard $dashboard, string $type, string $name, int $row, int $column, array $options): void
    {
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = $type;
        $box->dashboard_box_view_name = mb_substr($name, 0, BlankFormBuilder::MAX_VIEW_NAME_LENGTH);
        $box->row_no = $row;
        $box->column_no = $column;
        $box->options = $options;
        $box->save();
    }
}
