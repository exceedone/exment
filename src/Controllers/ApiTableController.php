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

    // custom_value --------------------------------------------------
    
    /**
     * list all data
     * @return mixed
     */
    public function dataList(Request $request)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $orderby = null;
        $orderby_list = [];
        if ($request->has('orderby')) {
            $orderby = $request->get('orderby');
            $params = explode(',', $orderby);
            $orderby_list = [];
            foreach ($params as $param) {
                $values = preg_split("/\s+/", trim($param));
                $column_name = $values[0];
                if (count($values) > 1 && !preg_match('/^asc|desc$/i', $values[1])) {
                    return abortJson(400, ErrorCode::INVALID_PARAMS());
                }
                if (SystemColumn::isSqlValid($column_name)) {
                } else {
                    $column = CustomColumn::getEloquent($column_name, $this->custom_table);
                    if (!isset($column)) {
                        return abortJson(400, ErrorCode::INVALID_PARAMS());
                    } elseif (!$column->index_enabled) {
                        return abortJson(400, ErrorCode::NOT_INDEX_ENABLED());
                    }
                    $column_name = $column->getIndexColumnName();
                }
                $orderby_list[] = [$column_name, count($values) > 1? $values[1]: 'asc'];
            }
        }
        
        // get paginate
        $model = $this->custom_table->getValueModel()->query();

        // filterd by id
        if ($request->has('id')) {
            $ids = explode(',', $request->get('id'));
            $model->whereIn('id', $ids);
        }

        // set order by
        if (isset($orderby_list)) {
            $hasId = false;
            foreach ($orderby_list as $item) {
                if ($item[0] == 'id') {
                    $hasId = true;
                }
                $model->orderBy($item[0], $item[1]);
            }

            if (!$hasId) {
                $model->orderBy('id');
            }
        }

        $paginator = $model->paginate($count);

        return $this->modifyAfterGetValue($request, $paginator, [
            'appends' => [
                'count' => $count,
                'orderby' => $orderby,
            ]
        ]);
    }

    /**
     * find match data for select ajax
     * @param mixed $id
     * @return mixed
     */
    public function dataSelect(Request $request)
    {
        $paginator = $this->dataQuery($request);
        if (!isset($paginator)) {
            return [];
        }
        
        if (!($paginator instanceof \Illuminate\Pagination\LengthAwarePaginator)) {
            return $paginator;
        }
        // if call as select ajax, return id and text array
        $paginator->getCollection()->transform(function ($value) {
            return [
                'id' => $value->id,
                'text' => $value->label,
            ];
        });

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
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        // get model filtered using role
        $model = getModelName($this->custom_table)::query();
        \Exment::user()->filterModel($model);

        $validator = Validator::make($request->all(), [
            'q' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        // filtered query
        $q = $request->get('q');
        
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        // get custom_view
        $custom_view = null;
        if ($request->has('target_view_id')) {
            $custom_view = CustomView::getEloquent($request->get('target_view_id'));
        }

        $getLabel = $this->isAppendLabel($request);
        $paginator = $this->custom_table->searchValue($q, [
            'paginate' => true,
            'makeHidden' => true,
            'target_view' => $custom_view,
            'maxCount' => $count,
            'getLabel' => $getLabel,
        ]);
        
        return $this->modifyAfterGetValue($request, $paginator, [
            'appends' => [
                'q' => $q,
                'count' => $count,
            ]
        ]);
    }
    
    /**
     * find match data by column query
     * use form select ajax
     * @param mixed $id
     * @return mixed
     */
    public function dataQueryColumn(Request $request, $tableKey)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        // get model filtered using role
        $model = getModelName($this->custom_table)::query();
        \Exment::user()->filterModel($model);

        $validator = Validator::make($request->all(), [
            'q' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        // get query
        $model = $this->custom_table->getValueModel()->query();

        // filtered query
        $params = explode(',', $request->get('q'));
        $orderby_list = [];
        foreach ($params as $param) {
            $values = preg_split("/\s+/", trim($param));
            $column_name = $values[0];
            if (count($values) < 3 || !preg_match('/^eq|ne|gt|gte|lt|lte$/i', $values[1])) {
                return abortJson(400, ErrorCode::INVALID_PARAMS());
            }
            if (SystemColumn::isSqlValid($column_name)) {
            } else {
                $column = CustomColumn::getEloquent($column_name, $this->custom_table);
                if (!isset($column)) {
                    return abortJson(400, ErrorCode::INVALID_PARAMS());
                } elseif (!$column->index_enabled) {
                    return abortJson(400, ErrorCode::NOT_INDEX_ENABLED());
                }
                $column_name = $column->getIndexColumnName();
            }
            $operator = '=';
            switch ($values[1]) {
                case 'gt':
                    $operator = '>';
                    break;
                case 'gte':
                    $operator = '>=';
                    break;
                case 'lt':
                    $operator = '<';
                    break;
                case 'lte':
                    $operator = '<=';
                    break;
                case 'ne':
                    $operator = '<>';
                    break;
            }
            $model->where($column_name, $operator, $values[2]);
        }
    
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $paginator = $model->paginate($count);

        return $this->modifyAfterGetValue($request, $paginator, [
            'appends' => [
                'count' => $count,
            ]
        ]);
    }
    
    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function dataFind(Request $request, $tableKey, $id)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        $model = getModelName($this->custom_table->table_name)::find($id);
        // not contains data, return empty data.
        if (!isset($model)) {
            $code = $this->custom_table->getNoDataErrorCode($id);
            if ($code == ErrorCode::PERMISSION_DENY) {
                return abortJson(403, $code);
            } else {
                // nodata
                return abortJson(400, $code);
            }
        }

        if (($code = $model->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        return $this->modifyAfterGetValue($request, $model);
    }

    /**
     * create data
     * @return mixed
     */
    public function dataCreate(Request $request)
    {
        if (($code = $this->custom_table->enableCreate()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        return $this->saveData($request);
    }

    /**
     * update data
     * @return mixed
     */
    public function dataUpdate(Request $request, $tableKey, $id)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        if (($custom_value = $this->getCustomValue($this->custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        if (($code = $custom_value->enableEdit()) !== true) {
            return abortJson(403, $code);
        }

        return $this->saveData($request, $custom_value);
    }

    /**
     * delete data
     * @return mixed
     */
    public function dataDelete(Request $request, $tableKey, $id)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        $ids = stringToArray($id);
        $max_delete_count = config('exment.api_max_delete_count', 100);
        if (count($ids) > $max_delete_count) {
            return abortJson(400, exmtrans('api.errors.over_deletelength', $max_delete_count), ErrorCode::OVER_LENGTH());
        }

        $custom_values = [];
        foreach ((array)$ids as $i) {
            if (($custom_value = $this->getCustomValue($this->custom_table, $i)) instanceof Response) {
                return $custom_value;
            }
            if (($code = $custom_value->enableDelete()) !== true) {
                return abortJson(403, $code());
            }
    
            $custom_values[] = $custom_value;
        }
        
        \DB::transaction(function () use ($custom_values) {
            foreach ($custom_values as $custom_value) {
                $custom_value->delete();
            }
        });

        if (boolval($request->input('webresponse'))) {
            return response([
                'result'  => true,
                'message' => trans('admin.delete_succeeded'),
            ], 200);
        }
        return response(null, 204);
    }

    /**
     * Get Attachment files
     *
     * @param Request $request
     * @return void
     */
    public function getDocuments(Request $request, $tableKey, $id)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        if (($custom_value = $this->getCustomValue($this->custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        if (($code = $custom_value->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }
        
        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $documents = $custom_value->getDocuments([
            'count' => $count,
            'paginate' => true,
        ]);

        $documents->appends([
            'count' => $count
        ]);

        $documents->getCollection()->transform(function ($document) {
            return $this->getDocumentArray($document);
        });
        
        return $documents;
    }

    /**
     * create Attachment files
     *
     * @param Request $request
     * @return void
     */
    public function createDocument(Request $request, $tableKey, $id)
    {
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        if (($custom_value = $this->getCustomValue($this->custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        if (($code = $custom_value->enableEdit()) !== true) {
            return abortJson(403, $code);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'base64' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        $file_data = base64_decode($request->get('base64'));
        $filename = $request->get('name');

        $file = File::storeAs($file_data, $this->custom_table->table_name, $filename)
            ->saveCustomValue($custom_value->id, null, $this->custom_table);
        // save document model
        $document_model = $file->saveDocumentModel($custom_value, $filename);
        
        return response($this->getDocumentArray($document_model), 201);
    }

    /**
     * get selected id's children values
     */
    public function relatedLinkage(Request $request)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        // get children table id
        $child_table_id = $request->get('child_table_id');
        $child_table = CustomTable::getEloquent($child_table_id);
        // get selected custom_value id(q)
        $q = $request->get('q');

        // get children items
        $options = [
            'paginate' => false,
            'maxCount' => null,
            'getLabel' => true,
        ];
        $datalist = $this->custom_table->searchRelationValue($request->get('search_type'), $q, $child_table, $options);
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

    /**
     * get table columns data
     */
    public function columnData(Request $request, $tableKey, $column_name)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        $query = $request->get('query');
        $custom_column = CustomColumn::getEloquent($column_name, $this->custom_table->table_name);

        $list = [];

        if ($custom_column->index_enabled) {
            $column_name = $custom_column->getIndexColumnName();
            $list = $this->custom_table->searchValue($query, [
                'searchColumns' => collect([$column_name]),
            ])->pluck($column_name)->unique()->toArray();
        }
        return json_encode($list);
    }

    protected function saveData($request, $custom_value = null)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required_without:data',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        $is_single = false;
        $rootValues = $this->getRootValuesFromPost($request, $is_single);

        $max_create_count = config('exment.api_max_create_count', 100);
        if (count($rootValues) > $max_create_count) {
            return abortJson(400, exmtrans('api.errors.over_createlength', $max_create_count), ErrorCode::OVER_LENGTH());
        }

        $findResult = $this->convertFindKeys($rootValues, $request);
        if ($findResult !== true) {
            return abortJson(400, [
                'errors' => $findResult
            ], ErrorCode::VALIDATION_ERROR());
        }

        $validates = [];
        // saved files
        $files = [];
        foreach ($rootValues as $index => $rootValue) {
            $validateValue = $rootValue;
            $value = array_get($rootValue, 'value');

            // Convert base64 encode file
            list($value, $fileColumns) = $this->custom_table->convertFileData($value);
            $files[$index] = $fileColumns;

            if (!isset($custom_value)) {
                $value = $this->custom_table->setDefaultValue($value);
            } else {
                $value = $custom_value->mergeValue($value);
            }
            $validateValue['value'] = $value;

            // // get fields for validation
            $validator = $this->custom_table->validateValue($validateValue, $custom_value, [
                'systemColumn' => true,
                'column_name_prefix' => 'value.',
                'appendKeyName' => false,
                'asApi' => true,
            ]);

            if ($validator->fails()) {
                if ($is_single) {
                    $validates[] = $this->getErrorMessages($validator);
                } else {
                    $validates[] = [
                        'line_no' => $index,
                        'error' => $this->getErrorMessages($validator)
                    ];
                }
            }
        }

        if (count($validates) > 0) {
            return abortJson(400, [
                'errors' => $validates
            ], ErrorCode::VALIDATION_ERROR());
        }

        $response = [];
        foreach ($rootValues as $index => &$rootValue) {
            // set default value if new
            if (!isset($custom_value)) {
                $model = $this->custom_table->getValueModel();
            }
            // now update is only one record, so it's OK.
            else {
                $model = $custom_value;
            }

            // Save file data
            $this->saveFile($this->custom_table, $files[$index], $rootValue['value']);

            $model->setValue($rootValue['value']);

            if (array_key_exists('parent_id', $rootValue)) {
                $model->parent_id = $rootValue['parent_id'];
            }
            if (array_key_exists('parent_type', $rootValue)) {
                $model->parent_type = $rootValue['parent_type'];
            }

            $model->saveOrFail();

            $this->customValueFile($this->custom_table, $files[$index], $model);

            $response[] = $this->modifyAfterGetValue($request, $model);
        }

        if ($is_single && count($response) > 0) {
            return $response[0];
        } else {
            return $response;
        }
    }

    /**
     * Get root values from post data.
     * root value is ex. {"parent_type": "sales", "parent_id": 1, "value": {"sale_code": "XYZ", "sale_name": "Sample"}}
     *
     * @return void
     */
    protected function getRootValuesFromPost(Request $request, &$is_single)
    {
        $rootValues = [];
        $systemKeys = ['parent_id', 'parent_type', 'updated_at'];

        if ($request->has('value')) {
            $values = $request->get('value');
        } else {
            $values = $request->get('data');
        }

        if (!is_vector($values)) {
            $rootValue = ['value' => $values];
            
            foreach ($systemKeys as $systemKey) {
                if ($request->has($systemKey)) {
                    $rootValue[$systemKey] = $request->get($systemKey);
                }
                if (array_key_exists($systemKey, $rootValue['value'])) {
                    $rootValue[$systemKey] = $rootValue['value'][$systemKey];
                    unset($rootValue['value'][$systemKey]);
                }
            }

            $rootValues[] = $rootValue;
            $is_single = true;
        } else {
            $rootValues = collect($values)->map(function ($value) use ($systemKeys) {
                if (array_key_exists('value', $value)) {
                    $rootValue = $value;
                } else {
                    $rootValue = ['value' => $value];
                    
                    foreach ($systemKeys as $systemKey) {
                        if (array_key_exists($systemKey, $rootValue['value'])) {
                            $rootValue[$systemKey] = $rootValue['value'][$systemKey];
                            unset($rootValue['value'][$systemKey]);
                        }
                    }
                }

                return $rootValue;
            })->toArray();
        }

        return $rootValues;
    }

    protected function convertFindKeys(&$rootValues, $request)
    {
        if (is_null($findKeys = $request->get('findKeys'))) {
            return true;
        }
        
        $errors = [];

        $processOptions = [
            'onlyValue' => true,
            'errorCallback' => function ($message, $key) use (&$errors) {
                $errors[$key] = $message;
            },
            'setting' => collect($findKeys)->map(function ($value, $key) {
                return [
                    'column_name' => $key,
                    'target_column_name' => $value
                ];
            })->toArray()];

        foreach ($rootValues as &$rootValue) {
            $rootValue['value'] = DataImportExportService::processCustomValue($this->custom_columns, array_get($rootValue, 'value'), $processOptions);
        }

        return count($errors) > 0 ? $errors : true;
    }

    /**
     * get calendar data
     * @return mixed
     */
    public function calendarList(Request $request)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        // filtered query
        if ($request->has('dashboard')) {
            $is_dashboard = boolval($request->get('dashboard'));
        } else {
            $is_dashboard = false;
        }
        $custom_view = CustomView::getDefault($this->custom_table, true, $is_dashboard);
        $start = $request->get('start');
        $end = $request->get('end');
        if (!isset($start) || !isset($end)) {
            return [];
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        $table_name = $this->custom_table->table_name;
        // get paginate
        $model = $this->custom_table->getValueModel()->query();
        // filter model
        \Exment::user()->filterModel($model, $custom_view);

        $tasks = [];
        foreach ($custom_view->custom_view_columns as $custom_view_column) {
            if ($custom_view_column->view_column_type == ConditionType::COLUMN) {
                $target_start_column = $custom_view_column->custom_column->getIndexColumnName();
            } else {
                $target_start_column = SystemColumn::getOption(['id' => $custom_view_column->view_column_target_id])['name'];
            }

            if (isset($custom_view_column->view_column_end_date)) {
                $end_date_target = $custom_view_column->getOption('end_date_target');
                if ($custom_view_column->view_column_end_date_type == ConditionType::COLUMN) {
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

    /**
     * Save fileinfo after custom_value save
     *
     * @param CustomTable $custom_table
     * @param array $files
     * @param array $value
     * @return void
     */
    protected function saveFile($custom_table, &$files, &$value)
    {
        foreach ($files as $column_name => &$fileInfo) {
            // save filename
            $file = File::storeAs($fileInfo['data'], $custom_table->table_name, $fileInfo['name']);

            // convert value array
            $value[$column_name] = path_join($file->local_dirname, $file->local_filename);

            $fileInfo['model'] = $file;
        }
    }

    /**
     * Append custom value info after custom_value save
     *
     * @param CustomTable $custom_table
     * @param array $files
     * @param CustomValue $custom_value
     * @return void
     */
    protected function customValueFile($custom_table, $files, $custom_value)
    {
        foreach ($files as $column_name => $fileInfo) {
            $fileInfo['model']->saveCustomValue($custom_value->id, $fileInfo['custom_column'], $custom_table);
        }
    }

    /**
     * Check whether use label
     *
     * @return bool if use, return true
     */
    protected function isAppendLabel(Request $request)
    {
        if ($request->has('label')) {
            return boolval($request->get('label', false));
        }

        if (boolval(config('exment.api_append_label', false))) {
            return true;
        }

        return false;
    }

    /**
     * Modify logic for getting value
     *
     * @return void
     */
    protected function modifyAfterGetValue(Request $request, $target, $options = [])
    {
        // for paginate logic
        if ($target instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $options = array_merge(
                [
                    'appends' => [],
                ],
                $options
            );

            $appends = array_merge(
                $request->all([
                    'label',
                    'count',
                    'page',
                    'valuetype',
                    'q',
                    'id',
                    'target_view_id',
                ]),
                $options['appends']
            );

            // execute makehidden
            $results = $target->makeHidden($this->custom_table->getMakeHiddenArray());

            $results->map(function ($result) use ($request) {
                $this->modifyCustomValue($request, $result);
            });

            $target->value = $results;

            // set appends
            if (!is_nullorempty($appends)) {
                $target->appends($appends);
            }

            return $target;
        }
        // as single model
        elseif ($target instanceof CustomValue) {
            $target = $target->makeHidden($this->custom_table->getMakeHiddenArray());
            return $this->modifyCustomValue($request, $target);
        }
    }

    protected function modifyCustomValue(Request $request, $custom_value)
    {
        // append label
        if ($this->isAppendLabel($request)) {
            $custom_value->append('label');
        }

        // convert to custom values
        $valuetype = $request->get('valuetype');
        if ($request->has('valuetype') && ValueType::filterApiValueType($valuetype)) {
            $custom_value->setValue($custom_value->getValues(ValueType::getEnum($valuetype), ['asApi' => true]));
        }

        if ($request->has('dot') && boolval($request->get('dot'))) {
            $custom_value = array_dot($custom_value->toArray());
        }

        return $custom_value;
    }

    protected function getDocumentArray($document)
    {
        return [
            'name' => $document->label,
            'url' => $document->url,
            'api_url' => $document->api_url,
            'created_at' => $document->created_at->__toString(),
            'created_user_id' => $document->created_user_id,
        ];
    }
}
