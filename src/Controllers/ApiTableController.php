<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\Auth;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Services\FormHelper;
use Validator;

/**
 * Api about target table
 */
class ApiTableController extends AdminControllerTableBase
{
    protected $custom_table;

    /**
     * list all data
     * @return mixed
     */
    public function list(Request $request)
    {
        // get paginate
        $model = $this->custom_table->getValueModel();
        $model = \Exment::user()->filterModel($model, $model->custom_table);
        $paginator = $model->paginate();

        // execute makehidden
        $value = $paginator->makeHidden($this->getMakeHiddenArray());
        $paginator->value = $value;

        return $paginator;
    }

    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function find($id, Request $request)
    {
        if (!$this->custom_table->hasPermissionData($id)) {
            abort(403);
        }
        $result = getModelName($this->custom_table->table_name)::findOrFail($id)
            ->makeHidden($this->getMakeHiddenArray())
            ->toArray();
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
        $model = \Exment::user()->filterModel($model, $this->custom_table);

        // filtered query
        $q = $request->get('q');
        if (!isset($q)) {
            return [];
        }

        // get search target columns
        $columns = $this->custom_table->getSearchEnabledColumns();
        foreach ($columns as $column) {
            $column_name = $column->getIndexColumnName();
            $model = $model->orWhere($column_name, 'like', "%$q%");
        }
        $paginate = $model->paginate(null);

        return $paginate;
    }
    
    /**
     * create data
     * @return mixed
     */
    public function createData(Request $request)
    {
        if (!$this->custom_table->hasPermission(AuthorityValue::AVAILABLE_EDIT_CUSTOM_VALUE)){
            abort(403);
        }

        $custom_value = $this->custom_table->getValueModel();
        return $this->saveData($custom_value, $request);
    }

    /**
     * create data
     * @return mixed
     */
    public function updateData($key, Request $request)
    {
        if(!is_numeric($key)){
            $custom_value = getModelName($this->custom_table)::findBySuuid($key);
        }else{
            $custom_value = getModelName($this->custom_table)::find($key);
        }
        if (!$this->custom_table->hasPermissionData($custom_value)){
            abort(403);
        }

        return $this->saveData($custom_value, $request);
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

    protected function saveData($custom_value, $request){
        if(is_null($value = $request->get('value'))){
            abort(400);
        }

        // // get fields for validation
        $validate = $this->validateData($value, $custom_value->id);
        if($validate !== true){
            return false;
        }

        $custom_value->setValue($value);
        $custom_value->saveOrFail();

        return $custom_value;
    }

    /**
     * validate requested data
     */
    protected function validateData($value, $id = null){
        // get fields for validation
        $fields = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
            $fields[] = FormHelper::getFormField($this->custom_table, $custom_column, $id);
        }
        // foreach for field validation rules
        $rules = [];
        foreach ($fields as $field) {
            // get field validator
            $field_validator = $field->getValidator($value);
            if (!$field_validator) {
                continue;
            }
            // get field rules
            $field_rules = $field_validator->getRules();

            // merge rules
            $rules = array_merge($field_rules, $rules);
        }
        
        // execute validation
        $validator = Validator::make(array_dot_reverse($value), $rules);
        if ($validator->fails()) {
            // create error message
            $errors = [];
            foreach ($validator->errors()->messages() as $message) {
                $errors[] = $message;
            }
            return $errors;
        }
        return true;
    }

    /**
     * get array for "makeHidden" function
     */
    protected function getMakeHiddenArray(){
        return $this->custom_table->getSearchEnabledColumns()->map(function($columns){
            return $columns->getIndexColumnName();
        })->toArray();
    }
}
