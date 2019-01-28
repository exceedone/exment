<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;

class LoginUserProvider extends ProviderBase
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

            // combine value
            $value_custom = array_combine($headers, $value);
            
            // get model
            $modelName = getModelName(SystemTableName::USER);
            // select $model using primary key and value
            $primary_value = array_get($value_custom, $this->primary_key);
            // if not exists, new instance
            if (is_nullorempty($primary_value)) {
                $model = new $modelName;
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
     * @param $data
     * @return array
     */
    public function validateImportData($dataObjects)
    {
        $error_data = [];
        $success_data = [];

        foreach ($dataObjects as $key => $value) {
            $check = $this->validateDataRow($key, $value);
            if ($check === true) {
                array_push($success_data, $value);
            } else {
                $error_data = array_merge($error_data, $check);
            }
        }
        return [$success_data, $error_data];
    }
    
    /**
     * validate data row
     * @param $line_no
     * @param $dataAndModel
     * @return array
     */
    public function validateDataRow($line_no, $dataAndModel)
    {
        return true;
    }

    /**
     * import data
     */
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
        if ($use_loginuser === '1'  && is_null($model->login_user)) {
            $model->login_user = new LoginUser;
        }
        // if set $login_user and $use_loginuser is false, remove
        elseif ($use_loginuser === '0' && !is_null($model->login_user)) {
            $model->login_user->remove();
        }
        
        if (is_null($model->login_user)) {
            return;
        }
        
        // set password
        if (boolval(array_get($data, 'create_password_auto'))) {
            $password = make_password();
            $update_flg = true;
        }
        // set password as input
        elseif (!is_nullorempty(array_get($data, 'password'))) {
            $password = array_get($data, 'password');
            $update_flg = true;
        }

        // send password
        if (boolval(array_get($data, 'send_password')) && isset($password)) {
            $model->login_user->sendPassword($password);
        }

        if ($update_flg) {
            $model->login_user->password = bcrypt($password);
            $model->login_user->save();
        }
        return $model;
    }
}
