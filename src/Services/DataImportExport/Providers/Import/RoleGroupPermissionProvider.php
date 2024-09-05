<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Illuminate\Support\Str;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Carbon\Carbon;
use Exceedone\Exment\Model\CustomTable;

class RoleGroupPermissionProvider extends ProviderBase
{
    protected $primary_key;

    public function __construct($args = [])
    {
        $this->primary_key = array_get($args, 'primary_key', 'id');
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

            // select $model using primary key and value
            $primary_value = array_get($value_custom, $this->primary_key);
            // if not exists, new instance
            if (is_nullorempty($primary_value)) {
                $model = new RoleGroupPermission();
            }
            // if exists, firstOrNew
            else {
                $model = RoleGroupPermission::findOrNew($primary_value);
            }
            if (!isset($model)) {
                continue;
            }

            $results[] = ['data' => $value_custom, 'model' => $model];
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

        // execute validation
        /** @var ExmentCustomValidator $validator */
        $validator = \Validator::make($data, [
            'role_group_id' => 'required|exists:' . RoleGroup::make()->getTable() . ',id',
            'role_group_permission_type' => 'required|regex:/^[013]$/',
            'permissions' => [
                function ($attribute, $value, $fail) use($line_no) {
                    if (!empty($value) && is_string($value)) {
                        $value = explode(',', $value);
                        foreach ($value as $val) {
                            if (!in_array($val, Permission::arrays())) {
                                $fail(exmtrans('custom_value.import.message.permission_not_exists', $val));
                             }
                        }
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            // create error message
            foreach ($validator->getMessages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), implode(',', $message));
            }
        } else {
            $role_group_permission_type = array_get($data, 'role_group_permission_type')?? 0;
            $role_group_target_id = array_get($data, 'role_group_target_id')?? 0;
            if ($role_group_permission_type == RoleType::TABLE) {
                if (!CustomTable::where('id', $role_group_target_id)->exists()) {
                    $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), exmtrans('custom_value.import.message.target_table_not_found'));
                }
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
        $model = array_get($dataAndModel, 'model');

        // select $model using primary key and value
        $primary_value = array_get($data, $this->primary_key);
        // if not exists, new instance
        if (is_nullorempty($primary_value)) {
            $isCreate = true;
        }
        // if exists, firstOrNew
        else {
            $isCreate = !$model->exists;
        }
        if (!isset($model)) {
            return;
        }

        $permissions = [];
        foreach ($data as $dkey => $dvalue) {
            $dvalue = is_nullorempty($dvalue) ? null : $dvalue;
            // if not exists column, continue
            if (!in_array($dkey, ['id', 'role_group_id', 'role_group_permission_type', 'role_group_target_id', 'created_at', 'updated_at'])) {
                if (!Str::startsWith($dkey, 'permissions.')) {
                    continue;
                }
            }
            if (in_array($dkey, ['created_at', 'updated_at'])) {
                // if null, contiune
                if (is_nullorempty($dvalue)) {
                    continue;
                }
                // if not create and created_at, continue(because time back)
                if (!$isCreate && $dkey == 'created_at') {
                    continue;
                }
                // set as date
                $model->{$dkey} = Carbon::parse($dvalue);
            }
            // if id
            elseif ($dkey == 'id') {
                // if null, contiune
                if (is_nullorempty($dvalue)) {
                    continue;
                }
                $model->id = $dvalue;
            }
            // if permissions
            elseif (Str::startsWith($dkey, 'permissions.')) {
                // if null, contiune
                if (boolval($dvalue)) {
                    $permissions[] = $dkey;
                }
            }
            // else, set
            else {
                $model->{$dkey} = $dvalue;
            }
        }

        $model->permissions = $this->getPermissions($model->role_group_permission_type, $permissions);

        // save model
        $model->save();

        return $model;
    }

    protected function getPermissions(int|string $role_type, array $values)
    {
        $result = [];
        foreach($values as $value) {
            $keys = explode('.', $value);
            if (count($keys) !== 3) {
                continue;
            }

            $role_group_type = $keys[1];
            $permission = $keys[2];

            if (!in_array($role_group_type, $this->getTargetRoleGroupType($role_type))) {
                continue;
            }

            $role_group_permissions = RoleGroupType::getEnum($role_group_type)->getRoleGroupPermissions();
            if (in_array($permission, $role_group_permissions)) {
                $result[] = $permission;
            }
        }

        return $result;
    }
    
    protected function getTargetRoleGroupType($role_type)
    {
        switch ($role_type) {
            case RoleType::SYSTEM:
                return [RoleGroupType::SYSTEM, RoleGroupType::ROLE_GROUP];
            case RoleType::TABLE:
                return [RoleGroupType::MASTER, RoleGroupType::TABLE];
            case RoleType::PLUGIN:
                return [RoleGroupType::PLUGIN];
        }
        return [];
    }
}
