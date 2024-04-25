<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomViewColumn;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Carbon\Carbon;
use Validator;

/**
 * Api about target table's data
 */
class ApiDataController extends AdminControllerTableBase
{
    use ApiDataTrait;

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

        return $this->{$method}(...array_values($parameters));
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

        if (($orderby_list = $this->getOrderBy($request)) instanceof Response) {
            return $orderby_list;
        }

        // get paginate
        $model = $this->custom_table->getValueQuery();

        // filterd by id
        if ($request->has('id')) {
            $ids = explode(',', $request->get('id'));
            $model->whereIn('id', $ids);
        }

        // set query
        $this->setQueryInfo($model);

        // set order by
        $this->setOrderByQuery($model, $orderby_list);

        $paginator = $model->paginate($count);

        return $this->modifyAfterGetValue($request, $paginator, [
            'appends' => [
                'count' => $count,
                'orderby' => $request->get('orderby'),
            ]
        ]);
    }

    /**
     * find match data for select ajax
     * @param Request $request
     * @return mixed
     */
    public function dataSelect(Request $request)
    {
        return $this->_dataSelect($request);
    }

    /**
     * find match data by query
     * use form select ajax
     * * (required) q : search word
     * * (optional) expand.target_view_id : filtering data using view.
     * * (optional) expand.linkage_column_id : if called column sets linkage from other column, set this linkage column id.
     * * (optional) expand.linkage_value_id : if called column sets linkage from other column, set linkage selected linkage_value_id.
     *
     * if has linkage_column_id and linkage_value_id, filtering using linkage
     *
     * @param Request $request
     * @return mixed
     */
    public function dataQuery(Request $request)
    {
        return $this->executeQuery($request);
    }

    /**
     * find match data by column query
     * use form select ajax
     * @param Request $request
     * @param string $tableKey
     * @return mixed
     */
    public function dataQueryColumn(Request $request, $tableKey)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, $code);
        }

        // get model filtered using role
        $model = getModelName($this->custom_table)::query();

        $validator = Validator::make($request->all(), [
            'q' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        // get query
        $model = $this->custom_table->getValueQuery();

        // filtered query
        $params = explode(',', $request->get('q'));

        // set query
        if (($res = $this->setParamsQueryColumn($request, $model, $params)) instanceof Response) {
            return $res;
        }

        // set order by
        if (($orderby_list = $this->getOrderBy($request)) instanceof Response) {
            return $orderby_list;
        }
        $this->setOrderByQuery($model, $orderby_list);

        $paginator = $model->paginate($count);

        return $this->modifyAfterGetValue($request, $paginator, [
            'appends' => [
                'count' => $count,
                'orderby' => $request->get('orderby'),
            ]
        ]);
    }

    /**
     * Set query parameter for query column search
     *
     * @param mixed $query
     * @param array $params
     * @return Response|boolean
     */
    protected function setParamsQueryColumn(Request $request, $query, $params)
    {
        if (empty($params)) {
            return true;
        }

        $paramInfos = [];
        foreach ($params as $param) {
            $values = preg_split("/\s+/", trim($param), 3);
            $column_name = $values[0];

            if (count($values) < 3 || !preg_match('/^eq|ne|gt|gte|lt|lte|like$/i', $values[1])) {
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
                case 'like':
                    $operator = 'LIKE';
                    break;
            }
            $paramInfos[] = [$column_name, $operator, $values[2]];
        }

        $query->where(function ($query) use ($request, $paramInfos) {
            $whereFunc = boolval($request->get('or', false)) ? 'orWhere' : 'where';
            foreach ($paramInfos as $paramInfo) {
                $query->{$whereFunc}($paramInfo[0], $paramInfo[1], $paramInfo[2]);
            }
        });

        return true;
    }

    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function dataFind(Request $request, $tableKey, $id)
    {
        return $this->_dataFind($request, $id);
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

        $forceDelete = boolval($request->get('force'));

        $custom_values = [];
        $validates = [];
        foreach ((array)$ids as $index => $i) {
            if (($custom_value = $this->getCustomValue($this->custom_table, $i, $forceDelete)) instanceof Response) {
                return $custom_value;
            }
            if (($code = $custom_value->enableDelete()) !== true) {
                return abortJson(403, $code());
            }
            if ($res = $this->custom_table->validateValueDestroy($i)) {
                $message = array_get($res, 'message')?? exmtrans('error.delete_failed');
                if (count($ids) == 1) {
                    $validates[] = $message;
                } else {
                    $validates[] = [
                        'line_no' => $index,
                        'error' => $message
                    ];
                }
            }
            $custom_values[] = $custom_value;
        }

        if (count($validates) > 0) {
            return abortJson(400, [
                'errors' => $validates
            ], ErrorCode::VALIDATION_ERROR());
        }

        \ExmentDB::transaction(function () use ($custom_values, $forceDelete) {
            foreach ($custom_values as $custom_value) {
                if ($forceDelete) {
                    $custom_value->forceDelete();
                } else {
                    $custom_value->delete();
                }
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


    // viewdata ----------------------------------------------------

    /**
     * list all data
     * @return mixed
     */
    public function viewDataList(Request $request, $tableKey, $viewid)
    {
        $init = $this->viewDataInit($request, $viewid, true);
        if ($init instanceof Response) {
            return $init;
        }
        list($custom_view, $valuetype, $count) = $init;

        $paginator = $custom_view->getDataPaginate([
            'maxCount' => $count,
            'executeSearch' => false,
            'isApi' => true,
        ]);
        $paginator = $this->modifyAfterGetValue($request, $paginator, [
            'appends' => [
                'count' => $count,
            ],
            'makeHidden' => false,
        ]);

        list($results, $apiDefinitions) = $this->viewDataAfter($custom_view, $valuetype, $paginator->items());

        $paginator->setCollection(collect($results));

        // convert to array
        $array = $paginator->toArray();

        // set difinition
        $array['column_definitions'] = $apiDefinitions;

        return $array;
    }

    /**
     * list view data
     * @return mixed
     */
    public function viewDataFind(Request $request, $tableKey, $viewid, $id)
    {
        $init = $this->viewDataInit($request, $viewid, false);
        if ($init instanceof Response) {
            return $init;
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

        list($custom_view, $valuetype, $count) = $init;

        list($results, $apiDefinitions) = $this->viewDataAfter($custom_view, $valuetype, collect([$model]));

        // convert to array
        $array = ['value' => $results->first(), 'column_definitions' => $apiDefinitions];

        return $array;
    }

    /**
     * Initialize view data
     *
     * @param Request $request
     * @param int|string $viewid
     * @param boolean $isList
     * @return Response|array if error, return response. or not, return list($custom_view, $valuetype, $count)
     */
    protected function viewDataInit(Request $request, $viewid, bool $isList)
    {
        // get view
        $custom_view = CustomView::getEloquent($viewid);
        //not found
        if (!isset($custom_view)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        // not match table and view
        if (!isMatchString($custom_view->custom_table_id, $this->custom_table->id)) {
            return abortJson(400, ErrorCode::WRONG_VIEW_AND_TABLE());
        }

        // validate view type
        $acceptView = $isList ? ViewKindType::acceptApiList($custom_view->view_kind_type) : ViewKindType::acceptApiData($custom_view->view_kind_type);
        if (!$acceptView) {
            return abortJson(400, ErrorCode::UNSUPPORTED_VIEW_KIND_TYPE());
        }

        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        // convert to custom values
        $valuetype = ValueType::getEnum($request->get('valuetype')) ?? ValueType::PURE_VALUE;
        if (!ValueType::filterApiValueType($valuetype)) {
            $valuetype = ValueType::PURE_VALUE;
        }

        return [$custom_view, $valuetype, $count];
    }

    protected function viewDataAfter($custom_view, $valuetype, $target)
    {
        list($headers, $bodies, $columnStyles, $columnClasses, $columnItems) =
            $custom_view->convertDataTable($target, ['appendLink' => false, 'valueType' => $valuetype]);

        // get api name and definitions
        $apiNames = collect($columnItems)->map(function ($columnItem) {
            return $columnItem->apiName();
        })->toArray();
        $apiDefinitions = collect($columnItems)->mapWithKeys(function ($columnItem) {
            return [$columnItem->apiName() => $columnItem->apiDefinitions()];
        })->toArray();

        $results = collect($bodies)->map(function ($body, $index) use ($apiNames) {
            return array_combine($apiNames, $body);
        });

        return [$results, $apiDefinitions];
    }


    // Document ----------------------------------------------------
    /**
     * Get Attachment files
     *
     * @param Request $request
     * @param $tableKey
     * @param $id
     * @return \Exceedone\Exment\Model\CustomValue|\Illuminate\Config\Repository|\Illuminate\Contracts\Foundation\Application|\Illuminate\Database\Eloquent\Collection|\Illuminate\Pagination\AbstractPaginator|mixed|Response|null
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
     * @param $tableKey
     * @param $id
     * @return \Exceedone\Exment\Model\CustomValue|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|Response
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

        $file = File::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $file_data, $this->custom_table->table_name, $filename)
            ->saveCustomValue($custom_value->id, null, $this->custom_table);
        // save document model
        $document_model = $file->saveDocumentModel($custom_value, $filename);

        return response($this->getDocumentArray($document_model), 201);
    }

    /**
     * get selected id's children values
     * *parent_select_table_id(required) : The select_table of the parent column(Changed by user) that executed Linkage. .
     * *child_select_table_id(required) : The select_table of the child column(Linkage target column) that executed Linkage.
     * *child_column_id(required) : Called Linkage target column.
     * *search_type(required) : 1:n, n:n or select_table.
     * *q(required) : id that user selected.
     */
    public function relatedLinkage(Request $request)
    {
        return $this->_relatedLinkage($request);
    }

    /**
     * get table columns data. seletcting column, and search.
     *
     * @param Request $request
     * @param string $tableKey
     * @param string $column_name
     * @return Response
     */
    public function columnData(Request $request, $tableKey, $column_name)
    {
        return $this->_columnData($request, $column_name);
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
        foreach ($rootValues as $index => &$rootValue) {
            $validateValue = $rootValue;
            $value = array_get($rootValue, 'value');

            // set default value if create
            if (!isset($custom_value)) {
                $value = $this->custom_table->setDefaultValue($value);
                $rootValue['value'] = $value;
            }

            // Convert base64 encode file
            list($value, $fileColumns) = $this->convertFileData($value);
            $files[$index] = $fileColumns;

            // merge value for validate
            if (isset($custom_value)) {
                $value = $custom_value->mergeValue($value);
            }
            $validateValue['value'] = $value;

            // // get fields for validation
            $validator = $this->custom_table->validateValue($validateValue, $custom_value, [
                'systemColumn' => true,
                'column_name_prefix' => 'value.',
                'appendKeyName' => false,
                'asApi' => true,
                'calledType' => ValidateCalledType::API,
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
            $this->saveFile($this->custom_table, $files[$index], $rootValue['value'], $model->value);

            $model->setValue($rootValue['value']);

            if (array_key_exists('parent_id', $rootValue)) {
                $model->parent_id = $rootValue['parent_id'];
            }
            if (array_key_exists('parent_type', $rootValue)) {
                $model->parent_type = $rootValue['parent_type'];
            }

            $model->saveOrFail();

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
     * @return array
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
        $model = $this->custom_table->getValueQuery();
        // filter model
        $custom_view->filterSortModel($model);

        $tasks = [];
        /** @var CustomViewColumn $custom_view_column */
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
            $data = $query->take(config('exment.calendar_max_size_count', 300))->get();

            foreach ($data as $row) {
                $task = [
                    'title' => $row->getLabel(),
                    'url' => admin_url('data', [$table_name, $row->id]),
                    'color' => $custom_view_column->view_column_color,
                    'textColor' => $custom_view_column->view_column_font_color,

                    'id' => $row->id,
                    'value' => $row->value,
                ];
                if (boolval(config('exment.calendar_data_get_value'))) {
                    $task['id'] = $row->id;
                    $task['value'] = $row->value;
                }

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
     * @param $model
     * @param $start
     * @param $end
     * @param $target_start_column
     * @param $target_end_column
     * @return mixed
     * @throws \Exception
     */
    protected function getCalendarQuery($model, $start, $end, $target_start_column, $target_end_column)
    {
        $db_table_name = getDBTableName($this->custom_table);
        $query = clone $model;
        // filter end data
        if (isset($target_end_column)) {
            // filter enddate.
            // ex. 4/1 - endDate - 4/30
            $endQuery = (clone $query);
            $endQuery = $endQuery->where((function ($query) use ($db_table_name, $target_end_column, $start, $end) {
                $query->where("$db_table_name.$target_end_column", '>=', $start->toDateString())
                ->where("$db_table_name.$target_end_column", '<', $end->toDateString());
            }))->select("$db_table_name.id");

            // filter start and enddate.
            // ex. startDate - 4/1 - 4/30 - endDate
            $startEndQuery = (clone $query);
            $startEndQuery = $startEndQuery->where((function ($query) use ($db_table_name, $target_start_column, $target_end_column, $start, $end) {
                $query->where("$db_table_name.$target_start_column", '<=', $start->toDateString())
                ->where("$db_table_name.$target_end_column", '>=', $end->toDateString());
            }))->select("$db_table_name.id");
        }

        if ($query instanceof \Illuminate\Database\Eloquent\Model) {
            $query = $query->getQuery();
        }

        // filter startDate
        // ex. 4/1 - startDate - 4/30
        $query->where(function ($query) use ($db_table_name, $target_start_column, $start, $end) {
            $query->where("$db_table_name.$target_start_column", '>=', $start->toDateString())
            ->where("$db_table_name.$target_start_column", '<', $end->toDateString());
        })->select("$db_table_name.id");

        // union queries
        if (isset($endQuery)) {
            $query->union($endQuery);
        }
        if (isset($startEndQuery)) {
            $query->union($startEndQuery);
        }

        // get target ids
        $ids = \DB::query()->fromSub($query, 'sub')->pluck('id');

        $result = clone $model;
        // return as eloquent
        return $result->whereIn("$db_table_name.id", $ids);
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
     * Convert base64 encode file
     *
     * @param array $value input value
     * @return array Value after converting base64 encode file, and files value
     */
    protected function convertFileData($value)
    {
        // get file columns
        $file_columns = $this->custom_table->custom_columns_cache->filter(function ($column) {
            return ColumnType::isAttachment($column->column_type);
        });

        $files = [];

        foreach ($file_columns as $file_column) {
            // if not key in value, set default value
            if (!array_has($value, $file_column->column_name)) {
                continue;
            }
            $file_value = $value[$file_column->column_name];
            // convert file name for validation
            list($fileNames, $fileValues) = $this->getFileValue($file_column, $file_value);
            $value[$file_column->column_name] = $fileNames;

            // append file data
            $files[$file_column->column_name] = $fileValues;
        }

        return [$value, $files];
    }


    protected function getFileValue(CustomColumn $file_column, $file_value): ?array
    {
        // whether is_vector, set as array
        if (!is_vector($file_value)) {
            $file_value = [$file_value];
        }

        $names = [];
        $result = [];
        foreach ($file_value as $file_v) {
            if (!array_has($file_v, 'name') && !array_has($file_v, 'base64')) {
                continue;
            }

            $file_name = $file_v['name'];
            $file_data = $file_v['base64'];
            $file_data = base64_decode($file_data);

            $names[] = $file_name;
            $result[] = [
                'name' => $file_name,
                'data' => $file_data,
                'custom_column' => $file_column,
            ];
        }

        if (!$file_column->isMultipleEnabled()) {
            return count($result) > 0 ? [$names[0], $result[0]] : null;
        }

        return [$names, $result];
    }

    /**
     * Save fileinfo after custom_value save
     *
     * @param CustomTable $custom_table
     * @param array $files
     * @param array $value
     * @param array $originalValue
     * @return void
     */
    protected function saveFile($custom_table, $files, &$value, $originalValue)
    {
        foreach ($files as $column_name => $fileInfos) {
            $result = [];

            if (!is_vector($fileInfos)) {
                $fileInfos = [$fileInfos];
            }

            foreach ($fileInfos as $fileInfo) {
                $custom_column = array_get($fileInfo, 'custom_column');
                // save filename
                $file = File::storeAs(FileType::CUSTOM_VALUE_COLUMN, array_get($fileInfo, 'data'), $custom_table->table_name, array_get($fileInfo, 'name'));

                // save file info
                \Exment::setFileRequestSession(
                    $file,
                    $custom_column->column_name,
                    $custom_table,
                    !$custom_column->isMultipleEnabled()
                );
                $result[] = path_join($file->local_dirname, $file->local_filename);
            }

            // set custom value
            if (!isset($custom_column) || !$custom_column->isMultipleEnabled()) {
                $value[$column_name] = count($result) > 0 ? $result[0] : null;
            } else {
                // If multiple, merge original array
                $value[$column_name] = array_merge(array_get($originalValue, $column_name) ?? [], $result);
            }
        }
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

    /**
     * Get order by array from request
     *
     * @param Request $request
     * @return array|Response offset 0 : target column name, 1 : 'asc' or 'desc'
     */
    protected function getOrderBy(Request $request)
    {
        if (!$request->has('orderby')) {
            return [];
        }

        $orderby_list = [];
        $orderby = $request->get('orderby');
        $params = explode(',', $orderby);

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
            $orderby_list[] = [$column_name, count($values) > 1 ? $values[1] : 'asc'];
        }

        return $orderby_list;
    }

    /**
     * Set order by query
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param array $orderby_list
     * @return void
     */
    protected function setOrderByQuery($query, $orderby_list)
    {
        if (empty($orderby_list)) {
            return;
        }

        // set order by
        $hasId = false;
        foreach ($orderby_list as $item) {
            if ($item[0] == 'id') {
                $hasId = true;
            }
            $query->orderBy($item[0], $item[1]);
        }

        if (!$hasId) {
            $query->orderBy('id');
        }
    }
}
