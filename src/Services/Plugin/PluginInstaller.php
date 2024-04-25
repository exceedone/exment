<?php

namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Storage\Disk\PluginDiskService;
use Exceedone\Exment\Validator\PluginTypeRule;
use Exceedone\Exment\Validator\PluginNamespaceRule;
use Exceedone\Exment\Validator\PluginRequirementRule;
use Exceedone\Exment\Services\TemplateImportExport;
use Exceedone\Exment\Storage\Disk\DiskServiceItem;
use ZipArchive;
use File;
use Validator;

/**
 * Plugin Installer
 */
class PluginInstaller
{
    /**
     * Upload plugin (call from display)
     */
    public static function uploadPlugin($uploadFile)
    {
        try {
            $diskService = new PluginDiskService();
            $tmpDiskItem = $diskService->tmpDiskItem();

            // store uploaded file and get tmp path
            $tmpdir = $tmpDiskItem->dirName();
            // $tmpfolderpath = path_join($tmpdir, short_uuid());
            $tmpfolderfullpath = $tmpDiskItem->dirFullPath();
            $pluginFileBasePath = null;

            // store file
            $filename = $tmpDiskItem->disk()->put($tmpdir, $uploadFile);
            $fullpath = $tmpDiskItem->disk()->path($filename);

            // open zip file
            $zip = new ZipArchive();
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
                if (basename($zip->statIndex($i)['name']) !== 'config.json') {
                    continue;
                }

                $zip->extractTo($tmpfolderfullpath);

                // get confign statname
                $statname = array_get($stat, 'name');
                $config_path = path_join($tmpfolderfullpath, $statname);

                // get dirname
                $dirname = pathinfo($statname)['dirname'];

                // if dirname is '.', $pluginFileBasePath is $tmpfolderpath
                if ($dirname == '.') {
                    $pluginFileBasePath = $tmpdir;
                }
                // else, $pluginFileBasePath is join $dirname
                else {
                    $pluginFileBasePath = path_join($tmpdir, $dirname);
                }
                break;
            }

            // remove zip
            if (!is_nullorempty($zip)) {
                $zip->close();
            }
            // delete zip
            $tmpDiskItem->disk()->delete($filename);

            //Extract file if $checkExistedConfig = true
            if (isset($config_path)) {
                $response = static::copySavePlugin($config_path, $pluginFileBasePath, $diskService);
            }
            //return response
            if (isset($response)) {
                return $response;
            }
        } catch (\Exception $ex) {
            throw $ex;
        } finally {

            // delete zip
            if (isset($diskService)) {
                $diskService->deleteTmpDirectory();
            }
        }
    }

    public static function templateInstall($pluginFileBasePath, PluginDiskService $diskService, array $json)
    {
        // If temlates not install, return true
        if (!boolval(array_get($json, "templates"))) {
            return true;
        }

        $tmpDiskItem = $diskService->tmpDiskItem();
        $directories = static::getTemplateDirectories($pluginFileBasePath, $diskService, $tmpDiskItem);

        $importer = new TemplateImportExport\TemplateImporter();

        foreach ($directories as $directory) {
            if (false === $importer->uploadTemplateWithPlugin($tmpDiskItem, $directory)) {
                return false;
            }
        }
        return true;
    }

    /**
     * GetTemplateDirectories.
     * Support these paths.
     * (1)templates
     *        config.json
     *        lang
     * (2)templates
     *            template1
     *                config.json
     *                lang
     *            template2
     *                config.json
     *                lang
     * @param string  $pluginFileBasePath
     * @param PluginDiskService $diskService
     * @return array
     */
    protected static function getTemplateDirectories(string $pluginFileBasePath, PluginDiskService $diskService, $tmpDiskItem): array
    {
        $result = [];
        $checkFunc = function ($directory, &$result) use ($tmpDiskItem) {
            $config_path = path_join($directory, "config.json");
            if ($tmpDiskItem->disk()->exists($config_path)) {
                $result[] = $directory;
            }
        };

        // check current dir
        $checkFunc("$pluginFileBasePath/templates", $result);

        $directories = $tmpDiskItem->disk()->directories("$pluginFileBasePath/templates");
        foreach ($directories as $directory) {
            $checkFunc($directory, $result);

            // // get sub directory
            // $subDirectories = $tmpDiskItem->disk()->directories($directory);
            // foreach($subDirectories as $subDirectory){
            //     $checkFunc($subDirectory, $result);
            // }
        }

        return $result;
    }

    public static function copySavePlugin($config_path, $pluginFileBasePath, ?PluginDiskService $diskService = null)
    {
        if (!$diskService) {
            $diskService = new PluginDiskService();
        }
        $tmpDiskItem = $diskService->tmpDiskItem();

        // get config.json
        $json = json_decode_ex(File::get($config_path), true);

        //If $json nothing, then delete folder extracted, return admin/plugin with error message 'config.json wrong'
        if ($json == null) {
            return back()->with('errorMess', exmtrans('common.message.wrongconfig'));
        } else {
            //Validate json file with fields require
            $checkRuleConfig = static::checkRuleConfigFile($json, $tmpDiskItem, $pluginFileBasePath);
            if ($checkRuleConfig === true) {
                $templateInstall = static::templateInstall($pluginFileBasePath, $diskService, $json);
                if ($templateInstall === false) {
                    return back()->with('errorMess', exmtrans('common.message.template_error'));
                }
                //Check if the name of the plugin has existed
                $plugineExistByName = Plugin::getPluginByName(array_get($json, 'plugin_name'));
                //Check if the uuid of the plugin has existed
                $plugineExistByUUID = Plugin::getPluginByUUID(array_get($json, 'uuid'));

                //If json pass validation, prepare data to do continue
                $plugin = static::prepareData($json);
                //Make path of folder where contain plugin with name is plugin's name
                $pluginFolder = $plugin->getPath();
                $diskService->initDiskService($plugin);

                //If both name and uuid existed, update data for this plugin
                if (!is_null($plugineExistByName) && !is_null($plugineExistByUUID)) {
                    $pluginUpdated = $plugin->saveOrFail();
                    //Rename folder with plugin name
                    static::copyPluginNameFolder($plugin, $json, $pluginFolder, $pluginFileBasePath, $diskService);
                    admin_toastr(exmtrans('common.message.success_execute'));
                    return back();
                }
                //If both name and uuid does not existed, save new record to database, change name folder with plugin name then return success
                elseif (is_null($plugineExistByName) && is_null($plugineExistByUUID)) {
                    $plugin->save();
                    static::copyPluginNameFolder($plugin, $json, $pluginFolder, $pluginFileBasePath, $diskService);
                    admin_toastr(exmtrans('common.message.success_execute'));
                    return back();
                }

                //If name has existed but uuid does not existed, then delete folder and return error with message
                elseif (!is_null($plugineExistByName) && is_null($plugineExistByUUID)) {
                    return back()->with('errorMess', exmtrans('plugin.error.samename_plugin'));
                }
                //If uuid has existed but name does not existed, then delete folder and return error with message
                elseif (is_null($plugineExistByName) && !is_null($plugineExistByUUID)) {
                    return back()->with('errorMess', exmtrans('plugin.error.wrongname_plugin'));
                }
                //rename folder without Uppercase, space, tab, ...
                else {
                    return back();
                }
            } else {
                return back()->with('errorMess', $checkRuleConfig);
            }
        }
    }

    /**
     * Function validate config.json file with field required
     *
     * @param array $json
     * @param DiskServiceItem $tmpDiskItem
     * @return bool|string
     */
    protected static function checkRuleConfigFile($json, DiskServiceItem $tmpDiskItem, string $pluginFileBasePath)
    {
        $rules = [
            'plugin_name' => ['required', new PluginNamespaceRule($tmpDiskItem, $pluginFileBasePath)],
            'plugin_type' => new PluginTypeRule(),
            'plugin_view_name' => 'required',
            'uuid' => 'required',
            'requirement' => new PluginRequirementRule(),
        ];

        //If pass validation return true, else return false
        $validator = Validator::make($json, $rules);
        if ($validator->passes()) {
            return true;
        } else {
            $messages = collect($validator->errors()->messages());
            $message = $messages->map(function ($message) {
                return $message[0];
            });
            return implode("\r\n", $message->values()->toArray());
        }
    }

    /**
     * Function prepare data to do continue
     *
     * @param array $json
     * @return Plugin plugin object
     */
    protected static function prepareData($json)
    {
        // find or new $plugin
        $plugin = Plugin::firstOrNew(['plugin_name' => array_get($json, 'plugin_name'), 'uuid' => array_get($json, 'uuid')]);

        $plugin_type = array_get($json, 'plugin_type');
        $plugin->plugin_types = $plugin_type;

        foreach (['plugin_name', 'author', 'version', 'uuid', 'plugin_view_name', 'description'] as $key) {
            $plugin->{$key} = array_get($json, $key);
        }
        $plugin->active_flg = PluginType::getEnum($plugin_type) != PluginType::BATCH;

        // set options
        $options = array_get($plugin, 'options', []);
        // set if exists
        foreach (['target_tables', 'export_types', 'event_triggers'] as $key) {
            if (array_key_value_exists($key, $json)) {
                $jsonval = array_get($json, $key);
                $options[$key] = stringToArray($jsonval);
            }
        }

        foreach (['all_user_enabled', 'label', 'icon', 'button_class', 'document_type', 'event_triggers', 'batch_hour', 'batch_cron', 'cdns', 'uri', 'export_description', 'command_only'] as $key) {
            if (array_key_value_exists($key, $json)) {
                $options[$key] = array_get($json, $key);
            }
        }

        // if page and 'uri' is empty, set snake_case plugin_name
        if ($plugin->isPluginTypeUri() && !array_has($options, 'uri')) {
            $options['uri'] = snake_case(array_get($json, 'plugin_name'));
        }

        $plugin->options = $options;

        return $plugin;
    }

    /**
     * Copy tmp folder to app folder
     *
     * @param Plugin $plugin
     * @param array $json
     * @param string $pluginFolderPath
     * @param string $pluginFileBasepath
     * @return void
     */
    protected static function copyPluginNameFolder($plugin, $json, $pluginFolderPath, $pluginFileBasepath, $diskService)
    {
        // get all files
        $files = $diskService->tmpDiskItem()->disk()->allFiles($pluginFileBasepath);

        $filelist = collect($files)->mapWithKeys(function ($file) use ($pluginFolderPath, $pluginFileBasepath) {
            // get moved file name
            $movedFileName = str_replace($pluginFileBasepath, '', $file);
            $movedFileName = str_replace(\Exment::replaceBackToSlash($pluginFileBasepath), '', $movedFileName);
            $movedFileName = trim($movedFileName, '/');
            $movedFileName = trim($movedFileName, '\\');

            return [$file => path_join($pluginFolderPath, $movedFileName)];
        })->filter();

        $diskService->upload($filelist->toArray());
    }
}
