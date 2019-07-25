<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;

/**
 * Api about target table
 */
class ApiController extends AdminControllerBase
{
    /**
     * get Exment version
     */
    public function version(Request $request)
    {
        return response()->json(['version' => (new \Exceedone\Exment\Exment)->version(false)]);
    }

    /**
     * get login user info
     * @param mixed $id
     * @return mixed
     */
    public function me(Request $request)
    {
        $base_user = \Exment::user()->base_user ?? null;
        if (!isset($base_user)) {
            return null;
        }
        $base_user = $base_user->makeHidden(CustomTable::getEloquent(SystemTableName::USER)->getMakeHiddenArray())
            ->toArray();

        if ($request->has('dot') && boolval($request->get('dot'))) {
            $base_user = array_dot($base_user);
        }
        return $base_user;
    }

    /**
     * get table list
     * @return mixed
     */
    public function tablelist(Request $request)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // filter table
        $query = CustomTable::query();
        CustomTable::filterList($query, ['getModel' => false]);
        return $query->paginate();
    }

    /**
     * get column list
     * @return mixed
     */
    public function indexcolumns(Request $request)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // if execute as selecting column_type
        if ($request->has('custom_type')) {
            // check user or organization
            if (!ColumnType::isUserOrganization($request->get('q'))) {
                return [];
            }
        }

        $table = $request->get('q');
        if (!isset($table)) {
            return [];
        }

        return CustomTable::getEloquent($table)->custom_columns()->indexEnabled()->get();
    }

    /**
     * get filter view list
     * @return mixed
     */
    public function filterviews(Request $request)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        $table = $request->get('q');
        if (!isset($table)) {
            return [];
        }

        return CustomView
            ::where('custom_table_id', $table)
            ->where('view_kind_type', ViewKindType::FILTER)
            ->get();
    }

    /**
     * get table data by id or table_name
     * @param mixed $tableKey id or table_name
     * @return mixed
     */
    public function table($tableKey, Request $request)
    {
        $table = CustomTable::getEloquent($tableKey);
        if (!isset($table)) {
            return abort(400);
        }

        if (!$table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }
        return $table;
    }

    /**
     * get column data by id
     * @param mixed $id
     * @return mixed
     */
    public function column($id, Request $request)
    {
        $column = CustomColumn::getEloquent($id);
        if (!isset($column)) {
            return abort(400);
        }

        if (!$column->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        return $column;
    }

    /**
     * get columns that belongs table using column id
     * 1. find column and get column info
     * 2. get column target table
     * 3. get columns that belongs to target table
     * @param mixed select_table custon_column id
     */
    public function targetBelongsColumns($id)
    {
        if (!isset($id)) {
            return [];
        }
        // get custom column
        $custom_column = CustomColumn::getEloquent($id);

        // if column_type is not select_table, return []
        if (!in_array(array_get($custom_column, 'column_type'), [ColumnType::SELECT_TABLE, ColumnType::USER, ColumnType::ORGANIZATION])) {
            return [];
        }
        // get select_target_table
        $select_target_table = array_get($custom_column, 'options.select_target_table');
        if (!isset($select_target_table)) {
            return [];
        }
        return CustomTable::getEloquent($select_target_table)->custom_columns()->get(['id', 'column_view_name'])->pluck('column_view_name', 'id');
    }
    
    public function notifyPage(Request $request)
    {
        // get notify NotifyNavbar list
        $query = NotifyNavbar::where('target_user_id', \Exment::user()->base_user_id)
            ->where('read_flg', false)
            ->orderBy('created_at', 'desc');
        
        $count = $query->count();
        $list = $query->take(5)->get();

        return [
            'count' => $count,
            'items' => $list->map(function ($l) {
                $custom_table = CustomTable::getEloquent(array_get($l, 'parent_type'));
                if (isset($custom_table)) {
                    $icon = $custom_table->getOption('icon');
                    $color = $custom_table->getOption('color');
                    $table_view_name = $custom_table->table_view_name;
                }

                return [
                    'id' => array_get($l, 'id'),
                    'icon' => $icon ?? 'fa-bell',
                    'color' => $color ?? null,
                    'table_view_name' => $table_view_name ?? null,
                    'label' => array_get($l, 'notify_subject'),
                    'href' => admin_urls('notify_navbar', $l->id)
                ];
            }),
            'noItemMessage' => exmtrans('notify_navbar.message.no_newitem')
        ];
    }
}
