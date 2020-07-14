<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Widgets\Table as WidgetTable;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;

class ListItem implements ItemInterface
{
    use TableItemTrait;

    protected $dashboard_box;
    
    protected $custom_table;
    
    protected $custom_view;
    
    protected $paginate;
    
    public function __construct($dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;

        $table_id = array_get($this->dashboard_box, 'options.target_table_id');
        $view_id = array_get($this->dashboard_box, 'options.target_view_id');

        // get table and view
        $this->custom_table = CustomTable::getEloquent($table_id);
        $this->custom_view = CustomView::getEloquent($view_id);

        if (!isset($this->custom_table)) {
            return;
        }

        if (!isset($this->custom_view)) {
            return;
        }

        // get paginate
        $this->setPaginate();
    }

    /**
     * get header
     */
    public function header()
    {
        return $this->tableheader();
    }
    
    /**
     * get body
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function body()
    {
        if (($result = $this->hasPermission()) !== true) {
            return $result;
        }
        
        $datalist = $this->paginate->items();

        // get widget table
        $option = [
            'action_callback' => function (&$link, $custom_table, $data) {
                if (count($custom_table->getRelationTables()) > 0) {
                    $link .= (new Linker)
                    ->url($data->getRelationSearchUrl(true))
                    ->icon('fa-compress')
                    ->tooltip(exmtrans('search.header_relation'));
                }
            }
        ];
        list($headers, $bodies, $columnStyles, $columnClasses) = $this->custom_view->convertDataTable($datalist, $option);
        
        $widgetTable = new WidgetTable($headers, $bodies);
        $widgetTable->class('table table-hover');
        $widgetTable->setColumnStyle($columnStyles);
        $widgetTable->setColumnClasses($columnClasses);

        return $widgetTable->render();
    }
    
    /**
     * get footer
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function footer()
    {
        if (($result = $this->hasPermission()) !== true) {
            return null;
        }

        // add link
        return $this->paginate->links('exment::search.links')->toHtml();
    }


    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        $form->select('pager_count', trans("admin.show"))
            ->required()
            ->options(getPagerOptions(true, Define::PAGER_DATALIST_COUNTS))
            ->config('allowClear', false)
            ->default(0);

        $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
            ->required()
            ->options(CustomTable::filterList(null, [
                'permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE,
            ])->pluck('table_view_name', 'id'))
            ->attribute([
                'data-linkage' => json_encode(['options_target_view_id' => admin_urls('dashboardbox', 'table_views', DashboardBoxType::LIST)]),
                'data-linkage-expand' => json_encode(['dashboard_suuid' => $dashboard->suuid])
            ]);

        $form->select('target_view_id', exmtrans("dashboard.dashboard_box_options.target_view_id"))
            ->required()
            ->options(function ($value, $data) use ($dashboard) {
                if (is_nullorempty($data) || is_nullorempty($data->data())) {
                    return [];
                }

                $custom_table = CustomTable::getEloquent(array_get($data->data(), 'target_table_id'));
                if (is_nullorempty($custom_table)) {
                    return [];
                }

                return $custom_table->custom_views
                    ->filter(function ($value) {
                        return array_get($value, 'view_kind_type') != ViewKindType::CALENDAR;
                    })
                    ->filter(function ($value) use ($dashboard) {
                        if (array_get($dashboard, 'dashboard_type') != DashboardType::SYSTEM) {
                            return true;
                        }
                        return array_get($value, 'view_type') == ViewType::SYSTEM;
                    })->pluck('view_view_name', 'id');
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

    /**
     * set paginate
     */
    protected function setPaginate()
    {
        // if table not found, break
        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            return null;
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return;
        }
        
        // create model for getting data --------------------------------------------------
        $model = $this->custom_table->getValueModel()::query();

        if (array_get($this->custom_view, 'view_kind_type') == ViewKindType::AGGREGATE) {
            // filter model
            $model = $this->custom_view->getValueSummary($model, $this->custom_table->table_name);
        } else {
            // filter model
            $this->custom_view->filterModel($model);
        }
        
        // pager count
        $pager_count = $this->dashboard_box->getOption('pager_count');
        if (!isset($pager_count) || $pager_count == 0) {
            $pager_count = System::datalist_pager_count() ?? 5;
        }

        $this->custom_table->setQueryWith($model, $this->custom_view);

        // get data
        $this->paginate = $model->paginate($pager_count);
    }
}
