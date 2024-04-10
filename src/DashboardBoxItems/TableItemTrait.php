<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;

trait TableItemTrait
{
    /**
     * Has show permission this dashboard item
     *
     * @return array|string|boolean
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
     * @return array
     */
    public function attributes()
    {
        return [
            'target_table_id' => isset($this->custom_table) ? $this->custom_table->id : null,
            'target_table_name' => isset($this->custom_table) ? $this->custom_table->table_name : null,
            'target_view_view_name' => isset($this->custom_view) ? $this->custom_view->view_view_name : null,
        ];
    }

    public static function getCustomViewSelectOptions($value, $field, $model, $dashboard, bool $isCalendar = false): array
    {
        if (is_nullorempty($field)) {
            return [];
        }

        // check $value or $field->data()
        $custom_table = null;
        if (isset($value)) {
            $custom_view = CustomView::getEloquent($value);
            $custom_table = $custom_view ? $custom_view->custom_table : null;
        } elseif (!is_nullorempty($field->data())) {
            $custom_table = CustomTable::getEloquent(array_get($field->data(), 'target_table_id'));
        }

        if (!isset($custom_table)) {
            return [];
        }

        return $custom_table->custom_views
            ->filter(function ($value) use ($isCalendar) {
                if ($isCalendar) {
                    return array_get($value, 'view_kind_type') == ViewKindType::CALENDAR;
                }
                return array_get($value, 'view_kind_type') != ViewKindType::CALENDAR;
            })
            ->filter(function ($value) use ($dashboard) {
                if (array_get($dashboard, 'dashboard_type') != DashboardType::SYSTEM) {
                    return true;
                }
                return array_get($value, 'view_type') == ViewType::SYSTEM;
            })->pluck('view_view_name', 'id')->toArray();
    }
}
