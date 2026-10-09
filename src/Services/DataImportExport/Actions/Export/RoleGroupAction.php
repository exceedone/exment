<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class RoleGroupAction extends ExportActionBase implements ActionInterface
{
    /**
     * laravel-admin grid
     */
    // @phpstan-ignore-next-line
    protected $grid;

    // @phpstan-ignore-next-line
    public function __construct($args = [])
    {
        $this->grid = array_get($args, 'grid');
    }

    // @phpstan-ignore-next-line
    public function datalist()
    {
        $providers = [];
        $providers[] = new Export\RoleGroupProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupPermissionSystemProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupPermissionRoleProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupPermissionPluginProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupPermissionMasterProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupPermissionTableProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupUserOrganizationProvider([
            'grid' => $this->grid
        ]);

        $datalist = [];
        foreach ($providers as $provider) {
            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
            $this->count .= $provider->getCount();
        }

        return $datalist;
    }

    // @phpstan-ignore-next-line
    public function filebasename()
    {
        return 'role_group';
    }
}
