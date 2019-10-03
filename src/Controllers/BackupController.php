<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Console\BackupRestoreTrait;
use Validator;
use DB;

class BackupController extends AdminControllerBase
{
    use BackupRestoreTrait;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("backup.header"), exmtrans("backup.header"), exmtrans("backup.description"), 'fa-database');
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
        $files = array_filter(static::disk()->files('list'), function ($file) {
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

        $content->row(view(
            'exment::backup.index',
            [
                'files' => $rows,
                'modal' => $this->importModal(),
                'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD,
                'restore_text' => exmtrans('common.message.execution_takes_time') . exmtrans('backup.message.restore_confirm_text') . exmtrans('common.message.input_keyword', Define::RESTORE_CONFIRM_KEYWORD),
            ]
        ));

        // create setting form
        $content->row($this->settingFormBox());

        // ※SQLServerはバックアップ未対応。※一時なので、日本語固定で表示
        if (config('database.default') == 'sqlsrv') {
            admin_error('SQL Server未対応', '現在、SQL Serverではバックアップ・リストア未対応です。ご了承ください。');
        }
        
        return $content;
    }

    protected function settingFormBox()
    {
        $form = new WidgetForm(System::get_system_values());
        $form->action(admin_urls('backup/setting'));
        $form->disableReset();

        $form->checkbox('backup_target', exmtrans("backup.backup_target"))
            ->help(exmtrans("backup.help.backup_target"))
            ->options(Enums\BackupTarget::transArray('backup.backup_target_options'))
            ;
        
        $form->switchbool('backup_enable_automatic', exmtrans("backup.enable_automatic"))
            ->help(exmtrans("backup.help.enable_automatic") . sprintf(exmtrans("common.help.task_schedule"), getManualUrl('quickstart_more#'.exmtrans('common.help.task_schedule_id'))))
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

        $form->number('backup_history_files', exmtrans("backup.history_files"))
            ->help(exmtrans("backup.help.history_files"))
            ->min(0)
            ->attribute(['data-filter' => json_encode(['key' => 'backup_enable_automatic', 'value' => '1'])]);

        return new Box(exmtrans("backup.setting_header"), $form);
    }

    /**
     * submit
     * @param Request $request
     */
    public function postSetting(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'backup_target.0' => 'required',
        ]);

        if (!$validator->passes()) {
            admin_toastr(exmtrans('backup.message.target_required'), 'error');
            return redirect(admin_url('backup'));
        }

        DB::beginTransaction();
        try {
            $inputs = $request->all(System::get_system_keys('backup'));
        
            // set system_key and value
            foreach ($inputs as $k => $input) {
                System::{$k}($input);
            }
            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_url('backup'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }
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
                'result'  => true,
                'message' => trans('admin.delete_succeeded'),
            ]);
        } else {
            return response()->json([
                'result'  => false,
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
                'result'  => true,
                'message' => trans('admin.save_succeeded'),
            ]);
        } else {
            return response()->json([
                'result'  => false,
                'message' => exmtrans("backup.message.backup_error"),
            ]);
        }
    }

    /**
     * Download file
     */
    public function download($arg)
    {
        $ymdhms = urldecode($arg);

        $validator = Validator::make(['ymdhms' => $ymdhms], [
            'ymdhms' => 'required|numeric'
        ]);

        if (!$validator->passes()) {
            abort(404);
        }

        $this->initBackupRestore($ymdhms);

        $path = $this->listZipName();
        $exists = static::disk()->exists($path);
        if (!$exists) {
            abort(404);
        }

        return downloadFile($path, static::disk());
    }

    /**
     * Render import modal form.
     *
     * @return Content
     */
    protected function importModal()
    {
        $import_path = admin_url(url_join('backup', 'import'));
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->disableReset();
        $form->modalAttribute('id', 'data_import_modal');
        $form->modalHeader(exmtrans('backup.restore'));

        $fileOption = Define::FILE_OPTION();
        $form->action($import_path);

        $form->file('upload_zipfile', exmtrans('backup.upload_zipfile'))
            ->rules('mimes:zip')->setWidth(8, 3)->addElementClass('custom_table_file')
            ->attribute(['accept' => ".zip"])
            ->removable()
            ->required()
            ->options($fileOption)
            ->help(exmtrans('backup.help.file_name') . array_get($fileOption, 'maxFileSizeHelp'));

        $form->text('restore_keyword', exmtrans('common.keyword'))
            ->required()
            ->setWidth(8, 3)
            ->help(exmtrans('common.message.input_keyword', Define::RESTORE_CONFIRM_KEYWORD));

        return $form->render()->render();
    }

    /**
     * Upload zip file
     */
    protected function import(Request $request)
    {
        set_time_limit(240);

        // validation
        $validator = Validator::make($request->all(), [
            'upload_zipfile' => 'required|file',
        ]);

        if (!$validator->passes()) {
            return getAjaxResponse([
                'result' => false,
                'toastr' => exmtrans('backup.message.restore_file_error'),
                'errors' => [],
            ]);
        }

        // validation restore keyword
        $validator = Validator::make($request->all(), [
            'restore_keyword' => Rule::in([Define::RESTORE_CONFIRM_KEYWORD]),
        ]);

        if (!$validator->passes()) {
            return getAjaxResponse([
                'result' => false,
                'toastr' => exmtrans('error.mistake_keyword'),
                'errors' => [],
            ]);
        }

        if ($request->has('upload_zipfile')) {
            // get upload file
            $file = $request->file('upload_zipfile');
            // store uploaded file
            $filename = $file->storeAs('', $file->getClientOriginalName(), Define::DISKNAME_ADMIN_TMP);
            try {
                \Artisan::call('down');
                $result = \Artisan::call('exment:restore', ['file' => $filename, '--tmp' => 1]);
            } finally {
                \Artisan::call('up');
            }
        }
        
        if (isset($result) && $result === 0) {
            admin_toastr(exmtrans('backup.message.restore_file_success'));
            \Auth::guard('admin')->logout();
            $request->session()->invalidate();
            
            return response()->json([
                'result'  => true,
                'toastr' => exmtrans('backup.message.restore_file_success'),
                'redirect' => admin_url(''),
            ]);
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
            try {
                \Artisan::call('down');
                $result = \Artisan::call('exment:restore', ['file' => $data['file']]);
            } finally {
                \Artisan::call('up');
            }
        }

        if (isset($result) && $result === 0) {
            admin_toastr(exmtrans('backup.message.restore_file_success'));
            \Auth::guard('admin')->logout();
            $request->session()->invalidate();

            return response()->json([
                'result'  => true,
                'message' => exmtrans('backup.message.restore_file_success'),
                'redirect' => admin_url(''),
            ]);
        } else {
            return response()->json([
                'result'  => false,
                'toastr' => exmtrans("backup.message.restore_error"),
            ]);
        }
    }
}
