<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Row;
use Encore\Admin\Widgets\Box;
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

        $script = <<<EOT
        $(function () {
            $('textarea.edit_file').each(function(index, elem){
                CodeMirror.fromTextArea(elem, {
                    mode: 'php',
                    lineNumbers: true,
                    indentUnit: 4
                });
            }).on('ajaxbutton-beforesubmit', function(ev){
                var editor = document.querySelector(".CodeMirror").CodeMirror;
                editor.save();
            });
        });
        EOT;
        Admin::script($script);
        
        return
            $content->row(function (Row $row) use($id) {
                $row->column(9, function (Column $column) use($id) {
                    $form = new \Encore\Admin\Widgets\Form();
                    $form->disableReset();
                    $form->disableSubmit();

                    $form->textarea('edit_file')->setWidth(12, 0)->attribute(['id' => 'edit_file']);
                    $form->hidden('file_path')->attribute(['id' => 'file_path']);
                    $form->ajaxButton('save_plugin_code', trans("admin.save"))
                        ->url(admin_urls('plugin', 'edit_code', $id))
                        ->button_class('btn-md btn-info pull-right')
                        ->button_label(trans("admin.save"))
                        ->beforesubmit_events('edit_file') 
                        ->send_params('edit_file,file_path')
                        ->setWidth(10, 0);
        
                    $column->append((new Box('未選択', $form))->style('success'));
                });

                $row->column(3, view('exment::widgets.jstree', [
                    'data_get_url' => "$id/getTree",
                    'file_get_url' => "$id/getFile",
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

    public function getFileData(Request $request, $id) {
        $json = [];
        $validator = \Validator::make($request->all(), [
            'nodepath' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
        $nodepath = $request->get('nodepath');
        if ($disk->exists($nodepath)) {
            $filedata = $disk->get($nodepath);
            $json = [
                'filepath' => $nodepath,
                'filename' => basename($nodepath),
                'filedata' => $filedata
            ];
        }
        return response()->json($json);
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
