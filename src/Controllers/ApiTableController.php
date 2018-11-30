<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;

/**
 * Api about target table
 */
class ApiTableController extends AdminControllerTableBase
{
    protected $custom_table;

    public function __construct(Request $request)
    {
        parent::__construct($request);
    }

    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function find($id, Request $request)
    {
        if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
            abort(403);
        }
        $result = getModelName($this->custom_table->table_name)::findOrFail($id)->toArray();
        if ($request->has('dot') && boolval($request->get('dot'))) {
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
    public function query(Request $request)
    {
        // get model filtered using authority
        $model = getModelName($this->custom_table)::query();
        $model = Admin::user()->filterModel($model, $this->custom_table);

        // filtered query
        $q = $request->get('q');
        if (!isset($q)) {
            return [];
        }

        // get search target columns
        $columns = $this->custom_table->getSearchEnabledColumns();
        foreach ($columns as $column) {
            $column_name = getIndexColumnName($column);
            $model = $model->orWhere($column_name, 'like', "%$q%");
        }
        $paginate = $model->paginate(null);

        return $paginate;
    }
    
    /**
     * get selected id7s children values
     */
    public function relatedLinkage(Request $request)
    {
        // get children table id
        $child_table_id = $request->get('child_table_id');
        $child_table = CustomTable::find($child_table_id);
        // get selected custom_value id(q)
        $q = $request->get('q');

        // get children items
        $datalist = getModelName($child_table)
            ::where('parent_id', $q)
            ->where('parent_type', $this->custom_table->table_name)
            ->get()->pluck('label', 'id');
        return collect($datalist)->map(function ($value, $key) {
            return ['id' => $key, 'text' => $value];
        });
    }
}
