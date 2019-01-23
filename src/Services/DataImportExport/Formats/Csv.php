<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use PhpOffice\PhpSpreadsheet\IOFactory;

class Csv extends FormatBase
{
    protected $accept_extension = 'csv,zip';

    public function getFileName(){
        return $this->filebasename.date('YmdHis'). ($this->isOutputAsZip() ? ".zip" : ".csv");
    }
    
    public function getDataTable($request)
    {
        // get file
        if(is_string($request)){
            $path = $request;
            $extension = pathinfo($path)['extension'];
            $originalName = pathinfo($path, PATHINFO_BASENAME);
        }else{
            $file = $request->file('custom_table_file');
            $path = $file->getRealPath();
            $extension = $file->extension();
            $originalName = $file->getClientOriginalName();
        }

        // if zip, extract
        if($extension == 'zip'){
            $tmpdir = getTmpFolderPath('data', false);
            $tmpfolderpath = getFullPath(path_join($tmpdir, short_uuid()), 'admin_tmp', true);
            
            $filename = $file->store($tmpdir, 'admin_tmp');
            $fullpath = getFullpath($filename, 'admin_tmp');
    
            // open zip file
            $zip = new \ZipArchive;
            //Define variable like flag to check exitsed file config (config.json) before extract zip file
            $res = $zip->open($fullpath);
            if ($res !== true) {
                //TODO:error
            }
            $zip->extractTo($tmpfolderpath);

            // get all files
            $files = collect(\File::files($tmpfolderpath))->filter(function($value){
                return pathinfo($value)['extension'] == 'csv';
            });

            foreach($files as $csvfile){
                $basename = $csvfile->getBasename('.csv');
                $datalist[$basename] = $this->getCsvArray($csvfile->getRealPath());
            }

            // delete tmp folder
            $zip->close();
            // delete zip
            \File::deleteDirectory($tmpfolderpath);
            \File::delete($fullpath);
        }else{
            $basename = file_ext_strip($originalName);
            $datalist[$basename] = $this->getCsvArray($path);
        }

        return $datalist;
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
    
    protected function createReader(){
        return IOFactory::createReader('Csv');
    }

    protected function getCsvArray($file){
        $reader = $this->createReader();
        $reader->setInputEncoding('UTF-8');
        $reader->setDelimiter("\t");
        $spreadsheet = $reader->load($file);
        return $spreadsheet->getActiveSheet()->toArray();
    }
}
