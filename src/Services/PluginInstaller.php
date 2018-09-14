<?php
namespace Exceedone\Exment\Services;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // $plugin = new Plugin();
        // $file = $request->file('fileUpload');
        // //Get file name with extension
        // $fileFullName = $file->getClientOriginalName();
        // //Get file name without extension
        // $fileNameOnly = basename($request->file('fileUpload')->getClientOriginalName(), '.'.$request->file('fileUpload')->getClientOriginalExtension());

        // store uploaded file and get tmp path
        $filename = $uploadFile->store('upload_tmp', 'local');
        $fullpath = getFullpath($filename, 'local');
        // tmpfolderpath is the folder path uploaded. 
        $tmpfolderpath = path_join(pathinfo($fullpath)['dirname'], pathinfo($fullpath)['filename']);
        $tmpPluginFolderPath = null;

        //Folder to move file uploaded
        $pluginBasePath = path_join(app_path(), 'Plugins');
        // $file->move($appPath.$pluginBasePath, $fileFullName);
        // //Get file's path with full name (include extionsion of file)
        // $fullpath = $appPath.$pluginBasePath.$fileFullName;
        // //Get file's path with name only (folder file)
        // $shortPath = $appPath.$pluginBasePath.$fileNameOnly;

        $plugin = new Plugin();
        $zip = new ZipArchive;
        //Define variable like flag to check exitsed file config (config.json) before extract zip file
        $res = $zip->open($fullpath);
        if ($res !== true) {
            //TODO:error
        }
                
        //Get folder into zip file
        $folderAfterExtract = trim($zip->getNameIndex(0), '/');

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

            // $zip->extractTo($appPath.$pluginBasePath);
            // $zip->close();
            // //Delete zip file after extract
            // unlink($fullPath);
                    
            // //Check folder name after extract, if named folder is different than name of zip file so rename to name of this file
            // if ($folderAfterExtract !== null && $folderAfterExtract !== $fileNameOnly && strpos($folderAfterExtract, '/') === false) {
            //     rename($appPath.$pluginBasePath.$folderAfterExtract, $appPath.$pluginBasePath.$fileNameOnly);
            // }
            // //Decode config file to get infomation of plugin uploaded
            // $json = json_decode(file_get_contents($shortPath . '/config.json'), true);

            //If $json nothing, then delete folder extracted, return admin/plugin with error message 'config.jsonファイルが不正です'
            if ($json == null) {
                File::deleteDirectory($shortPath);
                return back()->with('errorMess', 'config.jsonファイルが不正です');
            }
            //Validate json file with fields require
            $checkRuleConfig = static::checkRuleConfigFile($json);
            if ($checkRuleConfig) {
                //If json pass validation, prepare data to do continue
                $plugin = static::prepareData($json, $plugin);
                //Check if the name of the plugin has existed
                $plugineExistByName = static::checkPluginNameExisted($plugin->plugin_name);
                //Check if the uuid of the plugin has existed
                $plugineExistByUUID = static::checkPluginUUIDExisted($plugin->uuid);
                //Make path of folder where contain plugin with name is plugin's name
                $pluginFolder = path_join($pluginBasePath, strtolower(preg_replace('/\s+/', '', $json['plugin_name'])));
                //If both name and uuid existed, update data for this plugin
                if ($plugineExistByName > 0 && $plugineExistByUUID > 0) {
                    $pluginUpdated = static::updateExistedPlugin($plugin);
                    if ($pluginUpdated) {
                        //Rename folder with plugin name
                        static::copyPluginNameFolder($json, $pluginFolder, $tmpPluginFolderPath);
                        admin_toastr('アップロードに成功しました');
                        return back();
                    }
                }
                //If both name and uuid does not existed, save new record to database, change name folder with plugin name then return success
                if ($plugineExistByName <= 0 && $plugineExistByUUID <= 0) {
                    $plugin->save();
                    static::copyPluginNameFolder($json, $pluginFolder, $tmpPluginFolderPath);
                    admin_toastr('アップロードに成功しました');
                    return back();
                }

                //If name has existed but uuid does not existed, then delete folder and return error with message
                if ($plugineExistByName > 0 && $plugineExistByUUID <= 0) {
                    File::deleteDirectory($shortPath);
                    return back()->with('errorMess', '同名プラグインが存在します。確認してから一度お試してください。');
                }
                //If uuid has existed but name does not existed, then delete folder and return error with message
                if ($plugineExistByName <= 0 && $plugineExistByUUID > 0) {
                    File::deleteDirectory($shortPath);
                    return back()->with('errorMess', 'UUIDは存在しますが、プラグイン名が正しくありません。 確認してからもう一度お試しください。');
                }
                //rename folder without Uppercase, space, tab, ...
            } else {
                File::deleteDirectory($shortPath);
                return back()->with('errorMess', 'config.jsonファイルが不正です');
            }
            //return error if plugin existed
        }

        $zip->close();
        unlink($fullPath);
    }
    
    //Function validate config.json file with field required
    protected static function checkRuleConfigFile($json)
    {
        $rules = [
            'plugin_name' => 'required',
            'plugin_type' => 'required|in:trigger,page,dashboard,batch',
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
    protected static function prepareData($json, $plugin)
    {
        $plugin->plugin_name = $json['plugin_name'];
        $plugin->plugin_type = $json['plugin_type'];
        $plugin->author = $json['author'] ?? '';
        $plugin->version = $json['version'] ?? '';
        $plugin->uuid = $json['uuid'];
        $plugin->plugin_view_name = $json['plugin_view_name'];
        $plugin->description = $json['description'] ?? '';
        $plugin->active_flg = true;

        return $plugin;
    }

    //Check existed plugin name
    protected static function checkPluginNameExisted($name)
    {
        return Plugin
            ::where('plugin_name', '=', $name)
            ->count();
    }

    //Check existed plugin uuid
    protected static function checkPluginUUIDExisted($uuid)
    {
        return Plugin
            ::where('uuid', '=', $uuid)
            ->count();
    }

    //Get plugin by custom_table id
    //Where active_flg = 1 and target_tables contains custom_table id
    /**
     * @param $id
     * @return mixed
     */
    public static function getPluginByTableId($id)
    {
        return Plugin::where('plugin_type', 'trigger')
            ->where('active_flg', '=', 1)
            ->where('options->target_tables', $id)
            //->whereRaw('JSON_CONTAINS(options, \'{"target_tables": "'.$id.'"}\')')
            ->get()
            ;
    }

    //Update record if existed both name and uuid
    protected static function updateExistedPlugin($plugin)
    {
        $pluginUpdate = Plugin
            ::where('plugin_name', '=', $plugin->plugin_name)
            ->where('uuid', '=', $plugin->uuid)
            ->update(['author' => $plugin->author, 'version' => $plugin->version, 'plugin_type' => $plugin->plugin_type, 'description' => $plugin->description, 'plugin_view_name' => $plugin->plugin_view_name]);
        if ($pluginUpdate >= 0) {
            return true;
        }
        return false;
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

    //Delete record from database (one or multi records)
    protected static function destroy($id)
    {
        static::deleteFolder($id);
        if (static::form()->destroy($id)) {
            return response()->json([
                'status' => true,
                'message' => trans('admin.delete_succeeded'),
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => trans('admin.delete_failed'),
            ]);
        }
    }

    //Delete one or multi folder corresponds to the plugins
    protected static function deleteFolder($id)
    {
        $arrPlugin = array();
        $appPath = app_path();
        if (strpos($id, ',') !== false) {
            $arrPlugin = explode(',', $id);
            foreach ($arrPlugin as $item) {
                $plugin = DB::table('plugins')
                    ->where('id', '=', $item)
                    ->first();
                $pluginFolder = $appPath . '/plugins/' . strtolower(preg_replace('/\s+/', '', $plugin->plugin_name));
                if (File::isDirectory($pluginFolder)) {
                    File::deleteDirectory($pluginFolder);
                }
            }
        } else {
            $plugin = DB::table('plugins')
                ->where('id', '=', $id)
                ->first();
            $pluginFolder = $appPath . '/plugins/' . strtolower(preg_replace('/\s+/', '', $plugin->plugin_name));
            if (File::isDirectory($pluginFolder)) {
                File::deleteDirectory($pluginFolder);
            }
        }
    }

    //Check request when edit record to delete null values in event_triggers
    public static function update(Request $request, $id)
    {
        if (isset($request->get('options')['event_triggers']) === true) {
            $event_triggers = $request->get('options')['event_triggers'];
            $options = $request->get('options');
            $event_triggers = array_filter($event_triggers, 'strlen');
            $options['event_triggers'] = $event_triggers;
            $request->merge(['options' => $options]);
        }
        return static::form()->update($id);
    }

    public static function route($plugin, $json) {
        $namespace = $plugin->getNameSpace();
        Route::group([
            'prefix'        => config('admin.route.prefix').'/plugins',
            'namespace'     => $namespace,
            'middleware'    => config('admin.route.middleware'),
            'module'        => $namespace,
        ], function (Router $router) use ($plugin, $namespace, $json) {
            foreach($json['route'] as $route){
                $methods = is_string($route['method']) ? [$route['method']] : $route['method'];
                foreach($methods as $method){
                    if($method === ""){
                        $method = 'get';
                    }
                    $method = strtolower($method);
                    // call method in these http method
                    if(in_array($method, ['get', 'post', 'put', 'patch', 'delete'])){
                        //Route::{$method}(path_join(array_get($plugin->options, 'uri'), $route['uri']), $json['controller'].'@'.$route['function'].'');
                        Route::{$method}(url_join(array_get($plugin->options, 'uri'), $route['uri']), 'Office365UserController@'.$route['function']);
                    }
                }
            }
        });
    }
    
    //Check all plugins satisfied take out from function getPluginByTableId
    //If calling event is not button, then call execute function of this plugin
    //Because namspace can't contains specifies symbol
    /**
     * @param null $event
     */
    public static function pluginPreparing($plugins, $event = null)
    {
        $pluginCalled = false;
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                $event_triggers = $plugin->options['event_triggers'];
                $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit'];
                
                $classname = getPluginNamespace($plugin->plugin_name, 'Plugin');
                if (in_array($event, $event_triggers) && !in_array($event, $event_triggers_button) && class_exists($classname)) {
                    //$reponse = app('\App\Plugin\\'.$plugin->plugin_name.'\Plugin')->execute($event);
                    $pluginCalled = app($classname)->execute();
                    if ($pluginCalled) {
                        admin_toastr('Plugin called: '.$event);
                    }
                }
            }
        }
    }

    //Check all plugins satisfied take out from function getPluginByTableId
    //If calling event is button, then add event into array, then return array to make button with action
    /**
     * @param null $event
     * @return array
     */
    public static function pluginPreparingButton($plugins, $event = null)
    {
        $buttonList = [];
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                $event_triggers = $plugin->options['event_triggers'];
                $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit'];
                if (in_array($event, $event_triggers) && in_array($event, $event_triggers_button)) {
                    array_push($buttonList, $plugin);
                }
            }
        }
        return $buttonList;
    }

    //Function handle click event
    /**
     * @param Request $request
     * @return Response
     */
    public static function onPluginClick(Request $request)
    {
        if ($request->input('plugin_name') !== null) {
            $classname = getPluginNamespace($request->input('plugin_name'), 'Plugin');
            if (class_exists($classname)) {
                //$response = app('\App\Plugin\\'.$request->input('plugin_name').'\Plugin')->execute();
                app($classname)->execute();
//                if($response){
//                    return response('Success');
//                }
            }
        }
        return Response::create('Plugin Called', 200);
    }
}
