<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class LoginUserAction extends ExportActionBase implements ActionInterface
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
        $provider = new Export\LoginUserProvider([
            'grid' => $this->grid
        ]);

        $datalist = [];
        $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        $this->count = $provider->getCount();

        return $datalist;
    }

    public function filebasename()
    {
        return 'login_user';
    }
}
