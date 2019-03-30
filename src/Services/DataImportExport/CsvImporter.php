<?php

namespace Exceedone\Exment\Services\DataImportExport;

use PhpOffice\PhpSpreadsheet\Reader\Csv;

class CsvImporter extends DataImporterBase
{
    protected $accept_extension = 'csv,zip';

    protected function getDataTable($request)
    {
        // get file
        if (is_string($request)) {
            $path = $request;
            $extension = pathinfo($path)['extension'];
        } else {
            $file = $request->file('custom_table_file');
            $path = $file->getRealPath();
            $extension = $file->extension();
        }

        // if zip, extract
        if ($extension == 'zip') {
            $tmpdir = getTmpFolderPath('data', false);
            $tmpfolderpath = getFullPath(path_join($tmpdir, short_uuid()), 'local', true);
    
            $filename = $file->store($tmpdir, 'local');
            $fullpath = getFullpath($filename, 'local');
    
            // open zip file
            $zip = new \ZipArchive;
            //Define variable like flag to check exitsed file config (config.json) before extract zip file
            $res = $zip->open($fullpath);
            if ($res !== true) {
                //TODO:error
            }
            $zip->extractTo($tmpfolderpath);

            // get all files
            $files = collect(\File::files($tmpfolderpath))->filter(function ($value) {
                return pathinfo($value)['extension'] == 'csv';
            });

            foreach ($files as $csvfile) {
                // first, get custom_table sheet list
                if ($this->custom_table->table_name == $csvfile->getBasename('.csv')) {
                    $datalist[$this->custom_table->table_name] = [
                        'custom_table' => $this->custom_table,
                        'data' => $this->getCsvArray($csvfile->getRealPath()),
                    ];
                    continue;
                }

                // second, get relation data(if exists)
                foreach ($this->relations as $relation) {
                    $sheetName = $relation->getSheetName();
                    if ($sheetName == $csvfile->getBasename('.csv')) {
                        $datalist[$sheetName] = [
                            'custom_table' => $relation->child_custom_table,
                            'relation' => $relation,
                            'data' => $this->getCsvArray($csvfile->getRealPath()),
                        ];
                        continue;
                    }
                }
            }

            // delete tmp folder
            $zip->close();
            // delete zip
            \File::deleteDirectory($tmpfolderpath);
            \File::delete($fullpath);
        } else {
            $datalist[$this->custom_table->table_name] = [
                'custom_table' => $this->custom_table,
                'data' => $this->getCsvArray($path),
            ];
        }

        return $datalist;
    }

    protected function getCsvArray($file)
    {
        $reader = new Csv();
        $reader->setInputEncoding('UTF-8');
        $reader->setDelimiter("\t");
        $spreadsheet = $reader->load($file);
        return $spreadsheet->getActiveSheet()->toArray();
    }
}
