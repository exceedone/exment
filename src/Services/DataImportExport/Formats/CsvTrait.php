<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use Exceedone\Exment\Model\Define;

trait CsvTrait
{
    public function getFormat(): string
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
                $datalist[$basename] = $this->getCsvArray($csvfile->getRealPath(), $options);
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
            $datalist[$basename] = $this->getCsvArray($path, $options);
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

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     *
     * @return boolean
     */
    protected function isOutputAsZip()
    {
        // check relations
        if (!is_null($this->output_aszip)) {
            return $this->output_aszip;
        }

        // check relations
        return count($this->datalist) > 1;
    }
}
