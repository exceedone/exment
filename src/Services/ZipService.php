<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\File as ExmentFile;
use ZipArchive;

/**
 * Zip Service, set password
 */
class ZipService
{    
    /**
     * Create Password zip.
     * encrypt is ZipCrypto
     *
     * @param array<int, string> $files
     * @param string $zipFullPath
     * @param string $tmpFolderPath
     * @param string $password
     * @param string|null $disk
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

    /**
     * @param string $zipFullPath
     * @param string $tmpFolderPath
     * @param string $password
     * @return void
     * @throws \Exception
     */
    protected static function execPasswordZipWin($zipFullPath, $tmpFolderPath, $password)
    {
        if ($tmpFolderPath == '/' || $tmpFolderPath == '') {
            throw new \Exception();
        }

        $output = [];
        $dir7zip = path_join(config('exment.7zip_dir'), '7z.exe');
        // escapeshellarg() handles quoting and escaping for all dynamic arguments.
        exec(
            escapeshellarg($dir7zip) . ' a -p' . escapeshellarg($password)
            . ' ' . escapeshellarg($zipFullPath)
            . ' ' . escapeshellarg($tmpFolderPath . '/*'),
            $output
        );
    }

    /**
     * @param string $zipFullPath
     * @param string $tmpFolderPath
     * @param string $password
     * @return void
     * @throws \Exception
     */
    protected static function execPasswordZipLinux($zipFullPath, $tmpFolderPath, $password)
    {
        if ($tmpFolderPath == '/' || $tmpFolderPath == '') {
            throw new \Exception();
        }

        // escapeshellarg() wraps each argument in single quotes, preventing shell
        // metacharacter expansion even when paths contain spaces or special chars.
        $cmd = sprintf(
            '(cd %s && zip -e --password=%s %s ./*)',
            escapeshellarg($tmpFolderPath),
            escapeshellarg($password),
            escapeshellarg($zipFullPath)
        );

        exec($cmd);
    }

    /**
     * Validate ZIP entry names before extraction.
     *
     * @param ZipArchive $zip
     * @return bool|string
     */
    public static function validateZipEntries(ZipArchive $zip)
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            if (strpos($entryName, "\0") !== false || static::isUnsafeZipEntryName($entryName)) {
                return exmtrans('error.invalid_zip_entry', ['entry' => $entryName]);
            }
        }

        return true;
    }

    /**
     * @param string $entryName
     * @return bool
     */
    public static function isUnsafeZipEntryName(string $entryName): bool
    {
        if (preg_match('/^[a-zA-Z]:[\/\\\\]?/', $entryName)) {
            return true;
        }

        if (strpos($entryName, '/') === 0 || strpos($entryName, '\\') === 0) {
            return true;
        }

        $normalized = str_replace('\\', '/', $entryName);
        if (strpos($normalized, '//') === 0) {
            return true;
        }

        return collect(explode('/', $normalized))->contains(function ($segment) {
            return $segment === '..';
        });
    }

}
