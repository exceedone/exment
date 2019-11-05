<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ColumnType;
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
                if (SystemColumn::isValid($column_name)) {
                } else {
                    $column = CustomColumn::getEloquent($column_name, $this->custom_table);
                    if (!isset($column) && $column->index_enabled) {
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

        // set order by
        if (isset($orderby_list)) {
            foreach ($orderby_list as $item) {
                $model->orderBy($item[0], $item[1]);
            }
        }
        $paginator = $model->paginate($count);

        // execute makehidden
        $value = $paginator->makeHidden($this->custom_table->getMakeHiddenArray());
        $paginator->value = $value;

        // set appends
        $paginator->appends([
            'count' => $count,
            'orderBy' => $orderby,
        ]);

        return $paginator;
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

        $paginator = $this->custom_table->searchValue($q, [
            'paginate' => true,
            'makeHidden' => true,
            'target_view' => $custom_view,
            'maxCount' => $count,
        ]);

        $paginator->appends([
            'q' => $q,
            'count' => $count,
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
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        $model = getModelName($this->custom_table->table_name)::find($id);
        // not contains data, return empty data.
        if (!isset($model)) {
            return [];
        }

        if (($code = $model->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
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

        $custom_value = getModelName($this->custom_table)::find($id);
        if (!isset($custom_value)) {
            abort(400);
        }

        if (($code = $model->enableEdit()) !== true) {
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

        $custom_value = getModelName($this->custom_table)::find($id);
        if (!isset($custom_value)) {
            abort(400);
        }

        if (($code = $model->enableDelete()) !== true) {
            return abortJson(403, $code());
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
     * get table columns data
     */
    public function columnData(Request $request, $tableKey, $column_name)
    {
        if (!$this->custom_table->enableAccess()) {
            return abortJson(403, trans('admin.deny'));
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
        $is_single = false;

        $validator = Validator::make($request->all(), [
            'value' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        $values = $request->get('value');

        if (!is_vector($values)) {
            $values = [$values];
            $is_single = true;
        }

        $max_create_count = config('exment.api_max_create_count', 100);
        if (count($values) > $max_create_count) {
            return abortJson(400, exmtrans('api.errors.over_createlength', $max_create_count));
        }

        $findResult = $this->convertFindKeys($values, $request);
        if ($findResult !== true) {
            return abortJson(400, [
                'errors' => $findResult
            ], ErrorCode::VALIDATION_ERROR());
        }

        $validates = [];
        foreach ($values as $index => $value) {
            if (!isset($custom_value)) {
                $value = $this->custom_table->setDefaultValue($value);
                // // get fields for validation
                $validator = $this->custom_table->validateValue($value);
            } else {
                $value = $custom_value->mergeValue($value);
                // // get fields for validation
                $validator = $this->custom_table->validateValue($value, false, $custom_value->id);
            }

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
        foreach ($values as &$value) {
            // set default value if new
            if (!isset($custom_value)) {
                $model = $this->custom_table->getValueModel();
            } else {
                $model = $custom_value;
            }

            $model->setValue($value);
            $model->saveOrFail();

            $response[] = getModelName($this->custom_table)::find($model->id)->makeHidden($this->custom_table->getMakeHiddenArray());
        }

        if ($is_single && count($response) > 0) {
            return $response[0];
        } else {
            return $response;
        }
    }

    protected function convertFindKeys(&$values, $request)
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

        foreach ($values as &$value) {
            $value = DataImportExportService::processCustomValue($this->custom_columns, $value, $processOptions);
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
}
