<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Exceedone\Exment\ConditionItems\ConditionItemBase;
use Carbon\Carbon;
use Validator;

/**
 * Api about target table
 */
class ApiTableController extends AdminControllerTableBase
{
    use ApiTrait;

    protected $custom_table;

    /**
     * Execute an action on the controller.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callAction($method, $parameters)
    {
        if (!$this->custom_table) {
            return abortJson(404);
        }
        
        return call_user_func_array([$this, $method], $parameters);
    }

    // CustomColumn --------------------------------------------------
    /**
     * get table columns
     */
    public function tableColumns(Request $request)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        return $this->custom_columns;
    }

    /**
     * get column data by id
     * @param mixed $id
     * @return mixed
     */
    public function tableColumn(Request $request, $tableKey, $column_name)
    {
        return $this->responseColumn($request, CustomColumn::getEloquent($column_name, $tableKey));
    }
    

    // View ----------------------------------------------------
    
    /**
     * get view datalist
     * @param mixed $request request value
     * @return mixed
     */
    public function views(Request $request, $tableKey)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        $query = CustomView::where('custom_table_id', $this->custom_table->id);

        // set filter
        $req = $request->all();
        $keys = ['view_type', 'view_kind_type', 'view_view_name'];

        foreach($keys as $key){
            if(!is_null($v = array_get($req, $key))){
                $query->where($key, $v);
            }
        }

        return $query->get();
    }

    /**
     * get view 
     * @param mixed $idOrSuuid if length is 20, use suuid
     * @return mixed
     */
    public function view(Request $request, $tableKey, $idOrSuuid)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        $query = CustomView::where('custom_table_id', $this->custom_table->id);
        if(strlen($idOrSuuid) == 20){
            $query->where('suuid', $idOrSuuid);
        }
        else{
            $query->where('id', $idOrSuuid);
        }

        return $query->first();
    }
    


    /**
     * get filter condition
     */
    public function getFilterCondition(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('q'));
        if (!isset($item)) {
            return [];
        }
        return $item->getFilterCondition();
    }
    
    /**
     * get filter condition
     */
    public function getFilterValue(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('target'), $request->get('filter_kind'));
        if (!isset($item)) {
            return [];
        }
        return $item->getFilterValue($request->get('cond_key'), $request->get('cond_name'), boolval($request->get('show_condition_key')));
    }

    protected function getConditionItem(Request $request, $target, $filterKind = null)
    {
        $item = ConditionItemBase::getItem($this->custom_table, $target);
        if (!isset($item)) {
            return null;
        }

        $elementName = str_replace($request->get('replace_search', 'condition_key'), $request->get('replace_word', 'condition_value'), $request->get('cond_name'));
        $label = exmtrans('condition.condition_value');
        $item->setElement($elementName, 'condition_value', $label);
        if (isset($filterKind)) {
            $item->filterKind($filterKind);
        }

        return $item;
    }
}
