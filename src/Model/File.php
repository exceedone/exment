<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Services\Uuids;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Facades\Storage;
use Webpatser\Uuid\Uuid;
use Response;

/**
 * Exment file model.
 * uuid: primary key. it uses as url.
 * path: local file path. it often sets "folder"/"uuid".
 * filename: for download or display name
 */
class File extends ModelBase
{
    use Uuids;
    protected $guarded = ['uuid'];
    // Primary key setting
    protected $primaryKey = 'uuid';
    // increment disable
    public $incrementing = false;

    public function getPathAttribute()
    {
        return path_join($this->local_dirname, $this->local_filename);
    }

    /**
     * save document model
     */
    public function saveDocumentModel($custom_value, $document_name)
    {
        // save Document Model
        $document_model = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel();
        $document_model->parent_id = $custom_value->id;
        $document_model->parent_type = $custom_value->custom_table->table_name;
        $document_model->setValue([
            'file_uuid' => $this->uuid,
            'document_name' => $document_name,
        ]);
        $document_model->save();
        return $document_model;
    }

    public function saveCustomValue($custom_value, $custom_column = null)
    {
        if (isset($custom_value)) {
            $this->parent_id = $custom_value->id;
            $this->parent_type = $custom_value->custom_table->table_name;
        }
        if (isset($custom_column)) {
            $custom_column = CustomColumn::getEloquent($custom_column, $custom_value->custom_table);
            $this->custom_column_id = $custom_column->id;
        }
        $this->save();
        return $this;
    }

    /**
     * get the file url
     * @return void
     */
    public static function getUrl($path)
    {
        $file = static::getData($path);
        if (is_null($file)) {
            return null;
        }
        return admin_url("files/".$file->uuid);
    }

    /**
     * Save file info to database.
     * *Please call this function before store file.
     * @param string $fileName
     * @return File saved file path
     */
    public static function saveFileInfo(string $dirname, string $filename = null, $local_filename = null, $override = false)
    {
        $uuid = make_uuid();

        if (!isset($filename)) {
            list($dirname, $filename) = static::getDirAndFileName($dirname);
        }

        if (!isset($local_filename)) {
            //$local_filename = static::getUniqueFileName($dirname, $filename);
            $local_filename = $filename;
        }
        
        // get unique name. if different and not override, change name
        $unique_filename = static::getUniqueFileName($dirname, $filename);
        if (!$override && $local_filename != $unique_filename) {
            $local_filename = $unique_filename;
        }

        $file = new self;
        $file->uuid = $uuid;
        $file->local_dirname = $dirname;
        $file->local_filename = $local_filename;
        $file->filename = $filename;

        $file->save();
        return $file;
    }

    /**
     * delete file info to database
     * @param string|File $file
     * @return void
     */
    public static function deleteFileInfo($file)
    {
        if (is_string($file)) {
            $file = static::getData($file);
        }

        if (is_null($file)) {
            return;
        }
        $path = $file->path;
        $file->delete();

        Storage::disk(config('admin.upload.disk'))->delete($path);
    }

    /**
     * Download file
     */
    public static function downloadFile($uuid)
    {
        $data = static::getData($uuid);
        if (!$data) {
            abort(404);
        }
        $path = $data->path;
        $exists = Storage::disk(config('admin.upload.disk'))->exists($path);
        
        if (!$exists) {
            abort(404);
        }

        // if has parent_id, check permission
        if (isset($data->parent_id) && isset($data->parent_type)) {
            $custom_table = CustomTable::getEloquent($data->parent_type);
            if (!$custom_table->hasPermissionData($data->parent_id)) {
                abort(403);
            }
        }

        $file = Storage::disk(config('admin.upload.disk'))->get($path);
        $type = Storage::disk(config('admin.upload.disk'))->mimeType($path);
        // get page name
        $name = rawurlencode($data->filename);
        // create response
        $response = Response::make($file, 200);
        $response->header("Content-Type", $type);
        $response->header('Content-Disposition', "inline; filename*=UTF-8''$name");

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
            ],
            $options
        );
        $data = static::getData($uuid);
        if (!$data) {
            abort(404);
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
            $file = static::getData($uuid);
            static::deleteFileInfo($file);
        }

        return response([
            'status'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }

    /**
     * get file object(laravel)
     */
    public static function getFile($uuid, Closure $authCallback = null)
    {
        $data = static::getData($uuid);
        if (!$data) {
            return null;
        }
        $path = $data->path;
        $exists = Storage::disk(config('admin.upload.disk'))->exists($path);
        
        if (!$exists) {
            return null;
        }
        if ($authCallback) {
            $authCallback($data);
        }

        return Storage::disk(config('admin.upload.disk'))->get($path);
    }

    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param  string  $disk disk name
     * @param  string  $path directory path
     * @return string|false
     */
    public static function put($path, $content, $override = false)
    {
        $file = static::saveFileInfo($path, null, null, $override);
        Storage::disk(config('admin.upload.disk'))->put($file->path, $content);
        return $file;
    }
    
    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param  string  $content file content
     * @param  string  $disk disk name
     * @param  string  $path directory path
     * @return string|false
     */
    public static function store($content, $dirname)
    {
        $file = static::saveFileInfo($dirname, null, null, false);
        $content->store($file->local_dirname, config('admin.upload.disk'));
        return $file;
    }
    
    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param  string  $content file content
     * @param  string  $dirname directory path
     * @param  string  $name file name. the name is shown by display
     * @param  string  $local_filename local file name.
     * @param  bool  $override if file already exists, override
     * @return string|false
     */
    public static function storeAs($content, $dirname, $name, $local_filename = null, $override = false)
    {
        $file = static::saveFileInfo($dirname, $name, $local_filename, $override);
        $content->storeAs($dirname, $file->local_filename, config('admin.upload.disk'));
        return $file;
    }

    /**
     * Get file model using path or uuid
     */
    protected static function getData($pathOrUuid)
    {
        // get by uuid
        $file = static::where('uuid', $pathOrUuid)->first();
        if (is_null($file)) {
            // get by $dirname, $filename
            list($dirname, $filename) = static::getDirAndFileName($pathOrUuid);
            $file = static::where('local_dirname', $dirname)
                ->where('local_filename', $filename)
                ->first();
            if (is_null($file)) {
                return null;
            }
        }
        return $file;
    }
    
    /**
     * get unique file name
     */
    public static function getUniqueFileName($dirname, $filename = null)
    {
        if (!isset($filename)) {
            list($dirname, $filename) = static::getDirAndFileName($dirname);
        }

        // create file name.
        // get ymdhis string
        $path = url_join($dirname, $filename);

        // check file exists
        // if exists, use uuid
        if (\File::exists(getFullpath($path, config('admin.upload.disk')))) {
            $ext = file_ext($filename);
            return make_uuid() . (!is_nullorempty($ext) ? '.'.$ext : '');
        }
        return $filename;
    }

    /**
     * get directory and filename from path
     */
    public static function getDirAndFileName($path)
    {
        $dirname = dirname($path);
        $filename = mb_basename($path);
        return [$dirname, $filename];
    }
}
