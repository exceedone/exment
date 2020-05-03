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
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Services\BackupRestore;
use Validator;
use DB;

class BackupController extends AdminControllerBase
{
    protected $backup;
    protected $restore;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("backup.header"), exmtrans("backup.header"), exmtrans("backup.description"), 'fa-database');

        $this->backup = new BackupRestore\Backup;
        $this->backup->initBackupRestore();

        $this->restore = new BackupRestore\Restore;
        $this->restore->initBackupRestore();
    }
    
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        
        $checkBackup = $this->backup->check();

        if(!$checkBackup){
            //TODO:エラーメッセージ
        }

        $rows = $this->restore->list();

        $content->row(view(
            'exment::backup.index',
            [
                'files' => $rows,
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
        $this->initBackupRestore();
        $disk = $this->disk();
        
        $data = $request->all();

        $validator = Validator::make($data, [
            'files' => 'required',
        ]);

        if ($validator->passes()) {
            $files = explode(',', $data['files']);
            foreach ($files as $file) {
                $path = path_join('list', $file . '.zip');
                if ($disk->exists($path)) {
                    $disk->delete($path);
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
        setTimeLimitLong();
        $data = $request->all();

        $target = System::backup_target();

        $result = $this->backup->execute($target);

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

        $this->backup->initBackupRestore($ymdhms);
        $disk = $this->backup->disk();
        
        $path = $this->backup->diskService()->diskItem()->filePath();
        $exists = $disk->exists($path);
        if (!$exists) {
            abort(404);
        }

        return downloadFile($path, $disk);
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
        $form = new ModalForm();
        $form->modalAttribute('id', 'data_import_modal');

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

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('backup.restore')
        ]);
    }

    /**
     * Upload zip file
     */
    protected function import(Request $request)
    {
        setTimeLimitLong();

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
                $result = $this->restore->execute($filename, true);
            } finally {
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
                $result = $this->restore->execute($data['file']);
            } finally {
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
