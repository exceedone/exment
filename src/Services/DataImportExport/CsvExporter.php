<?php

namespace Exceedone\Exment\Services\DataImportExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use \File;

class CsvExporter extends DataExporterBase
{
    protected function createResponse($files){
        // save as csv
        if(count($files) == 1){
            return response()->stream(function () use ($files) {
                $files[0]['writer']->save('php://output');
            }, 200, $this->getDefaultHeaders());
        }
        // save as zip
        else{
            $tmpdir = getTmpFolderPath('data');

            $zip = new \ZipArchive();
            $zipfilename = short_uuid().'.zip';
            $zipfillpath = path_join($tmpdir, $zipfilename);
            $res = $zip->open($zipfillpath, \ZipArchive::CREATE);
            
            $csvdir = path_join($tmpdir, short_uuid());
            if (!File::exists($csvdir)) {
                File::makeDirectory($csvdir, 0755, true);
            }

            $csv_paths = [];
            foreach($files as $f){
                // csv path
                $csv_name = $f['name'] . '.csv';
                $csv_path = path_join($csvdir, $csv_name);
                $f['writer']->save($csv_path);
                $zip->addFile($csv_path, $csv_name);
                $csv_paths[] = $csv_path;
            }
            $zip->close();
            \File::deleteDirectory($csvdir);

            $response = response()->download($zipfillpath, $this->getFileName())->deleteFileAfterSend(true);
            return $response;
        }
    }
    
    protected function createWriter($spreadsheet){
        return IOFactory::createWriter($spreadsheet, 'Csv');
    }

    protected function getFileName(){
        return $this->custom_table->table_view_name.date('YmdHis'). ($this->isOutputAsZip() ? ".zip" : ".csv");
    }

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     */
    protected function isOutputAsZip(){
        // check relations
        return count($this->relations) > 0;
    }

}
