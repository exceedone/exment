<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\DataImportExport\Formats\CsvTrait;
use File;

class Csv extends PhpSpreadSheet
{
    use CsvTrait;

    protected $accept_extension = 'csv,zip';


    protected function _getData($request, $callbackZip, $callbackDefault)
    {
        // get file
        list($path, $extension, $originalName, $file) = $this->getFileInfo($request);

        // if zip, extract
        if ($extension == 'zip' && isset($file)) {
            $tmpdir = \Exment::getTmpFolderPath('data', false);
            $tmpfolderpath = getFullPath(path_join($tmpdir, short_uuid()), Define::DISKNAME_ADMIN_TMP, true);

            $filename = $file->store($tmpdir, Define::DISKNAME_ADMIN_TMP);
            $fullpath = getFullpath($filename, Define::DISKNAME_ADMIN_TMP);

            // open zip file
            try {
                $zip = new \ZipArchive();
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


    protected function createWriter($spreadsheet)
    {
        /** @var \PhpOffice\PhpSpreadsheet\Writer\Csv $writer */
        $writer = IOFactory::createWriter($spreadsheet, 'Csv');
        // append bom if config
        if (boolval(config('exment.export_append_csv_bom', false))) {
            $writer->setUseBOM(true);
        }
        return $writer;
    }

    protected function createReader()
    {
        return IOFactory::createReader('Csv');
    }

    /**
     * Get all csv's row count
     *
     * @param string|array|\Illuminate\Support\Collection $files
     * @return int
     */
    protected function getRowCount($files): int
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

    protected function getCsvArray($file, array $options = [])
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
        $array = $this->getDataFromSheet($spreadsheet->getActiveSheet(), false, false, $options);

        // revert to original locale
        setlocale(LC_CTYPE, $original_locale);

        return $array;
    }
}
