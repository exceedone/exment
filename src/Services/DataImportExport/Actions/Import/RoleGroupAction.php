<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

use Exceedone\Exment\Services\DataImportExport\Providers\Import;
use Exceedone\Exment\Model\Define;

class RoleGroupAction implements ActionInterface
{
    // @phpstan-ignore-next-line
    protected $primary_key;

    // @phpstan-ignore-next-line
    public function __construct($args = [])
    {
        $this->primary_key = array_get($args, 'primary_key', 'id');
    }

    // @phpstan-ignore-next-line
    public function import($datalist, $options = [])
    {
        // get target data and model list
        $data_imports = [];
        foreach ($datalist as $table_name => &$data) {
            $provider = $this->getProvider($table_name);
            if (!isset($provider)) {
                continue;
            }

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
    // @phpstan-ignore-next-line
    public function filterDatalist($datalist)
    {
        return $datalist;
    }

    // Import Modal --------------------------------------------------

    /**
     * get import modal endpoint. not contains "import" and "admin"
     */
    // @phpstan-ignore-next-line
    public function getImportEndpoint()
    {
        return 'role_group';
    }

    // @phpstan-ignore-next-line
    public function getImportHeaderViewName()
    {
        return exmtrans('menu.system_definitions.role_group');
    }

    /**
     * get primary key list.
     */
    // @phpstan-ignore-next-line
    public function getPrimaryKeys()
    {
        // default list
        $keys = getTransArray(['id'], "custom_value.import.key_options");
        return $keys;
    }

    /**
     * set_import_modal_items. it sets at form footer
     */
    // @phpstan-ignore-next-line
    public function setImportModalItems(&$form)
    {
        return $this;
    }

    // @phpstan-ignore-next-line
    protected function getProvider(string $table_name)
    {
        switch ($table_name) {
            case 'role_group':
                return new Import\RoleGroupProvider([
                    'primary_key' => $this->primary_key,
                ]);
            case 'role_group_permission_system':
                return new Import\RoleGroupPermissionSystemProvider();
            case 'role_group_permission_role':
                return new Import\RoleGroupPermissionRoleProvider();
            case 'role_group_permission_plugin':
                return new Import\RoleGroupPermissionPluginProvider();
            case 'role_group_permission_master':
                return new Import\RoleGroupPermissionMasterProvider();
            case 'role_group_permission_table':
                return new Import\RoleGroupPermissionTableProvider();
            case 'role_group_user_organization':
                return new Import\RoleGroupUserOrganizationProvider();
        }

    }
}
