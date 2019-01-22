<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use PhpOffice\PhpSpreadsheet\IOFactory;

class Csv extends FormatBase
{
    public function getFileName(){
        return $this->filebasename.date('YmdHis'). ($this->isOutputAsZip() ? ".zip" : ".csv");
    }

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     */
    protected function isOutputAsZip(){
        // check relations
        return count($this->datalist) > 1;
    }
    
    protected function createWriter($spreadsheet){
        return IOFactory::createWriter($spreadsheet, 'Csv');
    }
}
