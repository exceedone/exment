<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\File as ExmentFile;

/**
 * Zip Service, set password
 */
class ZipService
{
    /**
     * Create Password zip.
     * encrypt is ZipCrypto
     *
     * @return void
     */
    public static function createPasswordZip($files, $zipFullPath, $tmpFolderPath, $password, ?string $disk = null)
    {
        \Exment::makeDirectory($tmpFolderPath);
        foreach ($files as $file) {
            $tmpfile = pathinfo($file)['basename'];
            if (empty($tmpfile) || $tmpfile == '.' || $tmpfile == '..') {
                continue;
            }

            // get file info from database
            $dbFile = ExmentFile::where('local_filename', $tmpfile)->first();
            if (isset($dbFile)) {
                $tmpfile = $dbFile->filename;
            }

            // If has $disk, copy using disk
            if (!is_nullorempty($disk)) {
                $f = \Storage::disk($disk)->get($file);
                \File::put(path_join($tmpFolderPath, $tmpfile), $f);
            } else {
                \File::copy($file, path_join($tmpFolderPath, $tmpfile));
            }
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            static::execPasswordZipWin($zipFullPath, $tmpFolderPath, $password);
        } else {
            static::execPasswordZipLinux($zipFullPath, $tmpFolderPath, $password);
        }

        if (\File::exists($tmpFolderPath)) {
            \File::deleteDirectory($tmpFolderPath);
        }
    }

    protected static function execPasswordZipWin($zipFullPath, $tmpFolderPath, $password)
    {
        if ($tmpFolderPath == '/' || $tmpFolderPath == '') {
            throw new \Exception();
        }

        $output = [];
        $dir7zip = path_join(config('exment.7zip_dir'), '7z.exe');
        exec('"' . $dir7zip . '" a -p' . $password . ' "' . $zipFullPath . '" "' . $tmpFolderPath . '/*"', $output);
    }

    protected static function execPasswordZipLinux($zipFullPath, $tmpFolderPath, $password)
    {
        if ($tmpFolderPath == '/' || $tmpFolderPath == '') {
            throw new \Exception();
        }

        $output = [];
        $cmd = '(cd ' . $tmpFolderPath . ' && zip -e --password=' . $password . ' ' . $zipFullPath . ' ./*)';

        exec($cmd);
    }
}
