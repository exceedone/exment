<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

abstract class ExportActionBase
{
    /**
     * data's count
     *
     * @var integer
     */
    protected $count = 0;

    public function getCount()
    {
        return $this->count;
    }
}
