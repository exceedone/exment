<?php

namespace Exceedone\Exment\Services\Plugin;

use ExmentAdminCore\Admin\Facades\Admin;
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
    // @phpstan-ignore-next-line
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
                // Every call below - numFiles, extractTo, even close - raises a
                // ValueError on an unopened archive, so a corrupt upload used to
                // surface as a stack trace instead of a message.
                $tmpDiskItem->disk()->delete($filename);

                return back()->with('errorMess', exmtrans('error.failure_import_file'));
            }

            // Validate all ZIP entries for path traversal BEFORE extracting.
            // Prevents ZIP Slip: an attacker cannot write files outside $tmpfolderfullpath.
            static::validateZipEntries($zip);

            //Get folder into zip file
            //Check existed file config (config.json)
            $config_path = null;
            // A plugin that ships templates contains more than one config.json
            // (its own, plus one per template folder). The plugin's own file is
            // always the shallowest, so pick that rather than whichever entry
            // the archive happens to list first.
            $statname = null;
            $configDepth = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false || basename($entryName) !== 'config.json') {
                    continue;
                }

                $depth = substr_count(str_replace('\\', '/', $entryName), '/');
                if (isset($configDepth) && $depth >= $configDepth) {
                    continue;
                }

                $configDepth = $depth;
                $statname = $entryName;
            }

            if (isset($statname)) {
                $zip->extractTo($tmpfolderfullpath);

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

    /**
     * Validate all entries in a ZipArchive before extraction.
     *
     * Throws RuntimeException if any entry contains a path traversal sequence ('..')
     * or is an absolute path, preventing ZIP Slip attacks.
     *
     * @param ZipArchive $zip
     * @throws \RuntimeException
     */
    protected static function validateZipEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }
            // Reject path traversal sequences.
            if (strpos($entryName, '..') !== false) {
                throw new \RuntimeException('Invalid ZIP entry detected (path traversal): ' . $entryName);
            }
            // Reject absolute Unix paths ('/...') and Windows drive paths ('C:\...').
            if (substr($entryName, 0, 1) === '/' || (strlen($entryName) > 1 && $entryName[1] === ':')) {
                throw new \RuntimeException('Invalid ZIP entry detected (absolute path): ' . $entryName);
            }
        }
    }

    /**
     * Import the templates shipped inside a plugin.
     *
     * $keepExisting is set when the plugin is already installed. Without it a
     * plugin update overwrites every table, view, form and role the template
     * declares, silently discarding whatever the administrator changed since
     * the first install. On a first install there is nothing to keep, so the
     * template is written as shipped.
     *
     * @param string $pluginFileBasePath
     * @param PluginDiskService $diskService
     * @param array $json plugin config.json
     * @param bool $keepExisting
     * @return bool
     */
    // @phpstan-ignore-next-line
    public static function templateInstall($pluginFileBasePath, PluginDiskService $diskService, array $json, bool $keepExisting = false)
    {
        // If temlates not install, return true
        if (!boolval(array_get($json, "templates"))) {
            return true;
        }

        $tmpDiskItem = $diskService->tmpDiskItem();
        $directories = static::getTemplateDirectories($pluginFileBasePath, $diskService, $tmpDiskItem);

        $importer = new TemplateImportExport\TemplateImporter();

        foreach ($directories as $directory) {
            if (false === $importer->uploadTemplateWithPlugin($tmpDiskItem, $directory, $keepExisting)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Run migrations shipped inside a plugin.
     *
     * Exment templates already create custom tables, so this is only for the
     * rare plugin that needs a real table of its own - typically because it
     * runs aggregate queries that a custom table's JSON value column cannot
     * serve efficiently.
     *
     * Laravel's migration repository makes this idempotent, so reinstalling
     * or updating a plugin re-runs nothing. No new trust is granted here: a
     * plugin archive already contains PHP that Exment executes, so an
     * administrator uploading one is trusting it with the database anyway.
     *
     * @param Plugin $plugin
     * @return bool false when a migration failed
     */
    public static function migrationInstall(Plugin $plugin): bool
    {
        try {
            $dir = path_join($plugin->getLocalFullPath(), 'database', 'migrations');
        } catch (\Throwable $e) {
            return true;
        }

        if (!\File::exists($dir) || empty(\File::glob(path_join($dir, '*.php')))) {
            return true;
        }

        try {
            \Artisan::call('migrate', [
                '--path' => $dir,
                '--realpath' => true,
                '--force' => true,
            ]);
            return true;
        } catch (\Throwable $e) {
            \Log::error('Plugin migration failed for ' . $plugin->plugin_name . ': ' . $e->getMessage());
            return false;
        }
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
    // @phpstan-ignore-next-line
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

    // @phpstan-ignore-next-line
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
            // @phpstan-ignore-next-line
            $checkRuleConfig = static::checkRuleConfigFile($json, $tmpDiskItem, $pluginFileBasePath);
            if ($checkRuleConfig === true) {
                //Check if the name of the plugin has existed
                // @phpstan-ignore-next-line
                $plugineExistByName = Plugin::getPluginByName(array_get($json, 'plugin_name'));
                //Check if the uuid of the plugin has existed
                // @phpstan-ignore-next-line
                $plugineExistByUUID = Plugin::getPluginByUUID(array_get($json, 'uuid'));

                // Already installed: keep whatever the administrator changed
                // since the first install instead of resetting it.
                $isPluginUpdate = !is_null($plugineExistByName) && !is_null($plugineExistByUUID);

                // @phpstan-ignore-next-line
                $templateInstall = static::templateInstall($pluginFileBasePath, $diskService, $json, $isPluginUpdate);
                if ($templateInstall === false) {
                    return back()->with('errorMess', exmtrans('common.message.template_error'));
                }

                //If json pass validation, prepare data to do continue
                // @phpstan-ignore-next-line
                $plugin = static::prepareData($json);
                //Make path of folder where contain plugin with name is plugin's name
                $pluginFolder = $plugin->getPath();
                $diskService->initDiskService($plugin);

                //If both name and uuid existed, update data for this plugin
                if ($isPluginUpdate) {
                    $pluginUpdated = $plugin->saveOrFail();
                    //Rename folder with plugin name
                    // @phpstan-ignore-next-line
                    static::copyPluginNameFolder($plugin, $json, $pluginFolder, $pluginFileBasePath, $diskService);
                    if (false === static::migrationInstall($plugin)) {
                        return back()->with('errorMess', exmtrans('plugin.error.migration_error'));
                    }
                    admin_toastr(exmtrans('common.message.success_execute'));
                    return back();
                }
                //If both name and uuid does not existed, save new record to database, change name folder with plugin name then return success
                elseif (is_null($plugineExistByName) && is_null($plugineExistByUUID)) {
                    $plugin->save();
                    // @phpstan-ignore-next-line
                    static::copyPluginNameFolder($plugin, $json, $pluginFolder, $pluginFileBasePath, $diskService);
                    if (false === static::migrationInstall($plugin)) {
                        return back()->with('errorMess', exmtrans('plugin.error.migration_error'));
                    }
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
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    protected static function copyPluginNameFolder($plugin, $json, $pluginFolderPath, $pluginFileBasepath, $diskService)
    {
        // get all files
        $files = $diskService->tmpDiskItem()->disk()->allFiles($pluginFileBasepath);

        $filelist = collect($files)->mapWithKeys(function ($file) use ($pluginFolderPath, $pluginFileBasepath) {
            // get moved file name
            $movedFileName = str_replace($pluginFileBasepath, '', $file);
            $movedFileName = str_replace(\Exment::replaceBackToSlash($pluginFileBasepath), '', $movedFileName);
            // @phpstan-ignore-next-line
            $movedFileName = trim($movedFileName, '/');
            $movedFileName = trim($movedFileName, '\\');

            return [$file => path_join($pluginFolderPath, $movedFileName)];
        })->filter();

        $diskService->upload($filelist->toArray());
    }
}
