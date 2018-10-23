<?php

namespace Exceedone\Exment\Services\DataImportExport;


class CsvImporter extends DataImporterBase
{
    protected $accept_extension = 'csv';
    protected function getDataTable($request){
        // get file
        $path = $request->file('custom_table_file')->getRealPath();
        $dataCsv = array_map('str_getcsv', file($path));
        return $dataCsv;        
    }    
}
