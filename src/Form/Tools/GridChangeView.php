<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Encore\Admin\Grid\Tools\AbstractTool;

class GridChangeView extends AbstractTool
{
    protected $custom_table;
    protected $current_custom_view;

    public function __construct($custom_table, $current_custom_view)
    {
        $this->custom_table = $custom_table;
        $this->current_custom_view = $current_custom_view;
    }

    public function render()
    {
        $systemviews = [];
        $userviews = [];
        // get custom view
        $custom_views = $this->custom_table->custom_views;

        foreach ($custom_views as $v) {
            if ($v->view_type == ViewType::USER) {
                $userviews[] = $v->toArray();
            } else {
                $systemviews[] = $v->toArray();
            }
        }

        // setting menu list
        $settings = [];
        //role check
        //if ($this->custom_table->hasPermission(Permission::CUSTOM_VIEW)) {
        $query_str = '';
        switch (intval($this->current_custom_view->view_kind_type)) {
            case ViewKindType::AGGREGATE:
                $query_str = '?view_kind_type=1&from_data=1';
                break;
            case ViewKindType::CALENDAR:
                $query_str = '?view_kind_type=2&from_data=1';
                break;
            default:
                $query_str = '?from_data=1';
                break;
        }
        
        $settings[] = ['url' => admin_urls('view', $this->custom_table->table_name, $this->current_custom_view->id, 'edit'.$query_str), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.current_view_edit')];
        $settings[] = ['url' => admin_urls('view', $this->custom_table->table_name, 'create?from_data=1&copy_id=' . $this->current_custom_view->id), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.current_view_replicate')];
        $settings[] = ['url' => admin_urls('view', $this->custom_table->table_name, 'create?from_data=1'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.create')];
        $settings[] = ['url' => admin_urls('view', $this->custom_table->table_name, 'create?view_kind_type=1&from_data=1'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.create_sum')];
        $settings[] = ['url' => admin_urls('view', $this->custom_table->table_name, 'create?view_kind_type=2&from_data=1'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.create_calendar')];
        //}

        return view('exment::tools.view-button', [
            'current_custom_view' => $this->current_custom_view,
            'systemviews' => $systemviews,
            'userviews' => $userviews,
            'settings' => $settings,
            'base_uri' => admin_urls('data', $this->custom_table->table_name)
            ]);
    }
}
