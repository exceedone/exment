<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;

abstract class ProviderBase
{
    /**
     * Whether this output is as template
     */
    protected $template = false;

    /**
     * data's count
     *
     * @var integer
     */
    protected $count = 0;

    /**
     * Create a new exporter instance.
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

    /**
     * is output this sheet
     *
     * @return boolean
     */
    public function isOutput()
    {
        return true;
    }

    public function getCount()
    {
        return $this->count;
    }

    abstract public function getRecords(): Collection;
}
