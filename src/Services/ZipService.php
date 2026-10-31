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
        exec('"' . $dir7zip . '" a -p' . $password . ' "' . $zipFullPath . '" "' . $tmpFolderPath . '/*"', $output);
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

        $output = [];
        // quote every path: both come from the install directory, and an install path
        // carrying a space used to cut the command in half.
        $cmd = sprintf(
            '(cd %s && zip -e --password=%s %s ./*)',
            escapeshellarg($tmpFolderPath),
            escapeshellarg($password),
            escapeshellarg($zipFullPath)
        );

        exec($cmd, $output, $returnVar);
        if ($returnVar !== 0) {
            // the mail would go out with a missing attachment otherwise, and nothing
            // anywhere would say why. do not throw: that would stop the whole notify run.
            \Log::warning('Exment: password zip failed. exit code: ' . $returnVar);
        }
    }

    /**
     * Check every entry name of an opened zip before extracting it.
     *
     * ZipArchive::extractTo() already forces each entry back inside the target directory,
     * so this is a second line of defence. It matters most for the code that reuses the
     * raw entry name as a path AFTER extraction, where no such normalization happens.
     *
     * The rejected name goes to the log only, never into the returned message: some of
     * the views that print this message still render with {!! !!}, so an attacker
     * controlled entry name must not travel with it.
     *
     * @param ZipArchive $zip opened archive
     * @return string|null error message, or null when every entry is safe
     */
    public static function validateZipEntries(ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            if (strpos($entryName, "\0") !== false || static::isUnsafeZipEntryName($entryName)) {
                // drop control characters so one entry name cannot forge extra log lines
                $loggedName = strval(preg_replace('/[[:cntrl:]]/', '', $entryName));
                \Log::warning('Exment: rejected zip entry: ' . $loggedName);

                return strval(exmtrans('error.invalid_zip_entry'));
            }
        }

        return null;
    }

    /**
     * Does this zip entry name point anywhere but below the extract directory?
     *
     * @param string $entryName
     * @return bool
     */
    public static function isUnsafeZipEntryName(string $entryName): bool
    {
        // windows drive letter: "C:" , "C:/" , "C:\"
        if (preg_match('/^[a-zA-Z]:[\/\\\\]?/', $entryName)) {
            return true;
        }

        // absolute path
        if (strpos($entryName, '/') === 0 || strpos($entryName, '\\') === 0) {
            return true;
        }

        $normalized = str_replace('\\', '/', $entryName);

        // UNC path: "\\host\share"
        if (strpos($normalized, '//') === 0) {
            return true;
        }

        // ".." as a whole segment. "my..file.txt" is a normal name and stays allowed.
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }
}
