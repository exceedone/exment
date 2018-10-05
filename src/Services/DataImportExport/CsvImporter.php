<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Facades\Admin;
use Validator;
use Carbon\Carbon;

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
