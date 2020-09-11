<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Row;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\Permission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Validator;

class PluginCodeController extends AdminControllerBase
{
    protected $plugin;

    /**
     * constructer
     *
     */
    public function __construct()
    {
        $this->setPageInfo(exmtrans("plugincode.header"), exmtrans("plugincode.header"), exmtrans("plugincode.description"), 'fa-plug');
    }

    /**
     * Showing code edit page
     *
     * @param Request $request
     * @param Content $content
     * @param int $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $id)
    {
        $this->AdminContent($content);
        session()->forget(Define::SYSTEM_KEY_SESSION_PLUGIN_NODELIST);

        $this->plugin = Plugin::getEloquent($id);

        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }
        $content->row(function (Row $row) use ($id) {
            $row->column(9, view('exment::plugin.editor.upload', [
                'url' => admin_url("plugin/edit_code/$id/fileupload"),
                'filepath' => '/',
                'message' => exmtrans('plugincode.message.upload_file'),
                'nodeid' => null,
            ]));

            $row->column(3, $this->getJsTreeBox($id));
        });

        return $content;
    }

    protected function getJsTreeBox($id)
    {
        $view = view('exment::widgets.jstree', [
            'data_get_url' => "$id/getTree",
            'file_get_url' => "$id/selectFile",
        ]);
        $box = new Box('', $view);
        $box->tools(view('exment::plugin.editor.buttons', [
            'id' => $id,
        ]))->style('info');

        return $box->render();
    }

    /**
     * Get file tree data
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function getTreeData(Request $request, $id)
    {
        $json = [];
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        $this->setDirectoryNodes('/', '#', $json, true);

        // set session
        session([Define::SYSTEM_KEY_SESSION_PLUGIN_NODELIST => $json]);
        return response()->json($json);
    }

    /**
     * Upload file to target folder
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function fileupload(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }
        
        $validator = \Validator::make($request->all(), [
            'nodeid' => 'required',
        ]);
        if ($validator->fails()) {
            return back()->with('errorMess', exmtrans("plugincode.error.folder_notfound"));
        }

        if ($request->hasfile('fileUpload')) {
            $nodeid = str_replace('//', '/', $request->get('nodeid'));
            $nodepath = $this->getNodePath($nodeid);
            if(is_nullorempty($nodepath)){
                throw new \Exception;
            }

            $folder_path = str_replace('//', '/', $request->get('nodepath'));
            // path root check, if search as ex. "../../", throw new exception.
            if(strpos(str_replace(' ', '', $folder_path), '..') !== false){
                throw new \Exception;
            }
            $upload_files = $request->file('fileUpload');

            foreach ($upload_files as $upload_file) {
                $filename = $upload_file->getClientOriginalName();

                $this->plugin->putAsPluginFile($folder_path, $filename, $upload_file);
            }
            
            $this->updatePluginDatetime();
            admin_toastr(exmtrans('common.message.success_execute'));
            return back();
        }
        // if not exists, return back and message
        return back()->with('errorMess', exmtrans("plugin.help.errorMess"));
    }

    /**
     * Get child form html for selected file
     *
     * @param Request $request
     * @param int $id
     * @return array
     */
    public function getFileEditForm(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        
        list($view, $isBox) = $this->getFileEditFormView($request, $id);

        if ($isBox) {
            $box = new Box('', $view);
            $view = $box->style('info');
        }
        return [
            'editor' => $view->render()
        ];
    }

    
    /**
     * Get child form html for selected file
     *
     * @param Request $request
     * @param int $id
     * @return array
     */
    protected function getFileEditFormView(Request $request, $id)
    {
        $validator = \Validator::make($request->all(), [
            'nodeid' => 'required',
        ]);
        if ($validator->fails()) {
            return [view('exment::plugin.editor.info'), false];
        }

        $nodeid = str_replace('//', '/', $request->get('nodeid'));
        $nodepath = $this->getNodePath($nodeid);
        if(is_nullorempty($nodepath)){
            throw new \Exception;
        }

        try {
            if ($this->plugin->isPathDir($nodepath)) {
                return [view('exment::plugin.editor.upload', [
                    'url' => admin_url("plugin/edit_code/$id/fileupload"),
                    'filepath' => $nodepath,
                    'message' => exmtrans('plugincode.message.upload_file'),
                    'nodeid' => $nodeid,
                ]), false];
            }

            list($mode, $image, $can_delete) = $this->getPluginFileType($nodepath);

            $message = exmtrans('plugincode.message.irregular_ext');

            if (isset($image)) {
                $filedata = $this->plugin->getPluginFiledata($nodepath);
                return [view('exment::plugin.editor.image', [
                    'image' => base64_encode($filedata),
                    'url' => admin_url("plugin/edit_code/$id"),
                    'ext' => $image,
                    'filepath' => $nodepath,
                    'nodeid' => $nodeid,
                    'can_delete' => $can_delete,
                ]), true];
            } elseif ($mode !== false) {
                $filedata = $this->plugin->getPluginFiledata($nodepath);
                $enc = mb_detect_encoding($filedata, ['UTF-8', 'UTF-16', 'ASCII', 'ISO-2022-JP', 'EUC-JP', 'SJIS'], true);
                if ($enc == 'UTF-8') {
                    return [view('exment::plugin.editor.code', [
                        'url' => admin_url("plugin/edit_code/$id"),
                        'filepath' => $nodepath,
                        'nodeid' => $nodeid,
                        'filedata' => $filedata,
                        'mode' => $mode,
                    ]), true];
                } else {
                    $message = exmtrans('plugincode.message.irregular_enc');
                }
            }

            return [view('exment::plugin.editor.other', [
                'url' => admin_url("plugin/edit_code/$id"),
                'filepath' => $nodepath,
                'nodeid' => $nodeid,
                'can_delete' => $can_delete,
                'message' => $message
            ]), false];
        } catch (\League\Flysystem\FileNotFoundException $ex) {
            //Todo:FileNotFoundException
        }
    }

    /**
     * Get CodeMirror mode and image type of file
     *
     * @param string $nodepath
     * @return array [CodeMirror mode, image extension, deletable flg]
     */
    protected function getPluginFileType($nodepath)
    {
        // exclude config.json
        if (mb_strtolower(basename($nodepath)) === 'config.json') {
            return [false, null, false];
        }

        // check extension
        $ext = \File::extension($nodepath);
        $mode = false;
        $image = null;

        switch ($ext) {
            case 'php':
            case 'css':
                $mode = $ext;
                break;
            case 'js':
                $mode = 'javascript';
                break;
            case 'json':
                $mode = "{ name: 'javascript', json: true}";
                break;
            case 'txt':
                $mode = null;
                break;
            case 'jpg':
            case 'jpeg':
                $image = 'jpeg';
                break;
            case 'gif':
                $image = 'gif';
                break;
            case 'png':
                $image = 'png';
                break;
        }
        return [$mode, $image, true];
    }

    /**
     * Get and set file and directory nodes in target folder
     *
     * @param string $folder
     * @param string $parent
     * @param array &$json
     * @param string $folderName root folder name.
     */
    protected function setDirectoryNodes($folder, $parent, &$json, bool $selected = false)
    {
        $directory_node = "node_" . make_uuid();
        $json[] = [
            'id' => $directory_node,
            'parent' => $parent,
            'path' => $folder,
            'text' => isMatchString($folder, '/') ? '/' : basename($folder),
            'state' => [
                'opened' => $parent == '#',
                'selected' => $selected
            ]
        ];

        $directories = $this->plugin->getPluginDirPaths($folder, false);
        foreach ($directories as $directory) {
            $this->setDirectoryNodes($directory, $directory_node, $json);
        }

        $files = $this->plugin->getPluginFilePaths($folder, false);
        foreach ($files as $file) {
            $json[] = [
                'id' => "node_" . make_uuid(),
                'parent' => $directory_node,
                'path' => path_join($folder, basename($file)),
                'icon' => 'jstree-file',
                'text' => basename($file),
            ];
        }
    }

    /**
     * delete target file from plugin folder
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function delete(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }
        
        $validator = Validator::make($request->all(), [
            'nodeid' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $nodeid = str_replace('//', '/', $request->get('nodeid'));
        $nodepath = $this->getNodePath($nodeid);
        if(is_nullorempty($nodepath)){
            throw new \Exception;
        }
        $file_path = str_replace('//', '/', $nodepath);

        $this->plugin->deletePluginFile($file_path);

        $this->updatePluginDatetime();

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.delete_succeeded'),
            'reload' => false,
        ]);
    }

    /**
     * update file in plugin folder
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function store(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }
        
        $validator = Validator::make($request->all(), [
            'nodeid' => 'required',
            'edit_file' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $nodeid = str_replace('//', '/', $request->get('nodeid'));
        $nodepath = $this->getNodePath($nodeid);
        if(is_nullorempty($nodepath)){
            throw new \Exception;
        }
        $file_path = $nodepath;
        $edit_file = $request->get('edit_file');

        $this->plugin->putPluginFile($file_path, $edit_file);

        $this->updatePluginDatetime();

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.save_succeeded'),
            'reload' => false,
        ]);
    }

    /**
     * Update plugin's updated_at. Because sync files from crowd.
     *
     * @return void
     */
    protected function updatePluginDatetime()
    {
        $this->plugin->update([
            'updated_at' => \Carbon\Carbon::now(),
        ]);
    }


    /**
     * Get node path from node id
     *
     * @param string $nodeId
     * @return string|null
     */
    protected function getNodePath($nodeId) : ?string{
        $nodelist = session(Define::SYSTEM_KEY_SESSION_PLUGIN_NODELIST);
        if(is_nullorempty($nodelist)){
            return null;
        }

        foreach($nodelist as $node){
            if(!isMatchString($nodeId, array_get($node, 'id'))){
                continue;
            }

            return array_get($node, 'path');
        }
    }
}
