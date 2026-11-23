<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\ColumnItems\CustomColumns\SelectTable;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\ReferencePrefetcher;
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
    // @phpstan-ignore-next-line
    protected function hasPermission()
    {
        // if table not found, break
        if (!isset($this->custom_table) || !isset($this->custom_view)) {
            /** @phpstan-ignore-next-line */
            return exmtrans('dashboard.message.not_exists_table');
        }

        // if not access permission
        if (!$this->custom_table->hasPermission()) {
            return trans('admin.deny');
        }

        return true;
    }

    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function attributes()
    {
        return [
            'target_table_id' => isset($this->custom_table) ? $this->custom_table->id : null,
            'target_table_name' => isset($this->custom_table) ? $this->custom_table->table_name : null,
            'target_view_view_name' => isset($this->custom_view) ? $this->custom_view->view_view_name : null,
        ];
    }

    // @phpstan-ignore-next-line
    public static function getCustomViewSelectOptions($value, $field, $model, $dashboard, bool $isCalendar = false): array
    {
        /** @phpstan-ignore-next-line */
        if (is_nullorempty($field)) {
            return [];
        }

        // check $value or $field->data()
        $custom_table = null;
        if (isset($value)) {
            $custom_view = CustomView::getEloquent($value);
            $custom_table = $custom_view ? $custom_view->custom_table : null;
        /** @phpstan-ignore-next-line */
        } elseif (!is_nullorempty($field->data())) {
            $custom_table = CustomTable::getEloquent(array_get($field->data(), 'target_table_id'));
        }

        // of a table this user may view only, as its ajax list (table_views): the form is open
        // to anyone who may view the dashboard, with any view id posted back
        if (!isset($custom_table) || !$custom_table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return [];
        }

        return $custom_table->custom_views
            ->filter(function ($value) use ($isCalendar) {
                if ($isCalendar) {
                    return array_get($value, 'view_kind_type') == ViewKindType::CALENDAR;
                }
                return ViewKindType::isListable(array_get($value, 'view_kind_type'));
            })
            ->filter(function ($value) use ($dashboard) {
                if (array_get($dashboard, 'dashboard_type') != DashboardType::SYSTEM) {
                    return true;
                }
                return array_get($value, 'view_type') == ViewType::SYSTEM;
            })->pluck('view_view_name', 'id')->toArray();
    }

    /**
     * Read together the records the page's columns point at: a select_table, user or
     * organization column, a column of the record it points at (through that pivot column),
     * the parent, and the files of a file or image column. One query per table for the page,
     * not one per row and column (ISS00413). Only what the view shows: a small view reads
     * nothing more.
     *
     * @param \Illuminate\Support\Collection<int, mixed> $rows  the custom values
     * @param iterable<mixed> $view_columns  the CustomViewColumns shown
     * @return void
     */
    protected function prefetchReferences($rows, $view_columns)
    {
        ReferencePrefetcher::prefetch($this->custom_table, $rows, $view_columns);
    }

    /**
     * Read together the records the groups of an aggregate view point at (a group by a
     * select_table, user or organization column): their labels were read one query per group
     * (ISS00413).
     *
     * @param iterable<mixed> $rows  the summary rows
     * @param iterable<mixed> $items  the column items of the group columns (summary options set)
     * @return void
     */
    protected function prefetchSummaryTargets($rows, $items)
    {
        foreach ($items as $item) {
            if (!($item instanceof SelectTable) || is_null($target = $item->getSelectTable())) {
                continue;
            }
            $values = [];
            foreach ($rows as $row) {
                $values[] = data_get($row, $item->uniqueName());
            }
            $values = array_filter($values);
            if (!empty($values)) {
                $target->setCustomValueModels($values);
            }
        }
    }
}
