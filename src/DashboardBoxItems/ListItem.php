<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Widgets\Table as WidgetTable;
use Exceedone\Exment\Enums\RoleValue;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

class ListItem implements ItemInterface
{
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
    }

    /**
     * get header
     */
    public function header()
    {
        // if table not found, break
        if (!isset($this->custom_table)) {
            return null;
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return view('exment::dashboard.list.header')->render();
        }
    
        // check edit permission
        if ($this->custom_table->hasPermission(RoleValue::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            $new_url= admin_base_path("data/{$this->custom_table->table_name}/create");
            $list_url = admin_base_path("data/{$this->custom_table->table_name}");
        } else {
            $new_url = null;
            $list_url = null;
        }

        return view('exment::dashboard.list.header', [
            'new_url' => $new_url,
            'list_url' => $list_url,
        ])->render();
    }
    
    /**
     * get body
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function body()
    {
        // if table not found, break
        if (!isset($this->custom_table)) {
            return null;
        }

        // if view not found, set view first data
        if (!isset($this->custom_view)) {
            $this->custom_view = $this->custom_table->getDefault();
        }
        if (!isset($this->custom_view)) {
            return null;
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return trans('admin.deny');
        } else {
            // create model for getting data --------------------------------------------------
            $model = $this->custom_table->getValueModel();
            // filter model
            $model = \Exment::user()->filterModel($model, $this->custom_table->table_name, $this->custom_view);
            // get data
            // TODO:only take 5 rows. add function that changing take and skip records.
            $datalist = $model->take(5)->get();

            // get widget table
            list($headers, $bodies) = $this->custom_view->getDataTable($datalist);
            $widgetTable = new WidgetTable($headers, $bodies);
            $widgetTable->class('table table-hover');

            return $widgetTable->render();
        }

        return $html;
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form)
    {
        $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
            ->required()
            ->options(CustomTable::filterList()->pluck('table_view_name', 'id'))
            ->load('options_target_view_id', admin_base_path('dashboardbox/table_views'));

        $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
            ->required()
            ->options(function ($value) {
                if (!isset($value)) {
                    return [];
                }

                return CustomView::getEloquent($value)->custom_table->custom_views()->pluck('view_view_name', 'id');
            });
    }

    /**
     * saving event
     */
    public static function saving(&$form)
    {
    }

    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
