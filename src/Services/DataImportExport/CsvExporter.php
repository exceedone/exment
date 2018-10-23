<?php

namespace Exceedone\Exment\Services\DataImportExport;


class CsvExporter extends DataExporterBase
{
    /**
     * execute export
     */
    public function export()
    {
        $filename = $this->table->table_name.date('YmdHis').'.csv';
        $res_headers = [
            'Content-Encoding'    => 'UTF-8',
            'Content-Type'        => 'text/csv;charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        // get output table
        $outputs = $this->getDataTable();

        response()->stream(function () use($outputs){
            // create csv
            $handle = fopen('php://output', 'w');
            foreach ($outputs as $output) {
                fputcsv($handle, $output);
            }
            // Close the output stream
            fclose($handle);
        }, 200, $res_headers)->send();

        exit;
    }
}
