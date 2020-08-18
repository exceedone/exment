<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Exceedone\Exment\Model\Define;
use \File;

class Csv extends FormatBase
{
    protected $accept_extension = 'csv,zip';

    public function getFileName()
    {
        return $this->filebasename.date('YmdHis'). ($this->isOutputAsZip() ? ".zip" : ".csv");
    }
    
    public function getDataTable($request)
    {
        // get file
        if ($request instanceof Request) {
            $file = $request->file('custom_table_file');
            $path = $file->getRealPath();
            $extension = $file->extension();
            $originalName = $file->getClientOriginalName();
        } elseif ($request instanceof \SplFileInfo) {
            $path = $request->getPathName();
            $extension = pathinfo($path)['extension'];
            $originalName = pathinfo($path, PATHINFO_BASENAME);
        } else {
            $path = $request;
            $extension = pathinfo($path)['extension'];
            $originalName = pathinfo($path, PATHINFO_BASENAME);
        }

        // if zip, extract
        if ($extension == 'zip' && isset($file)) {
            $tmpdir = getTmpFolderPath('data', false);
            $tmpfolderpath = getFullPath(path_join($tmpdir, short_uuid()), Define::DISKNAME_ADMIN_TMP, true);
            
            $filename = $file->store($tmpdir, Define::DISKNAME_ADMIN_TMP);
            $fullpath = getFullpath($filename, Define::DISKNAME_ADMIN_TMP);
    
            // open zip file
            try {
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

                // if over row size, return number
                if (($count = $this->getRowCount($files)) > (config('exment.import_max_row_count', 1000) + 2)) {
                    return $count;
                }

                $datalist = [];
                foreach ($files as $csvfile) {
                    $basename = $csvfile->getBasename('.csv');
                    $datalist[$basename] = $this->getCsvArray($csvfile->getRealPath());
                }

                return $datalist;
            } finally {
                // delete tmp folder
                if (!is_nullorempty($zip)) {
                    $zip->close();
                }
                // delete zip
                if (isset($tmpfolderpath)) {
                    \File::deleteDirectory($tmpfolderpath);
                }
                if (isset($fullpath)) {
                    \File::delete($fullpath);
                }
            }
        } else {
            // if over row size, return number
            if (($count = $this->getRowCount($path)) > (config('exment.import_max_row_count', 1000) + 2)) {
                return $count;
            }

            $basename = $this->filebasename;
            $datalist[$basename] = $this->getCsvArray($path);
        }

        return $datalist;
    }

    
    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     *
     * @return boolean
     */
    protected function isOutputAsZip()
    {
        // check relations
        return count($this->datalist) > 1;
    }
    
    public function createResponse($files)
    {
        // save as csv
        if (count($files) == 1) {
            return response()->stream(function () use ($files) {
                $writer = $this->createWriter($files[0]['spreadsheet']);

                // append bom if config
                if (boolval(config('exment.export_append_csv_bom', false))) {
                    $writer->setUseBOM(true);
                }

                $writer->save('php://output');
                // close workbook and release memory
                $files[0]['spreadsheet']->disconnectWorksheets();
                $files[0]['spreadsheet']->garbageCollect();
                unset($writer);
            }, 200, $this->getDefaultHeaders());
        }
        // save as zip
        else {
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
            foreach ($files as $f) {
                // csv path
                $csv_name = $f['name'] . '.csv';
                $csv_path = path_join($csvdir, $csv_name);
                $writer = $this->createWriter($f['spreadsheet']);
                
                // append bom if config
                if (boolval(config('exment.export_append_csv_bom', false))) {
                    $writer->setUseBOM(true);
                }

                $writer->save($csv_path);
                $zip->addFile($csv_path, $csv_name);
                $csv_paths[] = $csv_path;

                // close workbook and release memory
                $f['spreadsheet']->disconnectWorksheets();
                $f['spreadsheet']->garbageCollect();
                unset($writer);
            }

            $zip->close();
            \File::deleteDirectory($csvdir);

            $response = response()->download($zipfillpath, $this->getFileName())->deleteFileAfterSend(true);
            return $response;
        }
    }

    protected function getDefaultHeaders()
    {
        $filename = $this->getFileName();
        return [
            'Content-Type'        => 'application/force-download',
            'Content-disposition' => "attachment; filename*=UTF-8''". rawurlencode($filename),
        ];
    }


    protected function createWriter($spreadsheet)
    {
        return IOFactory::createWriter($spreadsheet, 'Csv');
    }
    
    protected function createReader()
    {
        return IOFactory::createReader('Csv');
    }

    /**
     * Get all csv's row count
     *
     * @param [type] $spreadsheet
     * @return int
     */
    protected function getRowCount($files) : int
    {
        $count = 0;
        if (is_string($files)) {
            $files = [$files];
        }

        // get data count
        foreach ($files as $file) {
            $reader = $this->createReader();
            $reader->setInputEncoding('UTF-8');
            $reader->setDelimiter(",");
            $spreadsheet = $reader->load($file);
            
            $count += intval($spreadsheet->getActiveSheet()->getHighestRow());
        }

        return $count;
    }

    protected function getCsvArray($file)
    {
        $original_locale = setlocale(LC_CTYPE, 0);

        // set C locale
        if (0 === strpos(PHP_OS, 'WIN')) {
            setlocale(LC_CTYPE, 'C');
        }

        $reader = $this->createReader();
        $reader->setInputEncoding('UTF-8');
        $reader->setDelimiter(",");
        $spreadsheet = $reader->load($file);
        $array = $spreadsheet->getActiveSheet()->toArray();

        // revert to original locale
        setlocale(LC_CTYPE, $original_locale);

        return $array;
    }
}
