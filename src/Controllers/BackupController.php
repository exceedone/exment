<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums;
use Validator;
use DB;

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
        $content->row($this->settingFormBox());

        // $content->body(view('exment::backup.index', 
        //     ['files' => $rows, 'modal' => $this->importModal()]));
        return $content;
    }

    protected function settingFormBox(){
        $form = new WidgetForm(System::get_system_values());
        $form->action(admin_base_paths('backup/setting'));
        $form->disableReset();

        $form->checkbox('backup_target', exmtrans("backup.backup_target"))
            ->help(exmtrans("backup.help.backup_target"))
            ->options(Enums\BackupTarget::transArray('backup.backup_target_options'))
            ;
        
        $form->switchbool('backup_enable_automatic', exmtrans("backup.enable_automatic"))
            ->help(exmtrans("backup.help.enable_automatic"))
            ->attribute(['data-filtertrigger' =>true]);

        $form->number('backup_automatic_term', exmtrans("backup.automatic_term"))
            ->help(exmtrans("backup.help.automatic_term"))
            ->min(1)
            ->attribute(['data-filter' => json_encode(['key' => 'backup_enable_automatic', 'value' => '1'])]);

        $form->number('backup_automatic_hour', exmtrans("backup.automatic_hour"))
            ->help(exmtrans("backup.help.automatic_hour"))
            ->min(0)
            ->max(23)
            ->attribute(['data-filter' => json_encode(['key' => 'backup_enable_automatic', 'value' => '1'])]);

        return new Box(exmtrans("backup.setting_header"), $form);
    }

    /**
     * submit
     * @param Request $request
     */
    public function postSetting(Request $request)
    {
        DB::beginTransaction();
        try {
            $inputs = $request->all(System::get_system_keys('backup'));
        
            // set system_key and value
            foreach ($inputs as $k => $input) {
                System::{$k}($input);
            }
            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_base_path('backup'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }
    }

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
            ->options(Define::FILE_OPTION)
            ->help(exmtrans('backup.help.file_name'));

        return $form->render()->render();
    }

    /**
     * Upload zip file
     */
    protected function import(Request $request)
    {
        set_time_limit(240);

        if ($request->has('upload_zipfile')) {
            // get upload file
            $file = $request->file('upload_zipfile');
            // store uploaded file
            $filename = $file->store('upload_tmp', 'admin_tmp');
            $fullpath = getFullpath($filename, 'admin_tmp');
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
        set_time_limit(240);
        $data = $request->all();

        $target = System::backup_target();
        $result = \Artisan::call('exment:backup', ['--target' => $target]);

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
            admin_toastr(exmtrans('backup.message.restore_file_success'));
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
