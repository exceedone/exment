<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\File;
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
        $response->header('Content-Disposition', "attachment; filename*=UTF-8''$name");

        return $response;
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
        if (isset($data->parent_id) && isset($data->parent_type)) {
            $custom_value = CustomTable::getEloquent($data->parent_type)->getValueModel($data->parent_id);
            if ($custom_value->enableDelete() !== true) {
                if ($options['asApi']) {
                    return abortJson(403, ErrorCode::PERMISSION_DENY());
                }
                abort(403);
            }
        }

        $path = $data->path;
        $exists = Storage::disk(config('admin.upload.disk'))->exists($path);
        
        // if exists, delete file
        if ($exists) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }

        // if has document, remove document info
        if (boolval($options['removeDocumentInfo'])) {
            $column_name = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getIndexColumnName('file_uuid');
        
            // delete document info
            getModelName(SystemTableName::DOCUMENT)
                ::where($column_name, $uuid)
                ->delete();
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
        // delete old temporary files
        $this->removeTempFiles();
        
        // check image file. *NOW Only this endpoint is image*
        $validator = \Validator::make($request->all(), [
            'file' => 'required|image'
        ]);
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
        return json_encode(['location' => admin_urls('tmpfiles', basename($filename))]);
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
}
