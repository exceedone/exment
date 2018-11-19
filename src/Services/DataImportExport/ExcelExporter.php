<?php

namespace Exceedone\Exment\Services\DataImportExport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelExporter extends DataExporterBase
{
    /**
     * execute export
     */
    public function export()
    {
        set_time_limit(240);
        $filename = $this->table->table_view_name.date('YmdHis').".xlsx";
        // get output table
        $outputs = $this->getDataTable();

        // create excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle($this->table->table_name);
        $sheet->fromArray($outputs, null, 'A1', false, false);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $res_headers = [
            'Content-Type'        => 'application/vnd.ms-excel',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];
        response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, $res_headers)->send();
        exit;
    }
}
