<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewType;
use Encore\Admin\Facades\Admin;
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
        if (Admin::user()->hasPermission(Permission::CUSTOM_VIEW)) {
            $settings[] = ['url' => admin_base_paths('view', $this->custom_table->table_name, $this->current_custom_view->id, 'edit'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.current_view_edit')];
            $settings[] = ['url' => admin_base_paths('view', $this->custom_table->table_name, 'create'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.create')];
        }

        return view('exment::tools.view-button', [
            'current_custom_view' => $this->current_custom_view,
            'systemviews' => $systemviews,
            'userviews' => $userviews,
            'settings' => $settings,
            'base_uri' => admin_base_paths('data', $this->custom_table->table_name)
            ]);
    }
}
