<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Validator\ExmentCustomValidator;

class RoleGroupPermissionProvider extends ProviderBase
{
    protected $role_group_permission_type = 0;
    protected $role_group_target_id;
    protected $permission_keys = [];

    /**
     * get data name
     */
    public function name()
    {
        return '';
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

            $results[] = ['data' => $value_custom];
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
        $rules = [
            'role_group_id' => 'required|exists:' . $model->getTable() . ',id',
        ];

        foreach($this->permission_keys as $permission_key)
        {
            $rules["permissions:$permission_key"] = 'nullable|regex:/^[01]$/';
        }

        $this->addValidateTypeRules($rules);

        // execute validation
        /** @var ExmentCustomValidator $validator */
        $validator = \Validator::make($data, $rules);
        if ($validator->fails()) {
            // create error message
            foreach ($validator->getMessages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format_sheet'), $this->name(), ($line_no+1), implode(',', $message));
            }
        }
        $this->validateExtraRules($data, $line_no, $errors);

        if (!is_nullorempty($errors)) {
            return $errors;
        }
        return true;
    }

    /**
     * add data row validate rules for each role type
     * 
     * @param array $rules
     */
    protected function addValidateTypeRules(&$rules) : void
    {
    }

    /**
     * validate data row by custom rules
     * 
     * @param array $data
     * @param int $line_no
     * @param array $errors
     */
    protected function validateExtraRules($data, $line_no, &$errors) : void
    {
    }

    /**
     * import data
     */
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $role_group_id = array_get($data, 'role_group_id');

        // parent data not exists, do nothing
        if (!RoleGroup::where('id', $role_group_id )->exists()) {
            return;
        }

        $permissions = [];

        foreach($this->permission_keys as $permission_key)
        {
            $dvalue = array_get($data, "permissions:$permission_key"); 
            if (boolval($dvalue)) {
                $permissions[] = $permission_key;
            }
        }

        $role_group_target_id = $this->role_group_target_id?? array_get($data, 'role_group_target_id');

        $model = RoleGroupPermission::where('role_group_id', $role_group_id)
            ->where('role_group_permission_type', $this->role_group_permission_type)
            ->where('role_group_target_id', $role_group_target_id)
            ->first();

        if (!isset($model)) {
            $model = new RoleGroupPermission();
            $model->role_group_id = $role_group_id;
            $model->role_group_permission_type = $this->role_group_permission_type;
            $model->role_group_target_id = $role_group_target_id;
        }

        $model->permissions = $permissions;

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
