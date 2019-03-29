<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Services\FormHelper;
use Validator;

/**
 * Api about target table
 */
class ApiTableController extends AdminControllerTableBase
{
    protected $custom_table;

    // custom_value --------------------------------------------------
    
    /**
     * list all data
     * @return mixed
     */
    public function dataList(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        // get paginate
        $model = $this->custom_table->getValueModel();
        $model = \Exment::user()->filterModel($model, $model->custom_table);
        $paginator = $model->paginate();

        // execute makehidden
        $value = $paginator->makeHidden($this->custom_table->getMakeHiddenArray());
        $paginator->value = $value;

        return $paginator;
    }

    /**
     * find match data by query
     * use form select ajax
     * @param mixed $id
     * @return mixed
     */
    public function dataQuery(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        // get model filtered using role
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
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function dataFind($tableKey, $id, Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        $model = getModelName($this->custom_table->table_name)::find($id);
        // not contains data, return empty data.
        if(!isset($model)){
            return [];
        }

        if (!$this->custom_table->hasPermissionData($model)) {
            return abortJson(403, trans('admin.deny'));
        }

        $result = $model->makeHidden($this->custom_table->getMakeHiddenArray())
                    ->toArray();
        if ($request->has('dot') && boolval($request->get('dot'))) {
            $result = array_dot($result);
        }
        return $result;
    }

    /**
     * create data
     * @return mixed
     */
    public function dataCreate(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value = $this->custom_table->getValueModel();
        return $this->saveData($custom_value, $request);
    }

    /**
     * update data
     * @return mixed
     */
    public function dataUpdate($tableKey, $id, Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value = getModelName($this->custom_table)::find($id);
        if(!isset($custom_value)){
            abort(400);
        }

        if (!$this->custom_table->hasPermissionData($custom_value, Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        return $this->saveData($custom_value, $request);
    }

    /**
     * delete data
     * @return mixed
     */
    public function dataDelete($tableKey, $id, Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value = getModelName($this->custom_table)::find($id);
        if(!isset($custom_value)){
            abort(400);
        }

        if (!$this->custom_table->hasPermissionData($custom_value, Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value->delete();

        return response(null, 204);
    }

    /**
     * get selected id's children values
     */
    public function relatedLinkage(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)){
            return abortJson(403, trans('admin.deny'));
        }

        // get children table id
        $child_table_id = $request->get('child_table_id');
        $child_table = CustomTable::getEloquent($child_table_id);
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

    // CustomColumn --------------------------------------------------
    /**
     * get table columns
     */
    public function tableColumns(Request $request){
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        return $this->custom_columns;
    }

    
    protected function saveData($custom_value, $request){
        if(is_null($value = $request->get('value'))){
            abort(400);
        }

        // // get fields for validation
        $validate = $this->validateData($value, $custom_value->id);
        if($validate !== true){
            return abortJson(400, [
                'errors' => $validate
            ]);
        }

        // set default value if new
        if(!isset($custom_value->id)){
            $value = $this->setDefaultData($value);
        }

        $custom_value->setValue($value);
        $custom_value->saveOrFail();

        return getModelName($this->custom_table)::find($custom_value->id)->makeHidden($this->custom_table->getMakeHiddenArray());
    }

    /**
     * validate requested data
     */
    protected function validateData($value, $id = null){
        // get fields for validation
        $fields = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
            $fields[] = FormHelper::getFormField($this->custom_table, $custom_column, $id);

            // if not contains $value[$custom_column->column_name], set as null.
            // if not set, we cannot validate null check because $field->getValidator returns false.
            if(!array_has($value, $custom_column->column_name)){
                $value[$custom_column->column_name] = null;
            }
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
                if(is_array($message)){
                    $errors[] = $message[0];
                }else{
                    $errors[] = $message;
                }
            }
            return $errors;
        }
        return true;
    }

    /**
     * set Default Data from custom column info
     */
    protected function setDefaultData($value){
        // get fields for validation
        $fields = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
            // get default value
            $default = $custom_column->getOption('default');

            // if not key in value, set default value
            if(!array_has($value, $custom_column->column_name) && isset($default)){
                $value[$custom_column->column_name] = $default;
            }
        }

        return $value;
    }
}
