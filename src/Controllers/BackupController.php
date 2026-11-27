<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use ExmentAdminCore\Admin\Widgets\Box;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRunLockedException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Validator;
use DB;

class BackupController extends AdminControllerBase
{

    // @phpstan-ignore-next-line
    protected $backup;
    // @phpstan-ignore-next-line
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
        static::denyUnlessSystem();
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
        // @phpstan-ignore-next-line
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

        // the run going on, and the history (ISS02943)
        BackupRuns::markStalled();
        $active = BackupRuns::active();
        $content->row(view('exment::backup.progress', [
            'active' => isset($active) ? $active->toStatusArray() : null,
            'statusUrl' => admin_urls('backup', 'runs'),
            'pollMs' => max(1000, intval(config('exment.backup.poll_ms', 2000))),
            'loginUrl' => admin_url('auth/login'),
        ]));

        $content->row(view(
            'exment::backup.index',
            [
                'files' => $rows,
                'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD,
                'restore_text' => exmtrans('common.message.execution_takes_time') . exmtrans('backup.message.restore_confirm_text') . exmtrans('common.message.input_keyword', Define::RESTORE_CONFIRM_KEYWORD),
                'editname_text' => exmtrans('backup.message.edit_filename_text'),
            ]
        ));

        $content->row(view('exment::backup.history', [
            'runs' => $this->historyRows(),
            'keepRuns' => max(1, intval(config('exment.backup.keep_runs', 100))),
            'space' => BackupRestore\Backup::space(),
        ]));

        // create setting form
        $content->row($this->settingFormBox());


        return $content;
    }

    /**
     * Only the system administrators, on every action (the menu and the route permission are
     * checked before; this is checked again here). On a site where the roles are not used,
     * Auth\Permission lets everyone in: then the system administrators of the settings only.
     */
    protected static function denyUnlessSystem(): void
    {
        $user = \Exment::user();
        if (!isset($user)) {
            abort(403);
        }
        $allowed = boolval(System::permission_available())
            ? boolval($user->hasPermission(Enums\Permission::SYSTEM))
            : boolval($user->isAdministrator());
        if (!$allowed) {
            abort(403);
        }
    }

    /**
     * What a backup of the screen holds (ISS02943): all the targets of the settings, the
     * database only, or the files only (the targets of the settings without the database; all
     * the folders when the settings have none).
     *
     * @param array<mixed> $settings
     * @return array<int, string>
     */
    public static function targetsOfScope(string $scope, array $settings): array
    {
        $settings = array_values(array_filter(array_map('strval', $settings), fn ($t) => in_array($t, Enums\BackupTarget::arrays(), true)));
        if ($scope === 'database') {
            return [Enums\BackupTarget::DATABASE];
        }
        if ($scope === 'files') {
            $files = array_values(array_diff($settings, [Enums\BackupTarget::DATABASE]));
            return count($files) > 0 ? $files : array_values(array_diff(Enums\BackupTarget::arrays(), [Enums\BackupTarget::DATABASE]));
        }
        return count($settings) > 0 ? $settings : Enums\BackupTarget::arrays();
    }

    /**
     * The runs of the history (the newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function historyRows(): array
    {
        $limit = max(1, min(500, intval(config('exment.backup.history_rows', 50))));
        // by the time it was made (a restore puts the runs back with new ids)
        $runs = BackupRun::query()->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $names = [];
        $rows = [];
        foreach ($runs as $run) {
            /** @var BackupRun $run */
            $userId = $run->created_user_id;
            if (isset($userId) && !array_key_exists($userId, $names)) {
                $login = LoginUser::find($userId);
                $names[$userId] = isset($login) ? strval($login->user_name) : null;
            }
            $rows[] = array_merge($run->toStatusArray(), [
                'trigger_label' => strval(exmtrans("backup.run.triggers.{$run->triggered_by}")),
                'user_name' => isset($userId) ? ($names[$userId] ?? null) : null,
                'file_size_text' => isset($run->file_size) ? bytesToHuman($run->file_size) : null,
                'duration_text' => isset($run->started_at) ? BackupRestore\RunProgress::duration(intval($run->elapsedSeconds())) : null,
                'has_log' => is_file(BackupRuns::logFullPath($run)),
            ]);
        }
        return $rows;
    }

    /**
     * The run going on now (null: none), for the screen that polls.
     */
    public function runCurrent(Request $request): \Illuminate\Http\JsonResponse
    {
        static::denyUnlessSystem();

        return response()->json(['run' => BackupRuns::currentStatus()]);
    }

    /**
     * The progress of a run, for the screen that polls.
     */
    public function runStatus(Request $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        static::denyUnlessSystem();
        // the record, or its status file while a restore has the table replaced
        $status = BackupRuns::status($uuid);
        if (!isset($status)) {
            abort(404);
        }

        return response()->json(['run' => $status]);
    }

    /**
     * A run: its stages and its log.
     *
     * @return Content
     */
    public function runShow(Request $request, Content $content, string $uuid)
    {
        static::denyUnlessSystem();
        $run = $this->findRun($uuid);
        $this->AdminContent($content);

        $log = BackupRuns::readLog($run);
        $maxLines = max(100, intval(config('exment.backup.log_view_lines', 2000)));
        $lines = isset($log) ? preg_split('/\r\n|\n/', rtrim($log)) : [];
        $lines = is_array($lines) ? $lines : [];
        $cut = count($lines) > $maxLines;
        if ($cut) {
            $lines = array_slice($lines, -$maxLines);
        }

        $content->row(view('exment::backup.run', [
            'run' => $run->toStatusArray(),
            'trigger' => strval(exmtrans("backup.run.triggers.{$run->triggered_by}")),
            'user_name' => isset($run->created_user_id) ? LoginUser::find($run->created_user_id)?->user_name : null,
            'file_size_text' => isset($run->file_size) ? bytesToHuman($run->file_size) : null,
            'lines' => $lines,
            'cut' => $cut,
            'maxLines' => $maxLines,
            'hasLog' => isset($log),
            'downloadUrl' => admin_urls('backup', 'runs', $run->uuid, 'log'),
            'statusUrl' => admin_urls('backup', 'runs'),
            'backUrl' => admin_url('backup'),
            'loginUrl' => admin_url('auth/login'),
            'pollMs' => max(1000, intval(config('exment.backup.poll_ms', 2000))),
        ]));

        return $content;
    }

    /**
     * Download the log of a run (masked), written in the operation log.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function runLog(Request $request, string $uuid)
    {
        static::denyUnlessSystem();
        $run = $this->findRun($uuid);
        $log = BackupRuns::readLog($run);
        if (!isset($log)) {
            abort(404);
        }
        BackupRuns::operationLog($run, 'download');

        $at = $run->started_at ?? $run->created_at;
        $name = sprintf('backup-%s-%s.log', $run->kind, isset($at) ? $at->format('YmdHis') : $run->uuid);

        return response($log, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The page of a restore started here, with the token of its state after "#": a browser never
     * sends that part to a server (nor in a Referer), the page keeps it in the tab and takes it out
     * of the address. A path, so the screen opens it as a whole page.
     */
    protected static function restorePageUrl(BackupRun $run): string
    {
        $path = strval(parse_url(admin_urls('backup', 'runs', $run->uuid), PHP_URL_PATH));
        return $path . '#t=' . BackupRuns::issueStateToken($run);
    }

    protected function findRun(string $uuid): BackupRun
    {
        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)) {
            abort(404);
        }
        /** @var BackupRun|null $run */
        $run = BackupRun::query()->where('uuid', $uuid)->first();
        if (!isset($run)) {
            abort(404);
        }
        return $run;
    }

    // @phpstan-ignore-next-line
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

        $form->switchbool('backup_history_include_manual', exmtrans("backup.history_include_manual"))
            ->help(exmtrans("backup.help.history_include_manual"))
            ->default(false);

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
        static::denyUnlessSystem();
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
        static::denyUnlessSystem();
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
        static::denyUnlessSystem();
        \Exment::setTimeLimitLong();

        $target = static::targetsOfScope(strval($request->get('scope', 'all')), (array) System::backup_target());

        // one at a time; to the queue when its worker runs, else here as before (ISS02943)
        $queued = BackupRuns::queueAvailable();
        try {
            $run = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_SCREEN, ['targets' => (array) $target], $queued);
        } catch (BackupRunLockedException $e) {
            return response()->json([
                'result'  => false,
                'message' => $e->getMessage(),
                'run' => $e->active?->uuid,
            ]);
        }

        if ($queued) {
            BackupRuns::dispatch($run);
            return response()->json([
                'result'  => true,
                'queued' => true,
                'run' => $run->uuid,
                'message' => exmtrans('backup.run.message.queued'),
            ]);
        }

        try {
            $result = BackupRuns::runBackup($run, $target);
        } catch (BackupRestoreCheckException $e) {
            return response()->json([
                'result'  => false,
                'run' => $run->uuid,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            // told in the run (its log, the bell and the mail)
            $result = -1;
        }

        if ($result === 0) {
            return response()->json([
                'result'  => true,
                'run' => $run->uuid,
                'message' => trans('admin.save_succeeded'),
            ]);
        } else {
            return response()->json([
                'result'  => false,
                'run' => $run->uuid,
                'message' => exmtrans("backup.message.backup_error"),
            ]);
        }
    }

    /**
     * Download file
     */
    // @phpstan-ignore-next-line
    public function download($arg)
    {
        static::denyUnlessSystem();
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
    public function importModal($file_key = null)
    {
        static::denyUnlessSystem();
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

            // what the restore replaces (ISS02943)
            $preview = $this->restore->preview(strval($file_key));
            $form->display('restore_preview', exmtrans('backup.run.screen.preview'))
                ->setWidth(8, 3)
                ->displayText(view('exment::backup.preview', ['preview' => $preview])->render())->escape(false);
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

        // a backup of the data now, before it is replaced (ISS02943)
        $form->switchbool('backup_first', exmtrans('backup.run.screen.backup_first_option'))
            ->default('1')
            ->setWidth(8, 3)
            ->help(exmtrans('backup.run.screen.backup_first_help'));

        $form->text('restore_keyword', exmtrans('common.keyword'))
            ->required()
            ->setWidth(8, 3)
            ->help(exmtrans('common.message.input_keyword', Define::RESTORE_CONFIRM_KEYWORD));

        if (!BackupRuns::detachAvailable()) {
            $form->display('restore_command_note', exmtrans('backup.run.screen.restore_in_request'))
                ->setWidth(8, 3)
                ->displayText(e(exmtrans('backup.run.screen.restore_command_note')));
        }

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
    public function import(Request $request)
    {
        static::denyUnlessSystem();
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

        try {
            if ($request->has('restore_zipfile')) {
                $filename = $request->get('restore_zipfile');
                $result = $this->runRestore(strval($filename), null, boolval($request->get('backup_first')));
            } elseif ($request->has('upload_zipfile')) {
                // get upload file
                $file = $request->file('upload_zipfile');
                // store uploaded file
                $filename = $file->storeAs('', $file->getClientOriginalName(), Define::DISKNAME_ADMIN_TMP);
                $result = $this->runRestore(strval($filename), true, boolval($request->get('backup_first')));
            }
            // it goes on apart: its page shows the progress
            if (isset($result) && $result instanceof BackupRun) {
                return response()->json([
                    'result'  => true,
                    'toastr' => exmtrans('backup.run.message.restore_started'),
                    'redirect' => static::restorePageUrl($result),
                ]);
            }
        } catch (BackupRunLockedException $e) {
            return getAjaxResponse([
                'result' => false,
                'toastr' => $e->getMessage(),
                'errors' => [],
            ]);
        }

        if (isset($result) && $result === 0) {
            // The uploaded / selected zip in admin_tmp has been consumed by the restore.
            // Delete it here or it stays forever: the admin_tmp disk is hardcoded to the
            // local driver and nothing else ever cleans it (the empty finally blocks
            // above were the intended place but were never filled). Kept on FAILURE on
            // purpose, so the user can retry from the already-uploaded file without
            // re-uploading; abandoned files are collected by the host tmp sweeper.
            if (isset($filename)) {
                try {
                    \Storage::disk(Define::DISKNAME_ADMIN_TMP)->delete($filename);
                } catch (\Throwable $e) {
                    // cleanup must never break a successful restore response
                }
            }
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
        static::denyUnlessSystem();
        \Exment::setTimeLimitLong();

        $data = $request->all();

        $validator = Validator::make($data, [
            'file' => 'required',
        ]);

        if ($validator->passes()) {
            try {
                $result = $this->runRestore(strval($data['file']));
            } catch (BackupRunLockedException $e) {
                return response()->json([
                    'result'  => false,
                    'toastr' => $e->getMessage(),
                ]);
            }
            if ($result instanceof BackupRun) {
                return response()->json([
                    'result'  => true,
                    'toastr' => exmtrans('backup.run.message.restore_started'),
                    'redirect' => static::restorePageUrl($result),
                ]);
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
     * A restore of the screen, recorded (ISS02943). Never in the queue (it replaces the database
     * the queue and the sessions are in): in a process of its own (the command), so the request
     * answers at once and its page shows the progress; where PHP cannot start a command, here.
     *
     * @return int|BackupRun the result of Restore::execute (0: done), or the run going on apart
     * @throws BackupRunLockedException
     * @throws \Exception a refused file (InvalidZipEntryException) and the others, as before
     */
    protected function runRestore(string $file, ?bool $tmp = null, bool $backupFirst = false)
    {
        $detach = BackupRuns::detachAvailable();
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN, [
            'file_name' => pathinfo($file, PATHINFO_FILENAME),
            // where the file is, the file, and the backup before the restore (the command apart reads them)
            'targets' => array_merge([boolval($tmp) ? BackupRuns::SOURCE_UPLOAD : BackupRuns::SOURCE_STORED, $file], $backupFirst ? [BackupRuns::BACKUP_FIRST] : []),
        ], $detach);

        if ($detach && BackupRuns::launchRestore($run)) {
            return $run;
        }

        // here: as long as it takes, also when the browser gives up
        @set_time_limit(0);
        ignore_user_abort(true);
        return BackupRuns::runRestore($run, $this->restore, $file, $tmp, null, $backupFirst);
    }

    /**
     * edit file name
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editname(Request $request)
    {
        static::denyUnlessSystem();
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
