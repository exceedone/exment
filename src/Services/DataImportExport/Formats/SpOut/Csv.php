<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\SpOut;

use Exceedone\Exment\Model\Define;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use \File;

class Csv extends SpOut
{
    protected $accept_extension = 'csv,zip';

    public function getFormat() : string
    {
        return 'csv';
    }

    
    public function getDataTable($request, array $options = [])
    {
        $options = $this->getDataOptions($options);
        return $this->_getData($request, function ($files) use ($options) {
            // if over row size, return number
            if (boolval($options['checkCount'])) {
                if (($count = $this->getRowCount($files)) > (config('exment.import_max_row_count', 1000) + 2)) {
                    return $count;
                }
            }

            $datalist = [];
            foreach ($files as $csvfile) {
                $basename = $csvfile->getBasename('.csv');
                $datalist[$basename] = $this->getCsvArray($csvfile->getRealPath());
            }

            return $datalist;
        }, function ($path) use ($options) {
            // if over row size, return number
            if (boolval($options['checkCount'])) {
                if (($count = $this->getRowCount($path)) > (config('exment.import_max_row_count', 1000) + 2)) {
                    return $count;
                }
            }

            $basename = $this->filebasename;
            $datalist[$basename] = $this->getCsvArray($path);
            return $datalist;
        });
    }
    
    public function getDataCount($request)
    {
        return $this->_getData($request, function ($files) {
            return $this->getRowCount($files);
        }, function ($path) {
            return $this->getRowCount($path);
        });
    }

    protected function _getData($request, $callbackZip, $callbackDefault)
    {
        // get file
        list($path, $extension, $originalName) = $this->getFileInfo($request);

        // if zip, extract
        if ($extension == 'zip' && isset($file)) {
            $tmpdir = \Exment::getTmpFolderPath('data', false);
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

                return $callbackZip($files);
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
            return $callbackDefault($path);
        }
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
        if(!is_null($this->output_aszip)){
            return $this->output_aszip;
        }
        return count($this->datalist) > 1;
    }


    /**
     * Get all csv's row count
     *
     * @param string|array|\Illuminate\Support\Collection $files
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
        $reader->setEncoding('UTF-8');
        $reader->setFieldDelimiter(",");
        $reader->open($file);

        $array = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                // do stuff with the row
                $cells = $row->getCells();
                $array[] = collect($cells)->map(function($cell) use($sheet){
                    return $this->getCellValue($cell, $sheet);
                })->toArray();
            }

            // only get first row.
            break;
        }

        // revert to original locale
        setlocale(LC_CTYPE, $original_locale);

        return $array;
    }

    
    /**
     * @return \Box\Spout\Writer\CSV\Writer
     */
    protected function createWriter($spreadsheet)
    {
        return WriterEntityFactory::createCSVWriter();
    }

    
    /**
     * @return \Box\Spout\Reader\CSV\Reader
     */
    protected function createReader()
    {
        return ReaderEntityFactory::createCSVReader();
    }
}
