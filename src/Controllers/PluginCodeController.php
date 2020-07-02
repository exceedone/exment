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
        $filedata = null;

        $this->AdminContent($content);

        $plugin = Plugin::getEloquent($id);
        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
        $folder = $plugin->getPath();
        if ($disk->exists($folder)) {
            $filedata = $disk->get("$folder/plugin.php");
        }
        $script = <<<EOT
        $(function () {
            $('textarea.edit_file').each(function(index, elem){
                CodeMirror.fromTextArea(elem, {
                    mode: 'php',
                    lineNumbers: true,
                    indentUnit: 4
                });
            });
        });
        EOT;
        Admin::script($script);
        
        return
            $content->row(function (Row $row) use($id, $filedata) {
                $row->column(9, function (Column $column) use($id, $filedata) {
                    $form = new \Encore\Admin\Widgets\Form();
                    //$form->action(admin_url("plugin/code_edit"));
                    $form->disableReset();
                    $form->disableSubmit();

                    $form->textarea('edit_file')->default($filedata)->setWidth(12, 0)->attribute(['id' => 'edit_file']);
                    $form->ajaxButton('save_plugin_code', trans("admin.save"))
                        ->url(admin_urls('plugin', 'edit_code', $id))
                        ->button_class('btn-md btn-info pull-right')
                        ->button_label(trans("admin.save"))
                        ->send_params('edit_file')
                        ->setWidth(10, 0);
        
                    $column->append((new Box('Plugin.php', $form))->style('success'));
                });

                $row->column(3, function (Column $column) {

                });
            });
    }

    //Function use to upload file and update or add new record
    public function store(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'edit_file' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $edit_file = $request->get('edit_file');
        
        $plugin = Plugin::getEloquent($id);
        if (!isset($plugin)) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('plugincode.error.plugin_notfound'),
                'reload' => false,
            ]);
        }

        $disk = \Storage::disk(Define::DISKNAME_PLUGIN);
        $folder = $plugin->getPath();
        if (!$disk->exists($folder)) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('plugincode.error.file_notfound'),
                'reload' => false,
            ]);
        }
        $disk->put("$folder/plugin.php", $edit_file);

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.save_succeeded'),
            'reload' => false,
        ]);
    }
}
