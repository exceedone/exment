<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class RoleGroupUserOrganizationProvider extends ProviderBase
{
    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_user_organization';
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

            $value_custom = array_only($value_custom, ['role_group_id', 'role_group_user_org_type', 'role_group_target_id']);

            $results[] = ['data' => $value_custom, 'delete' => $delete];
        }

        return $results;
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
 
        $errors = [];
        $model = new RoleGroup();

        // execute validation
        /** @var ExmentCustomValidator $validator */
        $validator = \Validator::make($data, [
            'role_group_id' => 'required|exists:' . $model->getTable() . ',id',
            'role_group_user_org_type' => 'required|in:' . SystemTableName::USER . ',' . SystemTableName::ORGANIZATION,
            'role_group_target_id' => 'required',
        ]);
        if ($validator->fails()) {
            // create error message
            foreach ($validator->getMessages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format_sheet'), $this->name(), ($line_no+1), implode(',', $message));
            }
        } else {
            $role_group_user_org_type = array_get($data, 'role_group_user_org_type');
            $role_group_target_id = array_get($data, 'role_group_target_id');
            if (!CustomTable::getEloquent($role_group_user_org_type)->getValueModel()->withoutGlobalScopes()->where('id', $role_group_target_id)->exists()) {
                $message = exmtrans('custom_value.import.message.user_org_not_exists', exmtrans("$role_group_user_org_type.default_table_name"));
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format_sheet'), $this->name(), ($line_no+1), $message);
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
    public function importData($dataPivot)
    {
        $data = array_get($dataPivot, 'data');
        $delete = array_get($dataPivot, 'delete');
        $role_group_id = array_get($data, 'role_group_id');

        // parent data not exists, do nothing
        if (!RoleGroup::where('id', $role_group_id )->exists()) {
            return;
        }

        // get target id(cannot use Eloquent because not define)
        $id = RoleGroupUserOrganization::where('role_group_id', $role_group_id)
            ->where('role_group_user_org_type', array_get($data, 'role_group_user_org_type'))
            ->where('role_group_target_id', array_get($data, 'role_group_target_id'))
            ->first()->id ?? null;

        // if delete
        if ($delete) {
            if (isset($id)) {
                RoleGroupUserOrganization::find($id)->delete();
            }
            return;
        } 
        
        if (!isset($id)) {
            RoleGroupUserOrganization::insert($data);
        }
    }
}
