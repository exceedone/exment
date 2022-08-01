<?php

namespace Exceedone\Exment\Console;

use File;
use Exceedone\Exment\Model\CustomTable;

trait ImportTrait
{
    /**
     * get file names in target folder (filter extension)
     *
     */
    private function getFiles($ext = 'tsv', $include_sub = false)
    {
        // get files in target folder
        if ($include_sub) {
            $files = File::allFiles($this->directory);
        } else {
            $files = File::files($this->directory);
        }
        // filter files by extension
        $files = array_filter($files, function ($file) use ($ext) {
            // continue prefix '~' file
            if (strpos($file, '~') !== false) {
                return false;
            }

            $exts = stringToArray($ext);
            foreach ($exts as $e) {
                if (preg_match('/.+\.'.$e.'$/i', $file)) {
                    return true;
                }
            }

            return false;
        });
        return $files;
    }


    /**
     * Get table from file name.
     * Support such as:
     *     information.csv
     *     information#001.csv
     *     information.001.csv
     *
     * @param string  $file_name
     * @return CustomTable|null
     */
    protected function getTableFromFile(string $file_name): ?CustomTable
    {
        $table_name = file_ext_strip($file_name);
        // directry same name
        if (!is_null($custom_table = CustomTable::getEloquent($table_name))) {
            return $custom_table;
        }

        // If contains "#" in file name, throw exception
        if (strpos($table_name, '#') !== false) {
            throw new \Exception('File name that conatains "#" not supported over v3.8.0.');
        }

        // loop for regex
        $regexes = ['(?<table_name>.+)\\.\d+', '\d+\\.(?<table_name>.+)'];
        foreach ($regexes as $regex) {
            $match_num = preg_match('/' . $regex . '/u', $table_name, $matches);
            if ($match_num > 0 && !is_null($custom_table = CustomTable::getEloquent($matches['table_name']))) {
                return $custom_table;
            }
        }

        return null;
    }
}
