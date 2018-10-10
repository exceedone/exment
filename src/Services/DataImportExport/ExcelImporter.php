<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Facades\Admin;
use Validator;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class ExcelImporter extends DataImporterBase
{
    protected $accept_extension = 'xlsx';

    protected function getDataTable($request){
        $reader = Excel::load($request->file('custom_table_file')->getRealPath());
        if ($reader == null)
        {
            throw new \Exception('error.');
        }

        // set config
        config(['excel.import.heading' => false]); // heading false. read first row

        // read cell
        $data = [];
        foreach ($reader->all() as $cells)
        {
            $aa = $cells->all();
            $data[] = $cells->all();
        }
        
        return $data;
    }    
}
