<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\Enums\Permission;

trait TableItemTrait
{
    /**
     * Has show permission this dashboard item
     *
     * @return boolean
     */
    protected function hasPermission()
    {
        // if table not found, break
        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            return exmtrans('dashboard.message.not_exists_table');
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return trans('admin.deny');
        }

        return true;
    }

    protected function tableheader()
    {
        if (($result = $this->hasPermission()) !== true) {
            return null;
        }

        // check edit permission
        if ($this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            $new_url = admin_url("data/{$this->custom_table->table_name}/create");
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
     * get dashboard attributes for display html
     *
     * @return void
     */
    public function attributes()
    {
        return [
            'pager_count' => $this->dashboard_box->getOption('pager_count'),
            'target_table_id' => isset($this->custom_table) ? $this->custom_table->id : null,
            'target_table_name' => isset($this->custom_table) ? $this->custom_table->table_name : null,
            'target_view_nane' => isset($this->custom_view) ? $this->custom_view->view_view_name : null,
        ];
    }

}
