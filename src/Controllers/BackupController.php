<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Validator;

class BackupController extends AdminControllerBase
{
    use InitializeForm;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("backup.header"), exmtrans("backup.header"), exmtrans("backup.description"));
    }
    
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        // get all archive files
        $files = array_filter(static::disk()->files('list'), function ($file)
        {
            return preg_match('/list\/\d+\.zip$/i', $file);
        });
        // edit table row data
        $rows = [];
        foreach ($files as $file) {
              $rows[] = [
                'file_key' => pathinfo($file, PATHINFO_FILENAME),
                'file_name' => mb_basename($file),
                'file_size' => bytesToHuman(static::disk()->size($file)),
                'created' => date("Y/m/d H:i:s", static::disk()->lastModified($file))
            ];
        }

        $content->row(view('exment::backup.index', 
            ['files' => $rows, 'modal' => $this->importModal()]
        ));

        // create setting form
        //$content->row($this->settingFormBox());

        // $content->body(view('exment::backup.index', 
        //     ['files' => $rows, 'modal' => $this->importModal()]));
        return $content;
    }

    // protected function settingFormBox(){
    //     $form = new WidgetForm(System::get_system_values());
    //     $form->disableReset();
        
    //     $form->header(exmtrans('system.header'))->hr();
    //     $form->text('site_name', exmtrans("system.site_name"))
    //         ->help(exmtrans("system.help.site_name"));

    //     $form->text('site_name_short', exmtrans("system.site_name_short"))
    //         ->help(exmtrans("system.help.site_name_short"));
            
    //     $form->image('site_logo', exmtrans("system.site_logo"))
    //        ->help(exmtrans("system.help.site_logo"))
    //        ;
    //     $form->image('site_logo_mini', exmtrans("system.site_logo_mini"))
    //        ->help(exmtrans("system.help.site_logo_mini"))
    //        ;

    //     $form->select('site_skin', exmtrans("system.site_skin"))
    //        ->options(getTransArray(Define::SYSTEM_SKIN, "system.site_skin_options"))
    //        ->help(exmtrans("system.help.site_skin"));

    //     $form->select('site_layout', exmtrans("system.site_layout"))
    //         ->options(getTransArray(array_keys(Define::SYSTEM_LAYOUT), "system.site_layout_options"))
    //         ->help(exmtrans("system.help.site_layout"));

    //     $form->switchbool('authority_available', exmtrans("system.authority_available"))
    //         ->help(exmtrans("system.help.authority_available"));

    //     $form->switchbool('organization_available', exmtrans("system.organization_available"))
    //         ->help(exmtrans("system.help.organization_available"));

    //     $form->email('system_mail_from', exmtrans("system.system_mail_from"))
    //         ->help(exmtrans("system.help.system_mail_from"));

    //     return new Box(trans('admin.edit'), $form);
    // }

    /**
     * Render import modal form.
     *
     * @return Content
     */
    protected function importModal()
    {
        $import_path = admin_base_path(url_join('backup', 'import'));
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->disableReset();
        $form->modalAttribute('id', 'data_import_modal');
        $form->modalHeader(exmtrans('backup.restore'));

        $form->action($import_path)
            ->file('upload_zipfile', exmtrans('backup.upload_zipfile'))
            ->rules('mimes:zip')->setWidth(8, 3)->addElementClass('custom_table_file')
            ->options(['showPreview' => false])
            ->help(exmtrans('backup.help.file_name'));

        return $form->render()->render();
    }

    /**
     * Upload zip file
     */
    protected function import(Request $request)
    {
        if ($request->has('upload_zipfile')) {
            // get upload file
            $file = $request->file('upload_zipfile');
            // store uploaded file
            $filename = $file->store('upload_tmp', 'local');
            $fullpath = getFullpath($filename, 'local');
            \Artisan::call('down');
            $result = \Artisan::call('exment:restore', ['file' => $fullpath] );
            \Artisan::call('up');
        } 
        
        if (isset($result) && $result === 0) {
            $response = [
                'result' => true,
                'toastr' => exmtrans('backup.message.restore_succeeded'),
                'errors' => [],
            ];
        } else {
            $response = [
                'result' => false,
                'toastr' => exmtrans('backup.message.restore_file_error'),
                'errors' => [],
            ];
        }
        return getAjaxResponse($response);
    }

    /**
     * Delete interface.
     *
     * @return Content
     */
    public function delete(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'files' => 'required',
        ]);

        if ($validator->passes()) {
            $files = explode(',', $data['files']);
            foreach ($files as $file) {
                $path = path_join('list', $file . '.zip');
                if (static::disk()->exists($path)) {
                    static::disk()->delete($path);
                }
            }
            return response()->json([
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ]);
        } else {
            return response()->json([
                'status'  => false,
                'message' => trans('admin.delete_failed'),
            ]);
        }
    }

    /**
     * execute backup command.
     *
     * @return Content
     */
    public function save(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'type' => 'required',
        ]);

        if ($validator->passes()) {
            $result = \Artisan::call('exment:backup', ['type' => $data['type']]);
        }

        if (isset($result) && $result === 0) {
            return response()->json([
                'status'  => true,
                'message' => trans('admin.save_succeeded'),
            ]);
        } else {
            return response()->json([
                'status'  => false,
                'message' => exmtrans("backup.message.backup_error"),
            ]);
        }
    }

    /**
     * restore from backup file.
     *
     * @return Content
     */
    public function restore(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'file' => 'required',
        ]);
      
        if ($validator->passes()) {
            // get backup folder full path
            $backup = static::disk()->getAdapter()->getPathPrefix();
            // get restore file path
            $file = path_join($backup, 'list', $data['file'] . '.zip');

            \Artisan::call('down');
            $result = \Artisan::call('exment:restore', ['file' => $file] );
            \Artisan::call('up');
        }

        if (isset($result) && $result === 0) {
            admin_toastr('リストアに成功しました。ログイン画面にリダイレクトします。');
            return redirect(admin_base_paths('auth', 'logout'));
            return response()->json([
                'status'  => true,
                'message' => exmtrans('backup.message.restore_succeeded'),
            ]);
        } else {
            return response()->json([
                'status'  => false,
                'message' => exmtrans("backup.message.restore_error"),
            ]);
        }
    }
    /**
     * Download file
     */
    public static function download($arg)
    {
        $ymdhms = urldecode($arg);

        $validator = Validator::make(['ymdhms' => $ymdhms], [
            'ymdhms' => 'required|numeric'
        ]);

        if (!$validator->passes()) {
            abort(404);
        }

        $path = path_join("list",  $ymdhms . '.zip');
        $exists = static::disk()->exists($path);
        if (!$exists) {
            abort(404);
        }

        $file = static::disk()->get($path);
        $type = static::disk()->mimeType($path);
        // get page name
        $name = rawurlencode(mb_basename($path));
        // create response
        $response = \Response::make($file, 200);
        $response->header("Content-Type", $type);
        $response->header('Content-Disposition', "attachment; filename*=UTF-8''$name");

        return $response;
    }

    protected static function disk(){
        return Storage::disk('backup');
    }
}
