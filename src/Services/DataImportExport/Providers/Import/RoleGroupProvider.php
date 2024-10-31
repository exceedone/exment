<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class RoleGroupProvider extends ProviderBase
{
    protected $primary_key;

    public function __construct($args = [])
    {
        $this->primary_key = array_get($args, 'primary_key', 'id');
    }

    /**
     * get data name
     */
    public function name()
    {
        return 'role_group';
    }

    /**
     * get data and object.
     * set matched model data
     */
    public function getDataObject($data, $options = [])
    {
        $results = [];
        $headers = [];
        $row_count = 0;

        foreach ($data as $key => $value) {
            // get header if $key == 0
            if ($key == 0) {
                $headers = $value;
                continue;
            }
            // continue if $key == 1
            elseif ($key == 1) {
                continue;
            }

            $row_count++;
            if (!$this->isReadRow($row_count, $options)) {
                continue;
            }

            // combine value
            $null_merge_array = collect(range(1, count($headers)))->map(function () {
                return null;
            })->toArray();
            $value = $value + $null_merge_array;
            $value_custom = array_combine($headers, $value);
            $delete = boolval(array_get($value_custom, 'delete')) || boolval(array_get($value_custom, 'delete_flg'));

            $value_custom = array_only($value_custom, $this->getImportColumnName());
            $results[] = ['data' => $value_custom, 'delete' => $delete];
        }

        return $results;
    }

    protected function getImportColumnName(): array
    {
        return [
            'id', 
            'role_group_name', 
            'role_group_view_name', 
            'role_group_order', 
            'description'
        ];
    }

    /**
     * validate imported all data.
     * @param mixed $dataObjects
     * @return array
     */
    public function validateImportData($dataObjects)
    {
        $error_data = [];
        $success_data = [];

        foreach ($dataObjects as $key => $value) {
            $check = $this->validateDataRow($key, $value);
            if ($check === true) {
                $success_data[] = $value;
            } else {
                $error_data = array_merge($error_data, $check);
            }
        }

        $id_list = collect($dataObjects)->map(function ($item, $key) {
            $data = array_get($item, 'data');
            $id = array_get($data, 'id');
            $delete = array_get($item, 'delete');
            return ['id' => $id, 'delete' => $delete, 'line_no' => $key+1];
        })->filter(function ($item) {
            return isset($item['id']);
        })->sortBy(function ($item) {
            return $item['id'];
        })->reduce(function ($carry, $item) use(&$error_data) {
            $id = array_get($item, 'id');
            $delete = array_get($item, 'delete');
            $line_no = array_get($item, 'line_no');
            if (in_array($id, $carry)) {
                if ($delete) {
                    $carry = array_filter($carry, function($data) use($id) {
                        return $data != $id;
                    });
                } else {
                    $error_data[] = sprintf(exmtrans('custom_value.import.import_error_format_sheet'), $this->name(), $line_no, 'IDが重複しています');
                }
            } elseif (!$delete) {
                $carry[] = $id;
            }
            return $carry;
        }, []);

        return [$success_data, $error_data];
    }

    /**
     * validate data row
     *
     * @param $line_no
     * @param $dataAndModel
     * @return array|true
     */
    public function validateDataRow($line_no, $dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $id = array_get($data, 'id');

        $errors = [];

        // execute validation
        /** @var ExmentCustomValidator $validator */
        $validator = \Validator::make($data, [
            'id' => 'nullable|numeric',
            'role_group_name' => [
                'max:64',
                Rule::unique('role_groups')->ignore($id),
                'regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/',
            ],
            'role_group_view_name' => 'required|max:64',
            'role_group_order' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            // create error message
            foreach ($validator->getMessages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format_sheet'), $this->name(), ($line_no+1), implode(',', $message));
            }
         }

        if (!is_nullorempty($errors)) {
            return $errors;
        }
        return true;
    }

    /**
     * import data
     */
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $delete = array_get($dataAndModel, 'delete');
        $id = array_get($data, 'id');

        // if data not has id, create new instance
        if (is_nullorempty($id)) {
            $model = new RoleGroup();
        }
        // if data has id, find data (if not found and not delete create new instance)
        else {
            $model = RoleGroup::find($id);
            if (!isset($model)) {
                $model = new RoleGroup();
            }
        }

        if ($delete) {
            return $model->delete();
        }

        // set each column data
        foreach ($data as $dkey => $dvalue) {
            $dvalue = is_nullorempty($dvalue) ? null : $dvalue;
            // if not exists column, continue
            if (!in_array($dkey, $this->getImportColumnName())) {
                continue;
            }
            if ($dkey == 'role_group_order') {
                $dvalue = $dvalue?? 0;
            }
            $model->{$dkey} = $dvalue;
        }

        return $model->save();
    }
}
