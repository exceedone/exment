<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Encore\Admin\Widgets\Table as WidgetTable;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

class ListItem implements ItemInterface
{
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

        // if view not found, set view first data
        if (!isset($this->custom_view)) {
            $this->custom_view = $this->custom_table->getDefault();
        }

        // get paginate
        $this->setPaginate();
    }

    /**
     * get header
     */
    public function header()
    {
        // if table not found, break
        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            return null;
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return null;
        }
    
        // check edit permission
        if ($this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            $new_url= admin_url("data/{$this->custom_table->table_name}/create");
            $list_url = admin_url("data/{$this->custom_table->table_name}?view=".$this->custom_view->suuid);
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
        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            return null;
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return trans('admin.deny');
        }
        
        $datalist = $this->paginate->items();

        // get widget table
        $option = [
            'action_callback' => function (&$link, $custom_table, $data) {
                if(count($custom_table->getRelationTables()) > 0){
                    $link .= (new Linker)
                    ->url($data->getRelationSearchUrl(true))
                    ->icon('fa-compress')
                    ->tooltip(exmtrans('search.header_relation'));
                }
            }
        ];
        list($headers, $bodies) = $this->custom_view->getDataTable($datalist, $option);
        
        $widgetTable = new WidgetTable($headers, $bodies);
        $widgetTable->class('table table-hover');

        return $widgetTable->render();
    }
    
    /**
     * get footer
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function footer()
    {
        // if table not found, break
        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            return null;
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return null;
        }

        // add link
        return $this->paginate->links('exment::search.links')->toHtml();
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form)
    {
        $form->select('pager_count', trans("admin.show"))
            ->required()
            ->options(static::getPagerOptions())
            ->default(5);

        $form->select('target_table_id', exmtrans("dashboard.dashboard_box_options.target_table_id"))
            ->required()
            ->options(CustomTable::filterList()->pluck('table_view_name', 'id'))
            ->load('options_target_view_id', admin_url('dashboardbox/table_views'));

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
        $model = $this->custom_table->getValueModel();
        // filter model
        $model = \Exment::user()->filterModel($model, $this->custom_table->table_name, $this->custom_view);
        
        // pager count
        $pager_count = $this->dashboard_box->getOption('pager_count') ?? 5;
        // get data
        $this->paginate = $model->paginate($pager_count);
    }

    /**
     * get pager select options
     */
    protected static function getPagerOptions()
    {
        $counts = [5, 10, 20];
        
        $options = [];
        foreach ($counts as $count) {
            $options[$count] = $count. ' ' . trans('admin.entries');
        }
        return $options;
    }
}
