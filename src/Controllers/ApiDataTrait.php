<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SearchType;
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
 * Api about target table's data.
 * *THIS TRAIT defines called as api, webapi, AND publicformapi. And contains table key and id*
 */
trait ApiDataTrait
{
    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    protected function _dataFind(Request $request, $id)
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
     * get table columns data. seletcting column, and search.
     *
     * @param Request $request
     * @param string $tableKey
     * @param string $column_name
     * @return Response
     */
    protected function _columnData(Request $request, $column_name)
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

    

    /**
     * Modify logic for getting value
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator|CustomValue
     */
    protected function modifyAfterGetValue(Request $request, $target, $options = [])
    {
        $options = array_merge(
            [
                'makeHidden' => true,
            ],
            $options
        );

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
            
            if (boolval($options['makeHidden'])) {
                // execute makehidden
                $results = $target->makeHidden($this->custom_table->getMakeHiddenArray());

                // if need to convert to custom values, call setSelectTableValues, for performance
                $valuetype = $request->get('valuetype', ValueType::PURE_VALUE);
                if (ValueType::isRegetApiCustomValue($valuetype)) {
                    $this->custom_table->setSelectTableValues($results);
                }
                
                $results->map(function ($result) use ($request) {
                    $this->modifyCustomValue($request, $result);
                });
                $target->value = $results;
            }

            // set appends
            if (!is_nullorempty($appends)) {
                $target->appends($appends);
            }

            return $target;
        }
        // as single model
        elseif ($target instanceof CustomValue) {
            if (boolval($options['makeHidden'])) {
                $target = $target->makeHidden($this->custom_table->getMakeHiddenArray());
                return $this->modifyCustomValue($request, $target);
            }

            return $target;
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
        if ($request->has('valuetype') && ValueType::isRegetApiCustomValue($valuetype)) {
            $custom_value->setValueDirectly($custom_value->getValues(ValueType::getEnum($valuetype), ['asApi' => true]));
        }

        if ($request->has('dot') && boolval($request->get('dot'))) {
            $custom_value = array_dot($custom_value->toArray());
        }

        return $custom_value;
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
}
