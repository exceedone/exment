<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\CalendarType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;

class CalendarItem implements ItemInterface
{
    use TableItemTrait;

    protected $dashboard_box;

    protected $custom_table;

    protected $custom_view;


    public function __construct($dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;

        $table_id = array_get($this->dashboard_box, 'options.target_table_id');
        $view_id = array_get($this->dashboard_box, 'options.target_view_id');

        // get table and view
        $this->custom_table = CustomTable::getEloquent($table_id);
        $this->custom_view = CustomView::getEloquent($view_id);

        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            return;
        }
    }


    /**
     * get header
     */
    public function header()
    {
        return $this->tableheader();
    }

    /**
     * get footer
     */
    public function footer()
    {
        return null;
    }

    /**
     * saving event
     */
    public static function saving(&$form)
    {
    }

    /**
     * get html(for display)
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function body()
    {
        if (($result = $this->hasPermission()) !== true) {
            return $result;
        }

        // create model for getting data --------------------------------------------------
        $model = $this->custom_table->getValueQuery();
        // filter model
        $this->custom_view->filterSortModel($model);

        $options = $this->dashboard_box->options;

        return view('exment::dashboard.calendar.calendar', [
            'suuid' => $this->dashboard_box->suuid,
            'calendar_type' => array_get($options, 'calendar_type'),
            'view_id' => $this->custom_view->suuid,
            'data_url' => admin_url('webapi/data', [$this->custom_table->table_name, 'calendar']),
            'locale' => \App::getLocale(),
        ])->render();
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        $form->select('calendar_type', exmtrans("dashboard.dashboard_box_options.calendar_type"))
                ->required()
                ->options(CalendarType::transArray("calendar.calendar_type_options"));

        // get only has calendarview
        $model = CustomTable::whereHas('custom_views', function ($query) {
            $query->where('view_kind_type', ViewKindType::CALENDAR);
        });
        $tables = CustomTable::filterList($model, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])
            ->pluck('table_view_name', 'id');
        $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
            ->required()
            ->options($tables)
            ->attribute([
                'data-linkage' => json_encode(['options_target_view_id' => admin_urls('dashboardbox', 'table_views', DashboardBoxType::CALENDAR)]),
                'data-linkage-expand' => json_encode(['dashboard_suuid' => $dashboard->suuid])
            ]);

        $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
            ->required()
            ->options(function ($value, $field, $model) use ($dashboard) {
                return ListItem::getCustomViewSelectOptions($value, $field, $model, $dashboard, true);
            });
    }

    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
