<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Row;
use Encore\Admin\Widgets\Alert;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Validator;

class PluginCodeController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans("plugincode.header"), exmtrans("plugincode.header"), exmtrans("plugincode.description"), 'fa-plug');
    }

    /**
     * Showing code edit page
     */
    public function edit(Request $request, Content $content, $id)
    {
        $this->AdminContent($content);

        return
            $content->row(function (Row $row) use($id) {
                $row->column(9, view('exment::plugin.editor.info', [
                    'message' => exmtrans('plugincode.message.select_file'),
                ]));

                $row->column(3, view('exment::widgets.jstree', [
                    'data_get_url' => "$id/getTree",
                    'file_get_url' => "$id/selectFile",
                ]));
            });
    }

    public function getTreeData(Request $request, $id) {
        $json = [];
        $plugin = Plugin::getEloquent($id);
        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
        $folder = $plugin->getPath();
        $node_idx = 0;
        if ($disk->exists($folder)) {
            $this->setDirectoryNodes($folder, '#', $node_idx, $json);
        }
        return response()->json($json);
    }

    public function fileupload(Request $request, $id) {
        $validator = \Validator::make($request->all(), [
            'plugin_file_path' => 'required',
        ]);
        if ($validator->fails()) {
            return back()->with('errorMess', exmtrans("plugincode.error.folder_notfound"));
        }

        if ($request->hasfile('fileUpload')) {
            $folder_path = $request->get('plugin_file_path');
            $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
            if ($disk->exists($folder_path)) {
                $upload_file = $request->file('fileUpload');
                $filename = $upload_file->getClientOriginalName();
                $disk->putFileAs($folder_path, $upload_file, $filename);
                admin_toastr(exmtrans('common.message.success_execute'));
                return back();
            }
        }
        // if not exists, return back and message
        return back()->with('errorMess', exmtrans("plugin.help.errorMess"));
    }

    public function getFileEditForm(Request $request, $id) {
        $validator = \Validator::make($request->all(), [
            'nodepath' => 'required',
        ]);
        if ($validator->fails()) {
            return [
                'editor' => view('exment::plugin.editor.info')->render(),
            ];
        }

        $nodepath = $request->get('nodepath');
        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);

        if ($disk->exists($nodepath)) {
            $tmpFulldir = getFullpath($nodepath, Define::DISKNAME_PLUGIN, true);
            if (\File::isDirectory($tmpFulldir)) {
                return [
                    'editor' => view('exment::plugin.editor.upload', [
                        'url' => admin_url("plugin/edit_code/$id/fileupload"),
                        'filepath' => $nodepath,
                        'message' => exmtrans('plugincode.message.upload_file'),
                    ])->render(),
                ];
            } 

            $mode = $this->getCodeMirrorMode($nodepath);

            if ($mode !== false) {
                $filedata = $disk->get($nodepath);
                $enc = mb_detect_encoding($filedata, mb_list_encodings(), true);
                if ($enc == 'UTF-8') {
                    return [
                        'editor' => view('exment::plugin.editor.code', [
                            'url' => admin_url("plugin/edit_code/$id"),
                            'filepath' => $nodepath,
                            'filedata' => $filedata,
                            'mode' => $mode,
                        ])->render(),
                    ];
                }
            }

            return [
                'editor' => view('exment::plugin.editor.other', [
                    'url' => admin_url("plugin/edit_code/$id"),
                    'filepath' => $nodepath,
                ])->render(),
            ];
        }
    }

    protected function getCodeMirrorMode($nodepath) {
        // exclude config.json
        if (mb_strtolower(basename($nodepath)) === 'config.json') {
            return false;
        }

        // check extension
        $ext = \File::extension($nodepath);
        switch ($ext) {
            case 'php':
            case 'css':
                return $ext;
            case 'js':
                return 'javascript';
            case 'json':
                return "{ name: 'javascript', json: true}";
            case 'txt':
                return null;
            default;
                return false;
        }
    }

    protected function setDirectoryNodes($folder, $parent, &$node_idx, &$json) {
        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);

        $node_idx++;
        $directory_node = "node_$node_idx";
        $json[] = [
            'id' => $directory_node,
            'parent' => $parent,
            'text' => basename($folder),
            'state' => [
                'opened' => $parent == '#'
            ]
        ];

        $directories = $disk->directories($folder);
        foreach ($directories as $directory) {
            $this->setDirectoryNodes($directory, $directory_node, $node_idx, $json);
        }

        $files = $disk->files($folder);
        foreach ($files as $file) {
            $node_idx++;
            $json[] = [
                'id' => "node_$node_idx",
                'parent' => $directory_node,
                'icon' => 'jstree-file',
                'text' => basename($file),
            ];
        }

        return $directory_node;
    }

    /**
     * Function use to upload file and update or add new record
     */
    public function delete(Request $request, $id)
    {
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

        $file_path = $request->get('file_path');

        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
        if (!$disk->exists($file_path)) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('plugincode.error.file_notfound'),
                'reload' => false,
            ]);
        }
        $disk->delete($file_path);

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.delete_succeeded'),
            'reload' => false,
        ]);
    }

    /**
     * Function use to upload file and update or add new record
     */
    public function store(Request $request, $id)
    {
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

        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
        if (!$disk->exists($file_path)) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('plugincode.error.file_notfound'),
                'reload' => false,
            ]);
        }
        $disk->put($file_path, $edit_file);

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.save_succeeded'),
            'reload' => false,
        ]);
    }
}
