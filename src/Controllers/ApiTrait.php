<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;

/**
 * Api about target table
 */
trait ApiTrait
{
    /**
     * Get error message from validator
     *
     * @param \Exceedone\Exment\Validator\ExmentCustomValidator $validator
     * @return array error messages
     */
    protected function getErrorMessages($validator)
    {
        $errors = [];
        foreach ($validator->getMessages() as $key => $message) {
            // remove "value." key
            $key = str_replace("value.", "", $key);
            if (is_array($message)) {
                $errors[$key] = $message[0];
            } else {
                $errors[$key] = $message;
            }
        }
        return $errors;
    }

    /**
     * response column data
     *
     * @param Request $request
     * @param CustomColumn|null $custom_column
     * @return CustomColumn|\Symfony\Component\HttpFoundation\Response
     */
    protected function responseColumn(Request $request, ?CustomColumn $custom_column)
    {
        if (!isset($custom_column)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        if (!$custom_column->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        return $custom_column;
    }

    /**
     * Get count parameter for list count
     *
     * @param Request $request
     * @return \Illuminate\Config\Repository|\Illuminate\Contracts\Foundation\Application|mixed|\Symfony\Component\HttpFoundation\Response|null
     */
    protected function getCount(Request $request)
    {
        // get and check query parameter

        if (!$request->has('count')) {
            return config('exment.api_default_data_count', 20);
        }

        $count = $request->get('count');
        $maxcount = config('exment.api_max_data_count', 100);
        if (!preg_match('/^[0-9]+$/', $count) || intval($count) < 1 || intval($count) > $maxcount) {
            return abortJson(400, exmtrans('api.errors.over_maxcount', $maxcount), ErrorCode::INVALID_PARAMS());
        }

        return $count;
    }

    /**
     * get join table name list from querystring
     * @param Request $request
     * @param string $prefix
     */
    protected function getJoinTables(Request $request, $prefix)
    {
        $join_tables = [];
        if ($request->has('expands')) {
            $join_tables = collect(explode(',', $request->get('expands')))
                ->map(function ($expand) use ($prefix) {
                    $expand = trim($expand);
                    switch ($expand) {
                        case 'tables':
                        case 'statuses':
                        case 'action':
                        case 'actions':
                        case 'columns':
                        case 'status_from':
                        case 'status_to':
                            return $prefix . '_' . $expand;
                    }
                })->filter()->toArray();
        }
        return $join_tables;
    }

    /**
     * Get Custom Value (or return response)
     *
     * @param CustomTable $custom_table
     * @param string|int|null $id
     * @return CustomValue|\Symfony\Component\HttpFoundation\Response
     */
    protected function getCustomValue(CustomTable $custom_table, $id, bool $withTrashed = false)
    {
        $query = getModelName($custom_table->table_name)::query();
        if ($withTrashed) {
            $query->withTrashed();
        }
        $custom_value = $query->find($id);

        // not contains data, return empty data.
        if (!isset($custom_value)) {
            $code = $custom_table->getNoDataErrorCode($id);
            if ($code == ErrorCode::PERMISSION_DENY) {
                return abortJson(403, $code);
            } else {
                // nodata
                return abortJson(400, $code);
            }
        }

        return $custom_value;
    }
}
