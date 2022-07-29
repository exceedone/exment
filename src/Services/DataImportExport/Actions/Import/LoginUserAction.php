<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

use Exceedone\Exment\Services\DataImportExport\Providers\Import;
use Exceedone\Exment\Model\Define;

class LoginUserAction implements ActionInterface
{
    protected $primary_key;

    public function __construct($args = [])
    {
        $this->primary_key = array_get($args, 'primary_key', 'id');
    }

    public function import($datalist, $options = [])
    {
        // get target data and model list
        $data_imports = [];
        foreach ($datalist as $table_name => &$data) {
            //$target_table = $data['custom_table'];
            $provider = new Import\LoginUserProvider([
                'primary_key' => $this->primary_key,
            ]);

            $dataObject = $provider->getDataObject($data, $options);

            // validate data
            list($data_import, $error_data) = $provider->validateImportData($dataObject);

            // if has error data, return error data
            if (is_array($error_data) && count($error_data) > 0) {
                return response([
                    'result' => false,
                    'toastr' => exmtrans('common.message.import_error'),
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => implode("\r\n", $error_data)]],
                ], 400);
            }
            $data_imports[] = [
                'provider' => $provider,
                'data_import' => $data_import
            ];
        }

        foreach ($data_imports as $data_import) {
            // execute imoport
            $provider = $data_import['provider'];
            foreach ($data_import['data_import'] as $index => &$row) {
                // call dataProcessing if method exists
                if (method_exists($provider, 'dataProcessing')) {
                    $row['data'] = $provider->dataProcessing(array_get($row, 'data'));
                }

                $provider->importData($row);
            }
        }

        return [
            'result' => true,
            'toastr' => exmtrans('common.message.import_success')
        ];
    }

    /**
     * filter
     */
    public function filterDatalist($datalist)
    {
        return $datalist;
    }

    // Import Modal --------------------------------------------------

    /**
     * get import modal endpoint. not contains "import" and "admin"
     */
    public function getImportEndpoint()
    {
        return 'loginuser';
    }

    public function getImportHeaderViewName()
    {
        return exmtrans('menu.system_definitions.loginuser');
    }

    /**
     * get primary key list.
     */
    public function getPrimaryKeys()
    {
        // default list
        $keys = getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options");
        return $keys;
    }

    /**
     * set_import_modal_items. it sets at form footer
     */
    public function setImportModalItems(&$form)
    {
        return $this;
    }
}
