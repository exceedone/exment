<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\ColumnType;

class ApiController extends AdminControllerBase
{
    public function __construct(Request $request)
    {
    }

    /**
     * get table data by id
     * @param mixed $id
     * @return mixed
     */
    public function table($id, Request $request)
    {
        $table = CustomTable::find($id);
        if (!Admin::user()->hasPermissionTable($table, AuthorityValue::CUSTOM_TABLE)) {
            abort(403);
        }
        return $result;
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
        $custom_column = CustomColumn::find($id);

        // if column_type is not select_table, return []
        if (!in_array(array_get($custom_column, 'column_type'), [ColumnType::SELECT_TABLE, ColumnType::USER, ColumnType::ORGANIZATION])) {
            return [];
        }
        // get select_target_table
        $select_target_table = array_get($custom_column, 'options.select_target_table');
        if (!isset($select_target_table)) {
            return [];
        }
        return CustomTable::find($select_target_table)->custom_columns()->get(['id', 'column_view_name'])->pluck('column_view_name', 'id');
    }
}
