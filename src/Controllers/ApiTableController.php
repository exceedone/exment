<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

/**
 * Api about target table 
 */
class ApiTableController extends AdminControllerTableBase
{
    protected $custom_table;

    public function __construct(Request $request){
        parent::__construct($request);
    }

    /**
     * find data by id
     * use select linkage
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
     * use form select ajax
     * @param mixed $id
     * @return mixed
     */
    public function query(Request $request){
        // get model filtered using authority
        $model = getModelName($this->custom_table->table_name)::query();
        Admin::user()->filterModel($model, $this->custom_table);

        // filtered query 
        $q = $request->get('q');
        $labelcolumn = getLabelColumn($this->custom_table);
        $column_name = getColumnName($labelcolumn);
        return $model->where($column_name, 'like', "%$q%")->paginate(null, ['id', $column_name.' as text']);
    }
    
    /**
     * get selected id7s children values
     */
    public function relatedLinkage(Request $request){
        // get children table id
        $child_table_id = $request->get('child_table_id');
        $child_table = CustomTable::find($child_table_id);
        // get selected custom_value id(q)
        $q = $request->get('q');

        // get children items
        $labelcolumn = getLabelColumn($child_table);
        $column_name = getColumnName($labelcolumn);
        return getModelName($child_table)
            ::where('parent_id', $q)
            ->where('parent_type', $this->custom_table->table_name)
            ->paginate(null, ['id', $column_name.' as text']);
    }
}

