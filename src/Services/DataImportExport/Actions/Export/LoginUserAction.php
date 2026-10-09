<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class LoginUserAction extends ExportActionBase implements ActionInterface
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
        $provider = new Export\LoginUserProvider([
            'grid' => $this->grid
        ]);

        $datalist = [];
        $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        $this->count = $provider->getCount();

        return $datalist;
    }

    // @phpstan-ignore-next-line
    public function filebasename()
    {
        return 'login_user';
    }
}
