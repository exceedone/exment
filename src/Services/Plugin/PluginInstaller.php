<?php
namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Define;
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
        // store uploaded file and get tmp path
        $filename = $uploadFile->store('upload_tmp', 'local');
        $fullpath = getFullpath($filename, 'local');
        // tmpfolderpath is the folder path uploaded. 
        $tmpfolderpath = path_join(pathinfo($fullpath)['dirname'], pathinfo($fullpath)['filename']);
        $tmpPluginFolderPath = null;

        //Folder to move file uploaded
        $pluginBasePath = path_join(app_path(), 'Plugins');
        if(!\File::exists($pluginBasePath)){
            \File::makeDirectory($pluginBasePath, 0775);
        }

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
                $response = back()->with('errorMess', 'config.jsonファイルが不正です');
            }
            else{
                //Validate json file with fields require
                $checkRuleConfig = static::checkRuleConfigFile($json);
                if ($checkRuleConfig) {
                    //Check if the name of the plugin has existed
                    $plugineExistByName = static::checkPluginNameExisted(array_get($json, 'plugin_name'));
                    //Check if the uuid of the plugin has existed
                    $plugineExistByUUID = static::checkPluginUUIDExisted(array_get($json, 'uuid'));
                    //Make path of folder where contain plugin with name is plugin's name
                    $pluginFolder = path_join($pluginBasePath, pascalize(preg_replace('/\s+/', '', array_get($json, 'plugin_name'))));

                    //If json pass validation, prepare data to do continue
                    $plugin = static::prepareData($json);
                    //If both name and uuid existed, update data for this plugin
                    if ($plugineExistByName > 0 && $plugineExistByUUID > 0) {
                        $pluginUpdated = $plugin->saveOrFail();
                        //Rename folder with plugin name
                        static::copyPluginNameFolder($json, $pluginFolder, $tmpPluginFolderPath);
                        admin_toastr('アップロードに成功しました');
                        $response = back();
                    }
                    //If both name and uuid does not existed, save new record to database, change name folder with plugin name then return success
                    elseif ($plugineExistByName <= 0 && $plugineExistByUUID <= 0) {
                        $plugin->save();
                        static::copyPluginNameFolder($json, $pluginFolder, $tmpPluginFolderPath);
                        admin_toastr('アップロードに成功しました');
                        $response = back();
                    }

                    //If name has existed but uuid does not existed, then delete folder and return error with message
                    elseif ($plugineExistByName > 0 && $plugineExistByUUID <= 0) {
                        $response = back()->with('errorMess', '同名プラグインが存在します。確認してから一度お試してください。');
                    }
                    //If uuid has existed but name does not existed, then delete folder and return error with message
                    elseif ($plugineExistByName <= 0 && $plugineExistByUUID > 0) {
                        $response = back()->with('errorMess', 'UUIDは存在しますが、プラグイン名が正しくありません。 確認してからもう一度お試しください。');
                    }
                    //rename folder without Uppercase, space, tab, ...
                    else{
                        $response = back();
                    }
                } else {
                    $response = back()->with('errorMess', 'config.jsonファイルが不正です');
                }
            }
        }
        
        // delete tmp folder
        File::deleteDirectory($tmpfolderpath);
        $zip->close();
        // delete zip
        unlink($fullpath);
        //return response
        if(isset($response)){
            return $response;
        }
    }
    
    //Function validate config.json file with field required
    protected static function checkRuleConfigFile($json)
    {
        $rules = [
            'plugin_name' => 'required',
            'plugin_type' => 'required|in:trigger,page,dashboard,batch,document',
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
        $plugin->plugin_name = array_get($json, 'plugin_name');
        $plugin->plugin_type = array_get($json, 'plugin_type');
        $plugin->author = array_get($json, 'author');
        $plugin->version = array_get($json, 'version');
        $plugin->uuid = array_get($json, 'uuid');
        $plugin->plugin_view_name = array_get($json, 'plugin_view_name');
        $plugin->description = array_get($json, 'description');
        $plugin->active_flg = true;

        // set options 
        $options = array_get($plugin, 'options', []);
        // set if exists
        if(array_key_value_exists('target_tables', $json)){
            $target_tables = array_get($json, 'target_tables');
            // if is_string $target_tables
            if(is_string($target_tables)){
                $target_tables = [$target_tables];
            }
            $options['target_tables'] = $target_tables;
        }
        if(array_key_value_exists('label', $json)){
            $options['label'] = array_get($json, 'label');
        }
        if(array_key_value_exists('icon', $json)){
            $options['icon'] = array_get($json, 'icon');
        }
        $plugin->options = $options;

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

    //Get plugin by custom_table name
    //Where active_flg = 1 and target_tables contains custom_table id
    /**
     * @param $id
     * @return mixed
     */
    public static function getPluginByTable($table_name)
    {
        // espace name
        $table_name_escape = trim(DB::getPdo()->quote($table_name), "'");
        // execute query
        return Plugin::where('active_flg', '=', 1)
            ->whereIn('plugin_type', [Define::PLUGIN_TYPE_TRIGGER, Define::PLUGIN_TYPE_DOCUMENT])
            ->whereRaw('JSON_CONTAINS(options, \'"'.$table_name_escape.'"\', \'$.target_tables\')')
            //->where('options->target_tables', $table_name)
            ->get()
            ;
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
                // get plugin_type
                $plugin_type = array_get($plugin, 'plugin_type');
                // if $plugin_type is not trigger, continue
                if($plugin_type != Define::PLUGIN_TYPE_TRIGGER){
                    continue;
                }
                $event_triggers = array_get($plugin, 'options.event_triggers');
                $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit','form_menubutton_show'];
                
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
                // get plugin_type
                $plugin_type = array_get($plugin, 'plugin_type');
                switch($plugin_type){
                    case Define::PLUGIN_TYPE_DOCUMENT:
                        $event_triggers_button = ['form_menubutton_show'];
                        if (in_array($event, $event_triggers_button)) {
                            array_push($buttonList, $plugin);
                        }
                        break;
                    case Define::PLUGIN_TYPE_TRIGGER:
                        $event_triggers = $plugin->options['event_triggers'];
                        $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit','form_menubutton_show'];
                        if (in_array($event, $event_triggers) && in_array($event, $event_triggers_button)) {
                            array_push($buttonList, $plugin);
                        }
                    break;
                }
            }
        }
        return $buttonList;
    }
}
