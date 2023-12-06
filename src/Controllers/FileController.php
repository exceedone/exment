<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Validator\ImageRule;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\LogAction;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\PublicForm;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Response;

class FileController extends AdminControllerBase
{
    /**
     * Download file (call as web)
     */
    public function download(Request $request, $uuid)
    {
        $preview = request()->get('preview');
        if (!$preview) {
            $this->saveLog($uuid, LogAction::DOWNLOAD);
        }
        return static::downloadFile($uuid);
    }

    /**
     * Download file (call as publicform)
     */
    public function downloadPublicForm(Request $request, $publicFormUuid, $uuid)
    {
        return static::downloadFile($uuid);
    }

    /**
     * Download file (call as web)
     */
    public function downloadTable(Request $request, $tableKey, $uuid)
    {
        return static::downloadFile(url_join($tableKey, $uuid));
    }

    /**
     * Delete file (call as web)
     */
    public function delete(Request $request, $uuid)
    {
        $this->saveLog($uuid, LogAction::DELETE);
        return static::deleteFile($uuid);
    }

    /**
     * Delete file (call as web)
     */
    public function deleteTable(Request $request, $tableKey, $uuid)
    {
        return static::deleteFile(url_join($tableKey, $uuid));
    }



    /**
     * Download file (call as Api)
     */
    public function downloadApi(Request $request, $uuid)
    {
        return static::downloadFile(
            $uuid,
            [
            'asBase64' => boolval($request->get('base64', false)),
            'asApi' => true,
        ]
        );
    }

    /**
     * Download file (call as Api)
     */
    public function downloadTableApi(Request $request, $tableKey, $uuid)
    {
        return static::downloadFile(
            url_join($tableKey, $uuid),
            [
            'asBase64' => boolval($request->get('base64', false)),
            'asApi' => true,
        ]
        );
    }

    /**
     * Delete file (call as Api)
     */
    public function deleteApi(Request $request, $uuid)
    {
        return static::deleteFile(
            $uuid,
            [
            'asApi' => true,
        ]
        );
    }

    /**
     * Delete file (call as Api)
     */
    public function deleteTableApi(Request $request, $tableKey, $uuid)
    {
        return static::deleteFile(
            url_join($tableKey, $uuid),
            [
            'asApi' => true,
        ]
        );
    }


    /**
     * Download favicon image
     */
    public function downloadFavicon()
    {
        return static::downloadFileByKey('site_favicon');
    }

    /**
     * Download Login image
     */
    public function downloadLoginBackground()
    {
        return static::downloadFileByKey('login_page_image');
    }

    /**
     * Download Login Header
     */
    public function downloadLoginHeader()
    {
        return static::downloadFileByKey('site_logo');
    }

    /**
     * Download File
     *
     * @param string $key
     * @return mixed
     */
    public static function downloadFileByKey(string $key)
    {
        $record = System::where('system_name', $key)->first();

        if (!isset($record)) {
            abort(404);
        }

        return static::downloadFile($record->system_value);
    }


    /**
     * Download file
     */
    public static function downloadFile($uuid, $options = [])
    {
        $options = array_merge(
            [
                'asApi' => false,
                'asBase64' => false,
            ],
            $options
        );

        $uuid = [$uuid, pathinfo($uuid, PATHINFO_FILENAME)];

        $data = File::getData($uuid);
        if (!$data) {
            if ($options['asApi']) {
                return abortJson(404, ErrorCode::DATA_NOT_FOUND());
            }
            abort(404);
        }

        $path = $data->path;
        $exists = Storage::disk(config('admin.upload.disk'))->exists($path);

        if (!$exists) {
            if ($options['asApi']) {
                return abortJson(404, ErrorCode::DATA_NOT_FOUND());
            }
            abort(404);
        }

        // if has parent_id, check permission
        if (isset($data->parent_id) && isset($data->parent_type)) {
            $custom_table = CustomTable::getEloquent($data->parent_type);
            if (!$custom_table->hasPermissionData($data->parent_id)) {
                if ($options['asApi']) {
                    return abortJson(403, ErrorCode::PERMISSION_DENY());
                }

                abort(403);
            }
        }

        $file = Storage::disk(config('admin.upload.disk'))->get($path);
        $type = Storage::disk(config('admin.upload.disk'))->mimeType($path);
        // get page name
        $name = rawurlencode($data->filename);

        if ($options['asBase64']) {
            return response([
                'type' => $type,
                'name' => $data->filename,
                'base64' => base64_encode(($file)),
            ]);
        }

        // create response
        $response = Response::make($file, 200);
        $response->header("Content-Type", $type);

        // Disposition is attachment because inline is SVG XSS.
        $disposition = static::isDispositionInline($name) ? 'inline' : 'attachment';
        $response->header('Content-Disposition', "$disposition; filename*=UTF-8''$name");

        return $response;
    }

    // Save log download or delete of attach column file and download custom column file
    public function saveLog($uuid, $action)
    {
        $uuid_file = pathinfo($uuid, PATHINFO_FILENAME);
        $file = \DB::table('files')->where('uuid', $uuid_file)->first();
        if ($file) {
            $custom_table = CustomTable::getEloquent($file->parent_type);
            if ($custom_table && System::log_available() && array_get($custom_table, 'options.log_enabled')) {
                $log_table = CustomTable::getEloquent(SystemTableName::LOG_TABLE)
                    ->getValueModel()->where('value->table_id', $custom_table->id)->first();
                if ($log_table) {
                    if ($file->custom_column_id) {
                        $log_column = CustomTable::getEloquent(SystemTableName::LOG_COLUMN)->getValueModel()
                            ->where('parent_id', $log_table->id)
                            ->where('value->column_id', $file->custom_column_id)
                            ->first();
                        if (!$log_column) {
                            $log_column = CustomTable::getEloquent(SystemTableName::LOG_COLUMN)->getValueModel();
                            $log_column->parent_type = SystemTableName::LOG_TABLE;
                            $log_column->parent_id = $log_table->id;
                            $log_column->setValue('column_id', $file->custom_column_id);
                            $column = CustomColumn::find($file->custom_column_id);
                            if ($column) {
                                $column_view_name = $column->column_view_name;
                                $log_column->setValue('column_name', $column_view_name);
                            }
                            $log_column->save();
                        }
                    } else {
                        $log_column = CustomTable::getEloquent(SystemTableName::LOG_COLUMN)->getValueModel()
                            ->where('parent_id', $log_table->id)
                            ->where('value->column_name', exmtrans('system.attach_file'))
                            ->first();
                        if (!$log_column) {
                            $log_column = CustomTable::getEloquent(SystemTableName::LOG_COLUMN)->getValueModel();
                            $log_column->parent_type = SystemTableName::LOG_TABLE;
                            $log_column->parent_id = $log_table->id;
                            $log_column->setValue('column_name', exmtrans('system.attach_file'));
                            $log_column->save();
                        }
                    }
                    $org_ids = \Exment::user()->belong_organizations()->getResults()->pluck('id')->toArray();
                    $access_file_log = CustomTable::getEloquent(SystemTableName::ACCESS_FILE_LOG)->getValueModel();
                    $access_file_log->setValue('user', \Exment::user()->base_user->id);
                    $access_file_log->setValue('ip_address', request()->getClientIp());
                    $access_file_log->setValue('table', $log_table->id);
                    $access_file_log->setValue('column', $log_column->id);
                    $access_file_log->setValue('data_id', $file->parent_id);
                    $access_file_log->setValue('file_name', $file->filename);
                    $access_file_log->setValue('user_action', $action);
                    $access_file_log->setValue('org', $org_ids);
                    $access_file_log->save();
                }
            }
        }
    }

    /**
     * Download temporary upload file
     */
    public static function downloadTemp($uuid, $options = [])
    {
        $filename = pathinfo($uuid, PATHINFO_FILENAME);

        $exists = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->exists($uuid);

        if (!$exists) {
            abort(404);
        }

        $file = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->get($uuid);
        $type = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->mimeType($uuid);
        // get page name
        $name = rawurlencode($filename);

        // create response
        $response = Response::make($file, 200);
        $response->header("Content-Type", $type);

        // Disposition is attachment because inline is SVG XSS.
        $response->header('Content-Disposition', "attachment; filename*=UTF-8''$name");

        return $response;
    }

    /**
     * Delete file and document info
     */
    public static function deleteFile($uuid, $options = [])
    {
        $options = array_merge(
            [
                'removeDocumentInfo' => true,
                'removeFileInfo' => true,
                'asApi' => false,
            ],
            $options
        );
        $data = File::getData($uuid);
        if (!$data) {
            if ($options['asApi']) {
                return abortJson(404, ErrorCode::DATA_NOT_FOUND());
            }
            abort(404);
        }

        // if not has delete setting, abort 403
        if (!$options['asApi'] && boolval(config('exment.file_delete_useronly', false)) && $data->created_user_id != \Exment::getUserId()) {
            abort(403);
        }

        // if has parent_id, check permission
        $checkParentPermission = static::checkParentPermission($data, $options);
        if ($checkParentPermission !== true) {
            return $checkParentPermission;
        }

        $path = $data->path;
        $exists = Storage::disk(config('admin.upload.disk'))->exists($path);

        // if exists, delete file
        if ($exists) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }

        // if has document, remove document info
        if (boolval($options['removeDocumentInfo'])) {
            File::deleteDocumentModel($uuid, false);
        }

        // delete file info
        if (boolval($options['removeFileInfo'])) {
            $file = File::getData($uuid);
            File::deleteFileInfo($file);
        }

        if ($options['asApi']) {
            return response(null, 204);
        }

        return response([
            'status'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }

    /**
     *  Delete temporary files that are one day old
     */
    protected function removeTempFiles()
    {
        $disk = Storage::disk(Define::DISKNAME_TEMP_UPLOAD);

        // get all temporary files
        $filenames = $disk->files();

        // get file infos
        $files = collect($filenames)->map(function ($filename) use ($disk) {
            return [
                'name' => $filename,
                'lastModified' => $disk->lastModified($filename),
            ];
        })->sortBy('lastModified');

        // remove file
        foreach ($files->values()->all() as $file) {
            $past = time() - array_get($file, 'lastModified');
            if ($past < 24 * 60 * 60) {
                break;
            }

            $disk->delete(array_get($file, 'name'));
        }
    }

    /**
     *  upload file as temporary
     */
    protected function uploadTempFile(Request $request)
    {
        return $this->_uploadTempFile($request, false);
    }

    /**
     *  upload Image as temporary
     */
    protected function uploadTempImage(Request $request)
    {
        return $this->_uploadTempFile($request, true);
    }

    /**
     *  upload Image as temporary
     */
    protected function uploadTempImagePublicForm(Request $request, $publicFormUuid)
    {
        $public_form = PublicForm::getPublicFormByUuid($publicFormUuid);
        return $this->_uploadTempFile($request, true, $public_form);
    }

    /**
     *  upload file as temporary
     */
    protected function _uploadTempFile(Request $request, bool $isImage, ?PublicForm $public_form = null)
    {
        // delete old temporary files
        $this->removeTempFiles();

        // check image file.
        $rules = [
            'file' => ['required']
        ];
        if ($isImage) {
            $rules['file'][] = new ImageRule();
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(array_get($validator->errors()->toArray(), 'file'), 400);
        }

        // get upload file
        $file = $request->file('file');
        $original_name = $file->getClientOriginalName();
        $uuid = make_uuid();
        // store uploaded file
        $filename = $file->storeAs('', $uuid, Define::DISKNAME_TEMP_UPLOAD);
        try {
            $request->session()->put($uuid, $original_name);
        } catch (\Exception $e) {
        }

        // If this request is as public_form, return as url
        if ($public_form) {
            $localtion = $public_form->getUrl('tmpfiles', basename($filename));
        } else {
            $localtion = admin_urls('tmpfiles', basename($filename));
        }
        return json_encode(['location' => $localtion]);
    }

    /**
     * Download temporary saved file
     */
    public function downloadTempFile(Request $request, $uuid)
    {
        // delete old temporary files
        $this->removeTempFiles();

        return static::downloadTemp($uuid);
    }

    /**
     * Download temporary saved file
     */
    public function downloadTempFilePublicForm(Request $request, $publicFormUuid, $uuid)
    {
        // delete old temporary files
        $this->removeTempFiles();

        return static::downloadTemp($uuid);
    }


    /**
     * Check parent table's permission
     *
     * @param mixed $data
     * @return true|\Symfony\Component\HttpFoundation\Response
     */
    protected static function checkParentPermission($data, array $options = [])
    {
        $options = array_merge(
            [
                'asApi' => false,
            ],
            $options
        );
        if (!$data || is_nullorempty($data->parent_id) || is_nullorempty($data->parent_type)) {
            return true;
        }

        // if has parent_id, check permission
        $parent_custom_table = CustomTable::getEloquent($data->parent_type);
        if (!$parent_custom_table) {
            return true;
        }
        $custom_value = $parent_custom_table->getValueModel($data->parent_id);
        if ($custom_value && $custom_value->enableDelete() !== true) {
            if ($options['asApi']) {
                return abortJson(403, ErrorCode::PERMISSION_DENY());
            }
            abort(403);
        }

        return true;
    }


    /**
     * Whether this file downloads as inline
     *
     * @param string $fileName
     * @return boolean
     */
    protected static function isDispositionInline($fileName): bool
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        if (is_nullorempty($ext)) {
            return false;
        }

        // get inlines
        $inlines = stringToArray(config('exment.file_download_inline_extensions', []));
        $inlines = collect($inlines)->map(function ($inline) {
            return strtolower($inline);
        })->filter()->toArray();

        return in_array(strtolower($ext), $inlines);
    }
}
