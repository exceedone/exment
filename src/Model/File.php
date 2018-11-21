<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Services\Uuids;
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

    public function getPathAttribute(){
        return path_join($this->local_dirname, $this->local_filename);
    }

    /**
     * save document model
     */
    public function saveDocumentModel($custom_value, $document_name){
        // save Document Model
        $modelname = getModelName(Define::SYSTEM_TABLE_NAME_DOCUMENT);
        $document_model = new $modelname;
        $document_model->parent_id = $custom_value->id;
        $document_model->parent_type = $custom_value->getCustomTable()->table_name;
        $document_model->setValue([
            'file_uuid' => $this->uuid,
            'document_name' => $document_name,
        ]);
        $document_model->save();
        return $document_model;
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
     * path is dir + $filename + ymdhis + extension
     * @param string $fileName
     * @return File saved file path
     */
    public static function saveFileInfo(string $dirname, string $filename = null, $local_filename = null)
    {
        $uuid = make_uuid();

        if(!isset($filename)){
            list($dirname, $filename) = static::getDirAndFileName($dirname);
        }

        if(!isset($local_filename)){
            $local_filename = static::getUniqueFileName($dirname, $filename);
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
     * @param string $fileName
     * @return void
     */
    public static function deleteFileInfo(string $pathOrUuid)
    {
        $file = static::getData($path);
        if (is_null($file)) {
            return;
        }
        $file->delete();
    }

    /**
     * Download file
     */
    public static function download($uuid, Closure $authCallback = null)
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
        if ($authCallback) {
            $authCallback($data);
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
     * Delete file
     */
    public static function deleteFile($uuid)
    {
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
        $column_name = getColumnNameByTable(Define::SYSTEM_TABLE_NAME_DOCUMENT, 'file_uuid');
        
        // delete
        getModelName(Define::SYSTEM_TABLE_NAME_DOCUMENT)
            ::where($column_name, $uuid)
            ->delete();
    
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
     * get unique file name
     */
    public static function getUniqueFileName($dirname, $filename = null){
        if(!isset($filename)){
            list($dirname, $filename) = static::getDirAndFileName($dirname);
        }

        // create file name.
        // get ymdhis string
        $path = url_join($dirname, $filename);

        // check file exists
        // if exists, use uuid
        if(\File::exists(getFullpath($path, config('admin.upload.disk')))){
            return make_uuid() . '.'.file_ext($filename);
        }   
        return $filename;
    }

    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param  string  $disk disk name
     * @param  string  $path directory path
     * @param  array|string  $options
     * @return string|false
     */
    public static function put($path, $content, $options = [])
    {
        $path = Storage::disk(config('admin.upload.disk'))->put($path, $content, $options);
        $file = static::saveFileInfo($path);
        return $file;
    }
    
    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param  string  $disk disk name
     * @param  string  $path directory path
     * @param  array|string  $options
     * @return string|false
     */
    public static function putAs($path, $content, $options = [])
    {
        $path = Storage::disk(config('admin.upload.disk'))->put($path, $content, $options);
        $file = static::saveFileInfo($path);
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
    public static function store($content, $path)
    {
        $content->store($path, config('admin.upload.disk'));
        $file = static::saveFileInfo($path);
        return $file;
    }
    
    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param  string  $content file content
     * @param  string  $disk disk name
     * @param  string  $path directory path
     * @param  string  $name file name
     * @return string|false
     */
    public static function storeAs($content, $path, $name, $local_filename = null)
    {
        if(!isset($local_filename)){$local_filename = $name;}
        $content->storeAs($path, $local_filename, config('admin.upload.disk'));
        $file = static::saveFileInfo($path, $name, $local_filename);
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
     * get directory and filename from path
     */
    protected static function getDirAndFileName($path){
        $dirname = dirname($path);
        $filename = mb_basename($path);
        return [$dirname, $filename];
    }
}
