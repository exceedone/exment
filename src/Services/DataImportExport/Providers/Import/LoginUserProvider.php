<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Validator\ExmentCustomValidator;

class LoginUserProvider extends ProviderBase
{
    // @phpstan-ignore-next-line
    protected $primary_key;

    // @phpstan-ignore-next-line
    public function __construct($args = [])
    {
        $this->primary_key = array_get($args, 'primary_key', 'id');
    }

    /**
     * get data and object.
     * set matched model data
     */
    // @phpstan-ignore-next-line
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

            // get model
            $modelName = getModelName(SystemTableName::USER);
            // select $model using primary key and value
            $primary_value = array_get($value_custom, $this->primary_key);
            // if not exists, new instance
            if (is_nullorempty($primary_value)) {
                $model = new $modelName();
            }
            // if exists, firstOrNew
            else {
                //*Replace "." to "->" for json value
                $model = $modelName::withTrashed()->firstOrNew([str_replace(".", "->", $this->primary_key) => $primary_value]);
            }
            if (!isset($model)) {
                continue;
            }

            $model->saved_notify(false);

            $results[] = ['data' => $value_custom, 'model' => $model];
        }

        return $results;
    }

    /**
     * validate imported all data.
     * @param mixed $dataObjects
     * @return array
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function validateDataRow($line_no, $dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');

        // only administrator can change administrator's login (UserTrait::disabled_edit_trait).
        // importData() reaches the login through the user's id, or through the "id" column when
        // the key found no user - also one the record scope hides - so both ids are checked,
        // without the scope, before any of the early returns below. A row that changes nothing
        // passes, as on v6.2.15: an exported list holds the administrator too.
        if ($this->changesLogin($data, $model)) {
            foreach ([$model->id ?? null, array_get($data, 'id')] as $user_id) {
                if (is_nullorempty($user_id) || !is_numeric($user_id)) {
                    continue;
                }
                $user = getModelName(SystemTableName::USER)::withoutGlobalScopes()->find($user_id);
                if (isset($user) && $user->disabled_edit_trait()) {
                    return [sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), ErrorCode::PERMISSION_DENY()->getMessage())];
                }
            }
        }

        if (!boolval(array_get($data, 'use_loginuser')) || boolval(array_get($data, 'create_password_auto'))) {
            return true;
        }
        // if create_password_auto and password are null, nothing
        if (is_null(array_get($data, 'create_password_auto')) && is_null(array_get($data, 'password'))) {
            return true;
        }

        $errors = [];

        // execute validation
        /** @var ExmentCustomValidator $validator */
        $validator = \Validator::make($data, [
            // get validate password.
            // not check history.
            'password' => \Exment::get_password_rule(true, null, ['confirmed' => false])
        ]);
        if ($validator->fails()) {
            // create error message
            foreach ($validator->getMessages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), implode(',', $message));
            }
            // return $errors;
        }

        if (!is_nullorempty($errors)) {
            return $errors;
        }
        return true;
    }

    /**
     * Whether importData() would change the login of the row's user: remove
     * it, or give it a password (which also creates a missing login). The
     * same conditions as importData(), in the same order.
     *
     * @param mixed $data
     * @param mixed $model
     * @return bool
     */
    protected function changesLogin($data, $model): bool
    {
        if (!array_has($data, 'use_loginuser')) {
            return false;
        }

        $use_loginuser = array_get($data, 'use_loginuser');
        $has_login = !is_null($model->login_user);
        if (strcmp_ex($use_loginuser, '0') == 0) {
            return $has_login;
        }
        if (!$has_login && strcmp_ex($use_loginuser, '1') != 0) {
            return false;
        }

        return boolval(array_get($data, 'create_password_auto')) || !is_nullorempty(array_get($data, 'password'));
    }

    /**
     * import data
     */
    // @phpstan-ignore-next-line
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');

        // if not has key 'use_loginuser' return
        if (!array_has($data, 'use_loginuser')) {
            return;
        }

        $update_flg = false;
        // get use_loginuser
        $use_loginuser = array_get($data, 'use_loginuser');
        // if not set $login_user and $use_loginuser is true, create
        if (strcmp_ex($use_loginuser, '1') == 0  && is_null($model->login_user)) {
            $model->login_user = new LoginUser();
            $model->login_user->base_user_id = array_get($data, 'id');
        }
        // if set $login_user and $use_loginuser is false, remove
        elseif (strcmp_ex($use_loginuser, '0') == 0 && !is_null($model->login_user)) {
            $model->login_user->delete();
            return;
        }

        if (is_null($model->login_user)) {
            return;
        }

        // set password
        $password = null;
        if (boolval(array_get($data, 'create_password_auto'))) {
            $password = make_password();
            $update_flg = true;
        }
        // set password as input
        elseif (!is_nullorempty(array_get($data, 'password'))) {
            $password = array_get($data, 'password');
            $update_flg = true;
        }

        if ($update_flg) {
            // reset password
            LoginService::resetPassword($model->login_user, [
                'send_password' => boolval(array_get($data, 'send_password')),
                'password_reset_flg' => (System::first_change_password() || boolval(array_get($data, 'password_reset_flg'))),
                'password' => $password,
            ]);
        }
        return $model;
    }
}
