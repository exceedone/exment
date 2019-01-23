<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Traits;

trait DefaultTableTrait
{
    protected $custom_table;

    public function __construct($args = []){
        $this->custom_table = array_get($args, 'custom_table');
    }
}
