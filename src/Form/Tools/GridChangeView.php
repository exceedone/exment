<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewKindType;
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
        //authority check
        if (Admin::user()->hasPermission(AuthorityValue::CUSTOM_VIEW)) {
            $query_str = '';
            if (intval($this->current_custom_view->view_kind_type) === ViewKindType::AGGREGATE) {
                $query_str = '?view_kind_type=1';
            }
            $settings[] = ['url' => admin_base_paths('view', $this->custom_table->table_name, $this->current_custom_view->id, 'edit'.$query_str), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.current_view_edit')];
            $settings[] = ['url' => admin_base_paths('view', $this->custom_table->table_name, 'create'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.create')];
            $settings[] = ['url' => admin_base_paths('view', $this->custom_table->table_name, 'create?view_kind_type=1'), 'view_view_name' => exmtrans('custom_view.custom_view_menulist.create_sum')];
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
