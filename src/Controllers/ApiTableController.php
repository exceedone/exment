<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Services\FormHelper;
use Carbon\Carbon;
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
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // get paginate
        $model = $this->custom_table->getValueModel();
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
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
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

        $paginator = $this->custom_table->searchValue($q, [
            'paginate' => true,
            'makeHidden' => true,
        ]);
        return $paginator;
    }
    
    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function dataFind(Request $request, $tableKey, $id)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        $model = getModelName($this->custom_table->table_name)::find($id);
        // not contains data, return empty data.
        if (!isset($model)) {
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
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value = $this->custom_table->getValueModel();
        return $this->saveData($custom_value, $request);
    }

    /**
     * update data
     * @return mixed
     */
    public function dataUpdate(Request $request, $tableKey, $id)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value = getModelName($this->custom_table)::find($id);
        if (!isset($custom_value)) {
            abort(400);
        }

        if (!$this->custom_table->hasPermissionData($custom_value, Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        return $this->saveData($custom_value, $request);
    }

    /**
     * delete data
     * @return mixed
     */
    public function dataDelete(Request $request, $tableKey, $id)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value = getModelName($this->custom_table)::find($id);
        if (!isset($custom_value)) {
            abort(400);
        }

        if (!$this->custom_table->hasPermissionData($custom_value, Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        $custom_value->delete();

        if (boolval($request->input('webresponse'))) {
            return response([
                'result'  => true,
                'message' => trans('admin.delete_succeeded'),
            ], 200);
        }
        return response(null, 204);
    }

    /**
     * get selected id's children values
     */
    public function relatedLinkage(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // get children table id
        $child_table_id = $request->get('child_table_id');
        $child_table = CustomTable::getEloquent($child_table_id);
        // get selected custom_value id(q)
        $q = $request->get('q');

        $datalist = $this->custom_table->searchRelationValue($request->get('search_type'), $q, $child_table, [
            'paginate' => false,
            'maxCount' => null,
        ]);
        // get children items
        // $datalist = getModelName($child_table)
        //     ::where('parent_id', $q)
        //     ->where('parent_type', $this->custom_table->table_name)
        //     ->get()->pluck('label', 'id');
        return collect($datalist)->map(function ($data) {
            return ['id' => $data->id, 'text' => $data->label];
        });
    }

    // CustomColumn --------------------------------------------------
    /**
     * get table columns
     */
    public function tableColumns(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        return $this->custom_columns;
    }

    
    protected function saveData($custom_value, $request)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ]);
        }

        $value = $request->get('value');
        // replace items
        if (!is_null($findKeys = $request->get('findKeys'))) {
            $custom_table = $custom_value->custom_table;
            foreach ($findKeys as $findKey => $findValue) {
                // find column
                $custom_column = CustomColumn::getEloquent($findKey, $custom_table);
                if (!isset($custom_column)) {
                    continue;
                }

                if ($custom_column->column_type != ColumnType::SELECT_TABLE) {
                    continue;
                }

                // get target custom table
                $findCustomTable = $custom_column->select_target_table;
                if (!isset($findCustomTable)) {
                    continue;
                }

                // get target column for getting index
                $findCustomColumn = CustomColumn::getEloquent($findValue, $findCustomTable);
                if (!isset($findCustomColumn)) {
                    continue;
                }

                if (!$findCustomColumn->indexEnabled()) {
                    //TODO:show error
                    continue;
                }
                $indexColumnName = $findCustomColumn->getIndexColumnName();

                $findCustomValue = $findCustomTable->getValueModel()
                    ->where($indexColumnName, array_get($value, $findKey))
                    ->first();

                if (!isset($findCustomValue)) {
                    //TODO:show error
                    continue;
                }
                array_set($value, $findKey, array_get($findCustomValue, 'id'));
            }
        }


        // // get fields for validation
        $validate = $this->validateData($value, $custom_value->id);
        if ($validate !== true) {
            return abortJson(400, [
                'errors' => $validate
            ]);
        }

        // set default value if new
        if (!isset($custom_value->id)) {
            $value = $this->setDefaultData($value);
        }

        $custom_value->setValue($value);
        $custom_value->saveOrFail();

        return getModelName($this->custom_table)::find($custom_value->id)->makeHidden($this->custom_table->getMakeHiddenArray());
    }

    /**
     * validate requested data
     */
    protected function validateData($value, $id = null)
    {
        // get fields for validation
        $fields = [];
        $customAttributes = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
            $fields[] = FormHelper::getFormField($this->custom_table, $custom_column, $id);
            $customAttributes[$custom_column->column_name] = "{$custom_column->column_view_name}({$custom_column->column_name})";

            // if not contains $value[$custom_column->column_name], set as null.
            // if not set, we cannot validate null check because $field->getValidator returns false.
            if (!array_has($value, $custom_column->column_name)) {
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
        $validator = Validator::make(array_dot_reverse($value), $rules, [], $customAttributes);
        if ($validator->fails()) {
            // create error message
            return $this->getErrorMessages($validator);
        }
        return true;
    }

    /**
     * Get error message from validator
     *
     * @param [type] $validator
     * @return array error messages
     */
    protected function getErrorMessages($validator)
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $key => $message) {
            if (is_array($message)) {
                $errors[$key] = $message[0];
            } else {
                $errors[$key] = $message;
            }
        }
        return $errors;
    }

    /**
     * set Default Data from custom column info
     */
    protected function setDefaultData($value)
    {
        // get fields for validation
        $fields = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
            // get default value
            $default = $custom_column->getOption('default');

            // if not key in value, set default value
            if (!array_has($value, $custom_column->column_name) && isset($default)) {
                $value[$custom_column->column_name] = $default;
            }
        }

        return $value;
    }
    
    /**
     * get calendar data
     * @return mixed
     */
    public function calendarList(Request $request)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // filtered query
        $start = $request->get('start');
        $end = $request->get('end');
        if (!isset($start) || !isset($end)) {
            return [];
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        $table_name = $this->custom_table->table_name;
        // get paginate
        $model = $this->custom_table->getValueModel();
        // filter model
        $model = \Exment::user()->filterModel($model, $table_name, $this->custom_view);

        $tasks = [];
        foreach ($this->custom_view->custom_view_columns as $custom_view_column) {
            if ($custom_view_column->view_column_type == ViewColumnType::COLUMN) {
                $target_start_column = $custom_view_column->custom_column->getIndexColumnName();
            } else {
                $target_start_column = SystemColumn::getOption(['id' => $custom_view_column->view_column_target_id])['name'];
            }

            if (isset($custom_view_column->view_column_end_date)) {
                $end_date_target = $custom_view_column->getOption('end_date_target');
                if ($custom_view_column->view_column_end_date_type == ViewColumnType::COLUMN) {
                    $target_end_custom_column = CustomColumn::getEloquent($end_date_target);
                    $target_end_column = $target_end_custom_column->getIndexColumnName();
                } else {
                    $target_end_column = SystemColumn::getOption(['id' => $end_date_target])['name'];
                }
            } else {
                $target_end_column = null;
            }

            // clone model for re use
            $query = $this->getCalendarQuery($model, $start, $end, $target_start_column, $target_end_column ?? null);
            $data = $query->get();

            foreach ($data as $row) {
                $task = [
                    'title' => $row->getLabel(),
                    'url' => admin_url('data', [$table_name, $row->id]),
                    'color' => $custom_view_column->view_column_color,
                    'textColor' => $custom_view_column->view_column_font_color,
                ];
                $this->setCalendarDate($task, $row, $target_start_column, $target_end_column);
                
                $tasks[] = $task;
            }
        }
        return json_encode($tasks);
    }

    /**
     * Get calendar query
     * ex. display: 4/1 - 4/30
     *
     * @param mixed $query
     * @return void
     */
    protected function getCalendarQuery($model, $start, $end, $target_start_column, $target_end_column)
    {
        $query = clone $model;
        // filter end data
        if (isset($target_end_column)) {
            // filter enddate.
            // ex. 4/1 - endDate - 4/30
            $endQuery = (clone $query);
            $endQuery = $endQuery->where((function ($query) use ($target_end_column, $start, $end) {
                $query->where($target_end_column, '>=', $start->toDateString())
                ->where($target_end_column, '<', $end->toDateString());
            }))->select('id');

            // filter start and enddate.
            // ex. startDate - 4/1 - 4/30 - endDate
            $startEndQuery = (clone $query);
            $startEndQuery = $startEndQuery->where((function ($query) use ($target_start_column, $target_end_column, $start, $end) {
                $query->where($target_start_column, '<=', $start->toDateString())
                ->where($target_end_column, '>=', $end->toDateString());
            }))->select('id');
        }

        if ($query instanceof \Illuminate\Database\Eloquent\Model) {
            $query = $query->getQuery();
        }

        // filter startDate
        // ex. 4/1 - startDate - 4/30
        $query->where(function ($query) use ($target_start_column, $start, $end) {
            $query->where($target_start_column, '>=', $start->toDateString())
            ->where($target_start_column, '<', $end->toDateString());
        })->select('id');

        // union queries
        if (isset($endQuery)) {
            $query->union($endQuery);
        }
        if (isset($startEndQuery)) {
            $query->union($startEndQuery);
        }

        // get target ids
        $ids = \DB::query()->fromSub($query, 'sub')->pluck('id');

        // return as eloquent
        return $model->whereIn('id', $ids);
    }

    /**
     * Set calendar date. check date or datetime
     *
     * @param array $task
     * @param mixed $row
     * @return void
     */
    protected function setCalendarDate(&$task, $row, $target_start_column, $target_end_column)
    {
        $dt = $row->{$target_start_column};
        if (isset($target_end_column)) {
            $dtEnd = $row->{$target_end_column};
        } else {
            $dtEnd = null;
        }

        if ($dt instanceof Carbon) {
            $dt = $dt->toDateTimeString();
        }
        if (isset($dtEnd) && $dtEnd instanceof Carbon) {
            $dtEnd = $dtEnd->toDateTimeString();
        }
        
        // get columnType
        $dtType = ColumnType::getDateType($dt);
        $dtEndType = ColumnType::getDateType($dtEnd);

        // set
        $allDayBetween = $dtType == ColumnType::DATE && $dtEndType == ColumnType::DATE;
        
        $task['start'] = $dt;
        if (isset($dtEnd)) {
            $task['end'] = $dtEnd;
        }
        $task['allDayBetween'] = $allDayBetween;
    }
}
