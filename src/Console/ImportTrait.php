<?php

namespace Exceedone\Exment\Console;

use \File;

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
            $exts = stringToArray($ext);
            foreach($exts as $e){
                if(preg_match('/.+\.'.$e.'$/i', $file)){
                    return true;
                }
            }

            return false;
        });
        return $files;
    }
}
