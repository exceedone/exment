<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class RoleGroupAction extends ExportActionBase implements ActionInterface
{
    /**
     * laravel-admin grid
     */
    protected $grid;

    public function __construct($args = [])
    {
        $this->grid = array_get($args, 'grid');
    }

    public function datalist()
    {
        $providers = [];
        $providers[] = new Export\RoleGroupProvider([
            'grid' => $this->grid
        ]);
        $providers[] = new Export\RoleGroupPermissionProvider([
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

    public function filebasename()
    {
        return 'role_group';
    }
}
