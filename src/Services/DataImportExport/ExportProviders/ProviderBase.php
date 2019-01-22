<?php

namespace Exceedone\Exment\Services\DataImportExport\ExportProviders;

abstract class ProviderBase
{
    
    /**
     * Whether this output is as template
     */
    protected $template = false;

    /**
     * Create a new exporter instance.
     *
     * @param $grid
     */
    public function __construct()
    {
        $this->template = boolval(app('request')->query('temp'));
    }
    
    /**
     * get data name
     */
    abstract public function name();

    /**
     * get data
     */
    abstract public function data();
}
