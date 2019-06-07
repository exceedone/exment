<?php
namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\DocumentType;
use Exceedone\Exment\Enums\PluginType;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;
use File;
use Validator;

/**
 * Install Template
 */
class PluginInstaller
{
    /**
     * get template list (get from app folder and vendor/exceedone/exment/templates)
     */
    public static function getTemplates()
    {
        $templates = [];

        foreach (static::getTemplateBasePaths() as $templates_path) {
            $paths = File::glob("$templates_path/*/config.json");
            foreach ($paths as $path) {
                try {
                    $dirname = pathinfo($path)['dirname'];
                    $json = json_decode(File::get($path), true);
                    // add thumbnail
                    if (isset($json['thumbnail'])) {
                        $thumbnail_fullpath = path_join($dirname, $json['thumbnail']);
                        if (File::exists($thumbnail_fullpath)) {
                            $json['thumbnail_fullpath'] = $thumbnail_fullpath;
                        }
                    }
                    array_push($templates, $json);
                } catch (Exception $exception) {
                    //TODO:error handling
                }
            }
        }

        return $templates;
    }

    /**
     * Install template (from display)
     */
    public static function installTemplate($templateName)
    {
        if (!is_array($templateName)) {
            $templateName = [$templateName];
        }
        
        foreach (static::getTemplateBasePaths() as $templates_path) {
            foreach ($templateName as $t) {
                if (!isset($t)) {
                    continue;
                }
                $path = "$templates_path/$t/config.json";
                if (!File::exists($path)) {
                    continue;
                }
                
                static::install($path);
            }
        }
    }


    /**
     * Install System template (from command)
     */
    public static function installSystemTemplate()
    {
        // get vendor folder
        $templates_base_path = base_path() . '/vendor/exceedone/exment/system_template';
        $path = "$templates_base_path/config.json";

        static::install($path, true);
    }

    /**
     * Upload plugin (from display)
     */
    public static function uploadPlugin($uploadFile)
    {
        // store uploaded file and get tmp path
        $tmpdir = getTmpFolderPath('plugin', false);
        $tmpfolderpath = getFullPath(path_join($tmpdir, short_uuid()), Define::DISKNAME_ADMIN_TMP, true);

        $filename = $uploadFile->store($tmpdir, Define::DISKNAME_ADMIN_TMP);
        $fullpath = getFullpath($filename, Define::DISKNAME_ADMIN_TMP);
        // // tmpfolderpath is the folder path uploaded.
        // $tmpfolderpath = path_join(pathinfo($fullpath)['dirname'], pathinfo($fullpath)['filename']);
        $tmpPluginFolderPath = null;

        // open zip file
        $zip = new ZipArchive;
        //Define variable like flag to check exitsed file config (config.json) before extract zip file
        $res = $zip->open($fullpath);
        if ($res !== true) {
            //TODO:error
        }
                
        //Get folder into zip file
        //Check existed file config (config.json)
        $config_path = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $fileInfo = $zip->getNameIndex($i);
            if (basename($zip->statIndex($i)['name']) === 'config.json') {
                $zip->extractTo($tmpfolderpath);
                $config_path = path_join($tmpfolderpath, array_get($stat, 'name'));
                // plugin base path is the root path that has config and php.
                $tmpPluginFolderPath = pathinfo($config_path)['dirname'];
                break;
            }
        }

        //Extract file if $checkExistedConfig = true
        if (isset($config_path)) {
            // get config.json
            $json = json_decode(File::get($config_path), true);

            //If $json nothing, then delete folder extracted, return admin/plugin with error message 'config.jsonファイルが不正です'
            if ($json == null) {
                $response = back()->with('errorMess', exmtrans('common.message.wrongconfig'));
            } else {
                //Validate json file with fields require
                $checkRuleConfig = static::checkRuleConfigFile($json);
                if ($checkRuleConfig) {
                    //Check if the name of the plugin has existed
                    $plugineExistByName = Plugin::getPluginByName(array_get($json, 'plugin_name'));
                    //Check if the uuid of the plugin has existed
                    $plugineExistByUUID = Plugin::getPluginByUUID(array_get($json, 'uuid'));
                    
                    //If json pass validation, prepare data to do continue
                    $plugin = static::prepareData($json);
                    //Make path of folder where contain plugin with name is plugin's name
                    $pluginFolder = $plugin->getFullPath();

                    //If both name and uuid existed, update data for this plugin
                    if (!is_null($plugineExistByName) && !is_null($plugineExistByUUID)) {
                        $pluginUpdated = $plugin->saveOrFail();
                        //Rename folder with plugin name
                        static::copyPluginNameFolder($json, $pluginFolder, $tmpPluginFolderPath);
                        admin_toastr(exmtrans('common.message.success_execute'));
                        $response = back();
                    }
                    //If both name and uuid does not existed, save new record to database, change name folder with plugin name then return success
                    elseif (is_null($plugineExistByName) && is_null($plugineExistByUUID)) {
                        $plugin->save();
                        static::copyPluginNameFolder($json, $pluginFolder, $tmpPluginFolderPath);
                        admin_toastr(exmtrans('common.message.success_execute'));
                        $response = back();
                    }

                    //If name has existed but uuid does not existed, then delete folder and return error with message
                    elseif (!is_null($plugineExistByName) && is_null($plugineExistByUUID)) {
                        $response = back()->with('errorMess', exmtrans('plugin.error.samename_plugin'));
                    }
                    //If uuid has existed but name does not existed, then delete folder and return error with message
                    elseif (is_null($plugineExistByName) && !is_null($plugineExistByUUID)) {
                        $response = back()->with('errorMess', exmtrans('plugin.error.wrongname_plugin'));
                    }
                    //rename folder without Uppercase, space, tab, ...
                    else {
                        $response = back();
                    }
                } else {
                    $response = back()->with('errorMess', exmtrans('common.message.wrongconfig'));
                }
            }
        }
        
        // delete tmp folder
        $zip->close();
        // delete zip
        File::deleteDirectory($tmpfolderpath);
        unlink($fullpath);
        //return response
        if (isset($response)) {
            return $response;
        }
    }
    
    //Function validate config.json file with field required
    protected static function checkRuleConfigFile($json)
    {
        $rules = [
            'plugin_name' => 'required',
            'document_type' => 'in:'.DocumentType::getSelectableString(),
            'plugin_type' => 'required|in:'.PluginType::getRequiredString(),
            'plugin_view_name' => 'required',
            'uuid' => 'required'
        ];

        //If pass validation return true, else return false
        $validator = Validator::make($json, $rules);
        if ($validator->passes()) {
            return true;
        } else {
            return false;
        }
    }

    //Function prepare data to do continue
    protected static function prepareData($json)
    {
        // find or new $plugin
        $plugin = Plugin::firstOrNew(['plugin_name' => array_get($json, 'plugin_name'), 'uuid' => array_get($json, 'uuid')]);

        $plugin_type = PluginType::getEnum(array_get($json, 'plugin_type'));
        $plugin->plugin_type = $plugin_type->getValue() ?? null;
        
        foreach(['plugin_name', 'author', 'version', 'uuid', 'plugin_view_name', 'description'] as $key){
            $plugin->{$key} = array_get($json, $key);
        }
        $plugin->active_flg = $plugin_type != PluginType::BATCH;
        
        // set options
        $options = array_get($plugin, 'options', []);
        // set if exists
        if (array_key_value_exists('target_tables', $json)) {
            $target_tables = array_get($json, 'target_tables');
            // if is_string $target_tables
            if (is_string($target_tables)) {
                $target_tables = [$target_tables];
            }
            $options['target_tables'] = $target_tables;
        }

        foreach(['label', 'icon', 'button_class', 'document_type', 'batch_hour', 'batch_cron'] as $key){
            if (array_key_value_exists($key, $json)) {
                $options[$key] = array_get($json, $key);
            }
        }
        $plugin->options = $options;

        return $plugin;
    }

    //Copy tmp folder to app folder
    protected static function copyPluginNameFolder($json, $pluginFolderPath, $tmpPluginFolderPath)
    {
        if (!File::exists($pluginFolderPath)) {
            File::makeDirectory($pluginFolderPath);
        }
        // copy folder
        File::copyDirectory($tmpPluginFolderPath, $pluginFolderPath);
    }

}
