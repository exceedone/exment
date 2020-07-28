<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Row;
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

        $this->plugin = Plugin::getEloquent($id);

        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        return
            $content->row(function (Row $row) use($id) {
                $row->column(9, view('exment::plugin.editor.upload', [
                    'url' => admin_url("plugin/edit_code/$id/fileupload"),
                    'filepath' => '/',
                    'message' => exmtrans('plugincode.message.upload_file'),
                ]));

                $row->column(3, view('exment::widgets.jstree', [
                    'data_get_url' => "$id/getTree",
                    'file_get_url' => "$id/selectFile",
                ]));
            });
    }

    /**
     * Get file tree data
     * 
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function getTreeData(Request $request, $id) {
        $json = [];
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        $node_idx = 0;
        $this->setDirectoryNodes('/', '#', $node_idx, $json);
        return response()->json($json);
    }

    /**
     * Upload file to target folder
     * 
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function fileupload(Request $request, $id) {
        $this->plugin = Plugin::getEloquent($id);
        
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }
        
        $validator = \Validator::make($request->all(), [
            'plugin_file_path' => 'required',
        ]);
        if ($validator->fails()) {
            return back()->with('errorMess', exmtrans("plugincode.error.folder_notfound"));
        }

        if ($request->hasfile('fileUpload')) {
            $folder_path = str_replace('//', '/', $request->get('plugin_file_path'));
            $upload_file = $request->file('fileUpload');
            $filename = $upload_file->getClientOriginalName();

            $this->plugin->putAsPluginFile($folder_path, $filename, $upload_file);
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
    public function getFileEditForm(Request $request, $id) {
        $this->plugin = Plugin::getEloquent($id);
        
        $validator = \Validator::make($request->all(), [
            'nodepath' => 'required',
        ]);
        if ($validator->fails()) {
            return [
                'editor' => view('exment::plugin.editor.info')->render(),
            ];
        }

        $nodepath = str_replace('//', '/', $request->get('nodepath'));
        try{
            if ($this->plugin->isPathDir($nodepath)) {
                return [
                    'editor' => view('exment::plugin.editor.upload', [
                        'url' => admin_url("plugin/edit_code/$id/fileupload"),
                        'filepath' => $nodepath,
                        'message' => exmtrans('plugincode.message.upload_file'),
                    ])->render(),
                ];
            } 

            list($mode, $can_delete) = $this->getCodeMirrorMode($nodepath);

            $message = exmtrans('plugincode.message.irregular_ext');

            if ($mode !== false) {
                $filedata = $this->plugin->getPluginFiledata($nodepath);
                $enc = mb_detect_encoding($filedata, ['UTF-8', 'UTF-16', 'ASCII', 'ISO-2022-JP', 'EUC-JP', 'SJIS'], true);
                if ($enc == 'UTF-8') {
                    return [
                        'editor' => view('exment::plugin.editor.code', [
                            'url' => admin_url("plugin/edit_code/$id"),
                            'filepath' => $nodepath,
                            'filedata' => $filedata,
                            'mode' => $mode,
                        ])->render(),
                    ];
                } else {
                    $message = exmtrans('plugincode.message.irregular_enc');
                }
            }

            return [
                'editor' => view('exment::plugin.editor.other', [
                    'url' => admin_url("plugin/edit_code/$id"),
                    'filepath' => $nodepath,
                    'can_delete' => $can_delete,
                    'message' => $message
                ])->render(),
            ];
        }
        catch(\League\Flysystem\FileNotFoundException $ex){
            //Todo:FileNotFoundException
        }
    }

    /**
     * Get CodeMirror mode for file
     * 
     * @param string $nodepath
     * @return string|boolean
     */
    protected function getCodeMirrorMode($nodepath) {
        // exclude config.json
        if (mb_strtolower(basename($nodepath)) === 'config.json') {
            return [false, false];
        }

        // check extension
        $ext = \File::extension($nodepath);
        $mode = false;
        switch ($ext) {
            case 'php':
            case 'css':
                $mode = $ext;
            case 'js':
                $mode = 'javascript';
            case 'json':
                $mode = "{ name: 'javascript', json: true}";
            case 'txt':
                $mode = null;
        }
        return [$mode, true];
    }

    /**
     * Get and set file and directory nodes in target folder
     * 
     * @param string $folder
     * @param string $parent
     * @param int &$node_idx
     * @param array &$json
     * @param string $folderName root folder name.
     */
    protected function setDirectoryNodes($folder, $parent, &$node_idx, &$json) {
        $node_idx++;
        $directory_node = "node_$node_idx";
        $json[] = [
            'id' => $directory_node,
            'parent' => $parent,
            'text' => isMatchString($folder, '/') ? '/' : basename($folder),
            'state' => [
                'opened' => $parent == '#',
                'selected' => $node_idx == 1
            ]
        ];

        $directories = $this->plugin->getPluginDirPaths($folder, false);
        foreach ($directories as $directory) {
            $this->setDirectoryNodes($directory, $directory_node, $node_idx, $json);
        }

        $files = $this->plugin->getPluginFilePaths($folder, false);
        foreach ($files as $file) {
            $node_idx++;
            $json[] = [
                'id' => "node_$node_idx",
                'parent' => $directory_node,
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
            'file_path' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $file_path = str_replace('//', '/', $request->get('file_path'));

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
            'file_path' => 'required',
            'edit_file' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $file_path = $request->get('file_path');
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
    protected function updatePluginDatetime(){
        $this->plugin->update([
            'updated_at' => \Carbon\Carbon::now(),
        ]);
    }
}
