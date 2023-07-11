<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Validator;
use DB;

class BackupController extends AdminControllerBase
{
    protected $backup;
    protected $restore;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("backup.header"), exmtrans("backup.header"), exmtrans("backup.description"), 'fa-database');

        $this->backup = new BackupRestore\Backup();
        $this->backup->initBackupRestore();

        $this->restore = new BackupRestore\Restore();
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
        $disk = $this->backup->disk();

        // check backup execute
        try {
            $this->backup->check();
        } catch (BackupRestoreCheckException $ex) {
            admin_error_once(exmtrans('common.error'), $ex->getMessage());
            return $content;
        }

        // get all archive files
        $files = collect($disk->files('list'))->filter(function ($file) {
            return preg_match('/list\/' . Define::RULES_REGEX_BACKUP_FILENAME . '\.zip$/i', $file);
        })->sortByDesc(function ($file) use ($disk) {
            return $disk->lastModified($file);
        });
        // edit table row data
        $rows = [];
        foreach ($files as $file) {
            $rows[] = [
                'file_key' => pathinfo($file, PATHINFO_FILENAME),
                'file_name' => mb_basename($file),
                'file_size' => bytesToHuman($disk->size($file)),
                'created' => date("Y/m/d H:i:s", $disk->lastModified($file))
            ];
        }

        $rows = $this->restore->list();

        $content->row(view(
            'exment::backup.index',
            [
                'files' => $rows,
                'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD,
                'restore_text' => exmtrans('common.message.execution_takes_time') . exmtrans('backup.message.restore_confirm_text') . exmtrans('common.message.input_keyword', Define::RESTORE_CONFIRM_KEYWORD),
                'editname_text' => exmtrans('backup.message.edit_filename_text'),
            ]
        ));

        // create setting form
        $content->row($this->settingFormBox());


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
            ->help(exmtrans("backup.help.enable_automatic") . sprintf(exmtrans("common.help.task_schedule"), getManualUrl('quickstart_more?id='.exmtrans('common.help.task_schedule_id'))))
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
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
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
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }
    }

    /**
     * Delete interface.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        $disk = $this->backup->disk();

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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws BackupRestoreCheckException
     */
    public function save(Request $request)
    {
        \Exment::setTimeLimitLong();
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

        // validate "\", "/", "."
        $validator = Validator::make(['ymdhms' => $ymdhms], [
            'ymdhms' => ['required', 'regex:/' . Define::RULES_REGEX_BACKUP_FILENAME . '/']
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
     * @param $file_key
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function importModal($file_key = null)
    {
        $import_path = admin_url(url_join('backup', 'import'));
        // create form fields
        $form = new ModalForm();
        $form->modalAttribute('id', 'data_import_modal');

        $fileOption = array_merge(
            Define::FILE_OPTION(),
            [
                'showPreview' => false,
                'dropZoneEnabled' => false,
            ]
        );

        $form->action($import_path);

        if (isset($file_key)) {
            $form->display('restore_zipfile', exmtrans('backup.restore_zipfile'))
                ->setWidth(8, 3)
                ->displayText("$file_key.zip")->escape(false);
            $form->hidden('restore_zipfile')->default($file_key);
        } else {
            $form->file('upload_zipfile', exmtrans('backup.upload_zipfile'))
            ->rules('mimes:zip')->setWidth(8, 3)->addElementClass('custom_table_file')
            ->attribute(['accept' => ".zip"])
            ->removable()
            ->required()
            ->options($fileOption)
            ->help(exmtrans(
                'backup.help.file_name',
                array_get($fileOption, 'maxFileSizeHelp'),
                getManualUrl('backup?id='.exmtrans('backup.filesize_over'))
            ));
        }

        $form->text('restore_keyword', exmtrans('common.keyword'))
            ->required()
            ->setWidth(8, 3)
            ->help(exmtrans('common.message.input_keyword', Define::RESTORE_CONFIRM_KEYWORD));

        $form->display('restore_caution', exmtrans('backup.restore_caution'))
            ->setWidth(8, 3)
            ->displayText(exmtrans('backup.message.restore_caution'))->escape(false);

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
        \Exment::setTimeLimitLong();

        // validation
        $validator = Validator::make($request->all(), [
            'upload_zipfile' => 'required_without:restore_zipfile|file',
            'restore_zipfile' => 'required_without:upload_zipfile',
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

        if ($request->has('restore_zipfile')) {
            $filename = $request->get('restore_zipfile');
            try {
                $result = $this->restore->execute($filename);
            } finally {
            }
        } elseif ($request->has('upload_zipfile')) {
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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function restore(Request $request)
    {
        \Exment::setTimeLimitLong();

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

    /**
     * edit file name
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editname(Request $request)
    {
        $data = $request->all();

        // validate "\", "/", "."
        /** @var ExmentCustomValidator $validator */
        $validator = Validator::make($data, [
            'file' => ['required'],
            'filename' => ['required', 'max:30', 'regex:/^' . Define::RULES_REGEX_BACKUP_FILENAME . '$/'],
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'swal' => exmtrans('common.error'),
                'swaltext' => array_first(array_flatten($validator->getMessages())),
            ]);
        }

        $disk = $this->backup->disk();

        $oldfile = path_join('list', $data['file'] . '.zip');
        $newfile = path_join('list', $data['filename'] . '.zip');

        // check same file name
        if ($disk->exists($newfile)) {
            return getAjaxResponse([
                'result'  => false,
                'swal' => exmtrans('common.error'),
                'swaltext' => exmtrans('backup.message.same_filename'),
            ]);
        }

        if (!$disk->exists($oldfile)) {
            return getAjaxResponse([
                'result'  => false,
                'swal' => exmtrans('common.error'),
                'swaltext' => exmtrans('backup.message.notfound_file'),
            ]);
        }

        // get all archive files
        $disk->move($oldfile, $newfile);

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.update_succeeded'),
        ]);
    }
}
