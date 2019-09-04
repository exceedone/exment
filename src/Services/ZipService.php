<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Widgets\ModalInnerForm;
use Exceedone\Exment\Notifications;

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
    public static function createPasswordZip($files, $zipFullPath, $tmpFolderPath, $password)
    {
        if(!\File::exists($tmpFolderPath)){
            \File::makeDirectory($tmpFolderPath);
        }

        foreach($files as $file){
            $tmpfile = pathinfo($file)['basename'];
            if(empty($tmpfile) || $tmpfile == '.' || $tmpfile == '..'){
                continue;
            }
            \File::copy($file, $tmpFolderPath . '\\' . $tmpfile);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            static::execPasswordZipWin($zipFullPath, $tmpFolderPath, $password);
        }else{
            static::execPasswordZipLinux($zipFullPath, $tmpFolderPath, $password);
        }
        
        if(\File::exists($tmpFolderPath)){
            \File::deleteDirectory($tmpFolderPath);
        }
    }

    protected static function execPasswordZipWin($zipFullPath, $tmpFolderPath, $password){
        $output = [];
        $dir7zip = path_join(config('exment.7zip_dir'), '7z.exe');
        exec('"' . $dir7zip . '" a -p' . $password . ' "' . $zipFullPath . '" "' . $tmpFolderPath . '"', $output);
    }

    protected static function execPasswordZipLinux($zipFullPath, $tmpFolderPath, $password){
        $output = [];
        exec('zip -P "' . $password . '" "' . $zipFullPath . '" "' . $tmpFolderPath . '"');
    }
}
