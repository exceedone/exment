<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Encore\Admin\Grid;
use Maatwebsite\Excel\Facades\Excel;

class ExcelExporter extends DataExporterBase
{
    /**
     * execute export
     */
    public function export()
    {
        $filename = $this->table->table_name.date('YmdHis');
        // get output table
        $outputs = $this->getDataTable();

        // set config
        config(['excel.export.sheets.strictNullComparison' => true]); // Even if val is 0, outout cell

        // create excel
        Excel::create($filename, function($excel) use($outputs) {
            // create sheet
            $excel->sheet('Sheet1', function($sheet) use($outputs) {
                // add from array
                $sheet->fromArray($outputs, null, 'A1', false, false);
            });

        })->export('xlsx');
    }
}
