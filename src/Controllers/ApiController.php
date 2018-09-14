<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

class ApiController extends AdminControllerBase
{
    protected $custom_table;

    public function __construct(Request $request){
        $this->custom_table = getEndpointTable();
    }

    /**
     * get table data by id
     * @param mixed $id
     * @return mixed
     */
    public function table($id, Request $request){
        // if(!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)){
        //     abort(403);
        // }
        $result = CustomTable::find($id);
        return $result;
    }

    /**
     * find data by id
     * @param mixed $id
     * @return mixed
     */
    public function find($id, Request $request){
        if(!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)){
            abort(403);
        }
        $result = getModelName($this->custom_table->table_name)::findOrFail($id)->toArray();
        if($request->has('dot') && boolval($request->get('dot'))){
            $result = array_dot($result);
        }
        return $result;
    }

    /**
     * find match data by query
     * @param mixed $id
     * @return mixed
     */
    public function query(Request $request){
        // get model filtered using authority
        $model = getModelName($this->custom_table->table_name)::query();
        Admin::user()->filterModel($model, $this->custom_table->table_name);

        // filtered query 
        $q = $request->get('q');
        $labelcolumn = getLabelColumn($this->custom_table);
        $column_name = getColumnName($labelcolumn);
        return $model->where($column_name, 'like', "%$q%")->paginate(null, ['id', $column_name.' as text']);
    }

    /**
     * get columns that belongs table using column id
     * @param mixed select_table custon_column id
     */
    public function targetBelongsColumns($id){
        if(!isset($id)){return [];}
        // get custom column
        $custom_column = CustomColumn::find($id);

        // if column_type is not select_table, return []
        if(!in_array(array_get($custom_column, 'column_type'), ['select_table', Define::SYSTEM_TABLE_NAME_USER, Define::SYSTEM_TABLE_NAME_ORGANIZATION])){
            return [];
        }
        // get select_target_table
        $select_target_table = array_get($custom_column, 'options.select_target_table');
        if(!isset($select_target_table)){return [];}
        return CustomTable::find($select_target_table)->custom_columns()->get(['id', 'column_view_name'])->pluck('column_view_name', 'id');
    }
}

