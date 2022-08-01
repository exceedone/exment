<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

trait XlsxTrait
{
    public function getFormat(): string
    {
        return 'xlsx';
    }


    /**
     * get data table list. contains self table, and relations (if contains)
     */
    public function getDataCount($request)
    {
        return $this->_getData($request, function ($reader) {
            return $this->getRowCount($reader);
        });
    }


    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     */
    protected function isOutputAsZip()
    {
        return false;
    }
}
