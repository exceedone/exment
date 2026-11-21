<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Services\Uuids;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Facades\Storage;

/**
 * Exment file model.
 * uuid: primary key. it uses as url.
 * file_type : File type, Uses FileType.
 * path: local file path. it often sets "folder"/"uuid".
 * filename: for download or display name
 *
 * This class uses ↓
 *     *Append attachment info.
 *     *Save attachment server(or ftp, s3, ...).
 *     *Update custom value id or table
 *     *Delete file info.
 *     *Get attachment url.
 *
 * @phpstan-consistent-constructor
 * @property mixed $uuid
 * @property mixed $parent_type
 * @property mixed $parent_id
 * @property mixed $value
 * @property mixed $local_filename
 * @property mixed $local_dirname
 * @property mixed $custom_column_id
 * @property mixed $custom_form_column_id
 * @property mixed $filename
 * @property mixed $file_type
 * @property mixed $created_user_id
 * @method static \Illuminate\Database\Query\Builder whereNull($columns, $boolean = 'and', $not = false)
 * @method static \Illuminate\Database\Query\Builder whereNotNull($columns, $boolean = 'and')
 */
class File extends ModelBase
{
    use Uuids;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['uuid'];
    protected $casts = ['options' => 'json'];

    // Primary key setting
    protected $primaryKey = 'uuid';
    // increment disable
    public $incrementing = false;


    // @phpstan-ignore-next-line
    public function getPathAttribute()
    {
        return path_join($this->local_dirname, $this->local_filename);
    }


    // @phpstan-ignore-next-line
    public function getExtensionAttribute()
    {
        if (!isset($this->local_filename)) {
            return null;
        }

        return pathinfo($this->local_filename, PATHINFO_EXTENSION);
    }


    /**
     * get the file url
     *
     * @param string $path file path
     * @param array|boolean|null $options (Old version, this args is boolean)
     * @return string|null
     */

    // @phpstan-ignore-next-line
    public static function getUrl($path, $options = []): ?string
    {
        if ($options === true) {
            $options = ['asApi' => true];
        } elseif ($options === false) {
            $options = ['asApi' => false];
        }
        $options = array_merge(
            [
                'asApi' => false,
                'asPublicForm' => false,
                'publicFormKey' => null,
                'dirName' => false,
            ],
            $options
        );

        $file = static::getData($path);

        if (is_null($file)) {
            return null;
        }

        $name = 'files/'.($options['dirName'] ? $file->local_dirname.'/'.$file->local_filename  : $file->uuid);

        // append prefix
        if ($options['asApi']) {
            $name = url_join('api', $name);
        } elseif ($options['asPublicForm']) {
            $name = url_join(public_form_base_path(), $options['publicFormKey'], $name);
            // If public form, return name
            return asset($name);
        }

        return admin_url($name);
    }


    /**
     * Get file url from form column
     *
     * @param string|null $form_column
     * @return File|null
     */
    public static function getFileFromFormColumn(?string $form_column): ?File
    {
        if (!$form_column) {
            return null;
        }

        return static::where('custom_form_column_id', $form_column)->first();
    }

    /**
     * save document model. Please call after save file
     */

    // @phpstan-ignore-next-line
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

        // execute notify
        $custom_value->notify(false);

        return $document_model;
    }

    /**
     * Save custom value's on custom column's file-image. This function calls after save custom value.
     *
     * @param string|int $custom_value_id
     * @param CustomColumn|int|string|null $custom_column
     * @param CustomTable|int|string|null $custom_table
     * @return $this
     */
    public function saveCustomValue($custom_value_id, $custom_column = null, $custom_table = null)
    {
        if (!is_nullorempty($custom_column)) {

            // @phpstan-ignore-next-line
            return $this->saveCustomValueAndColumn($custom_value_id, $custom_column, $custom_table);
        }

        if (!is_nullorempty($custom_value_id)) {
            $this->parent_id = $custom_value_id;

            // @phpstan-ignore-next-line
            $this->parent_type = $custom_table->table_name;
        }
        $this->save();
        return $this;
    }


    /**
     * Save custom value information and column info
     * *Delete old column's file*
     *
     * @param string|int $custom_value_id
     * @param CustomColumn|null $custom_column
     * @param CustomTable|null $custom_table
     * @return $this
     */
    public function saveCustomValueAndColumn($custom_value_id, $custom_column, $custom_table = null, ?bool $replace = true)
    {
        if (is_null($replace)) {
            $replace = true;
        }

        if (!is_nullorempty($custom_value_id)) {
            $this->parent_id = $custom_value_id;
            $this->parent_type = $custom_table->table_name;
        }

        $table_name = $this->local_dirname ?? $custom_table->table_name;
        $custom_column = CustomColumn::getEloquent($custom_column, $table_name);
        $this->custom_column_id = $custom_column ? $custom_column->id : null;

        // get old file if replace
        if ($replace) {
            $oldFiles = static::where('parent_id', $this->parent_id)
            ->where('parent_type', $this->parent_type)
            ->where('custom_column_id', $this->custom_column_id)
            ->get();

            foreach ($oldFiles as $oldFile) {
                if (!is_nullorempty($oldFile) && !is_nullorempty($oldFile->custom_column_id)) {
                    static::deleteFileInfo($oldFile);
                }
            }
        }

        $this->save();
        return $this;
    }


    /**
     * Save file data to database.
     * *Please call this function before store file.
     *
     * @param string $dirname directory name
     * @return File
     */

    // @phpstan-ignore-next-line
    public static function saveFileInfo(?string $file_type, string $dirname, array $options = []): File
    {
        $options = array_merge([
            'filename' => null, // saves file name
            'unique_filename' => null, // If select unique file name
            'override' => false, // If true, override file
            'options' => [], // saved options data
        ], $options);
        $filename = $options['filename'];
        $unique_filename = $options['unique_filename'];
        $override = $options['override'];
        $options = $options['options'];

        $uuid = make_uuid();

        if (!isset($filename)) {
            list($dirname, $filename) = static::getDirAndFileName($dirname);
        }

        if (!isset($unique_filename)) {
            // get unique name. if different and not override, change name
            $unique_filename = static::getUniqueFileName($dirname, $filename, $override);
        }

        // if (!$override && $local_filename != $unique_filename) {
        //     $local_filename = $unique_filename;
        // }

        $file = File::create([
            'file_type' => $file_type,
            'uuid' => $uuid,
            'local_dirname' => $dirname,
            'local_filename' => $unique_filename,
            'filename' => $filename,
            'options' => $options,
        ]);
        return $file;
    }


    /**
     * delete file info to database
     * @param string|File $file
     * @return File|null|void
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

        return $file;
    }


    /**
     * Delete document model and file
     */

    // @phpstan-ignore-next-line
    public static function deleteDocumentModel($file, bool $isDeleteFile = true)
    {
        if ($isDeleteFile) {
            $file = static::deleteFileInfo($file);
        } else {
            $file = static::getData($file);
        }

        if (!$file) {
            return;
        }

        $column_name = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getIndexColumnName('file_uuid');
        $query = getModelName(SystemTableName::DOCUMENT)::where($column_name, $file->uuid);

        // the older versions of the attachment (replaced files) go with it
        if (\Exceedone\Exment\Services\DocumentVersionService::available()) {
            // @phpstan-ignore-next-line the query of the document model
            \Exceedone\Exment\Services\DocumentVersionService::deleteFor((clone $query)->pluck('id')->all());
        }

        // delete document info
        $query->delete();
    }

    /**
     * Get file object(laravel)
     *
     * @param string $uuid
     * @param \Closure|null $authCallback
     * @return string|null
     */
    public static function getFile($uuid, \Closure $authCallback = null)
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
     * get CustomValue from form. for saved CustomValue
     */

    // @phpstan-ignore-next-line
    public function getCustomValueFromForm($custom_value, $uuidObj)
    {
        // replace $uuidObj[path] for windows
        $path = str_replace('\\', '/', $this->path);

        // get from model
        $value = $custom_value->toArray();
        $fileValues = array_get($value, 'value.' . array_get($uuidObj, 'column_name'));
        if (!is_array($fileValues)) {
            $fileValues = [$fileValues];
        }

        $uuids = collect($custom_value->file_uuids());

        foreach ($fileValues as $fileValue) {
            // if match path, return this model's id
            if (isMatchString($fileValue, $path)) {
                return $value;
            } else {
                $file_uuid = $uuids->first(function ($file_uuid) {
                    return isMatchString(array_get($file_uuid, 'uuid'), $this->uuid);
                });
                if (isset($file_uuid)) {
                    return $fileValue;
                }
            }
        }


        return null;
    }

    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param string|null $file_type file type
     * @param  string  $path directory and file path(Please join.)
     * @param  \Illuminate\Http\UploadedFile|\Symfony\Component\HttpFoundation\File\UploadedFile|string|null $content file content
     * @return File
     */

    // @phpstan-ignore-next-line
    public static function put(?string $file_type, $path, $content, array $options = [])
    {
        $file = static::saveFileInfo($file_type, $path, $options);

        if ($content instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $content = \Illuminate\Http\UploadedFile::createFromBase($content);
        }

        Storage::disk(config('admin.upload.disk'))->put($file->path, $content);
        return $file;
    }

    /**
     * Save file table on db and store the uploaded file on a filesystem disk.
     *
     * @param string|null $file_type file type
     * @param $content file content
     * @param string $dirname directory path
     * @param string $name file name. the name is shown by display
     * @param array $options
     * @return File
     */

    // @phpstan-ignore-next-line
    public static function storeAs(?string $file_type, $content, string $dirname, string $name, array $options = []): File
    {
        $options = array_merge([
            'filename' => $name, // saves file name
        ], $options);
        $file = static::saveFileInfo($file_type, $dirname, $options);

        if ($content instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $content = \Illuminate\Http\UploadedFile::createFromBase($content);
        }

        if ($content instanceof \Illuminate\Http\UploadedFile) {
            $content->storeAs($dirname, $file->local_filename, config('admin.upload.disk'));
        } else {
            \Storage::disk(config('admin.upload.disk'))->put(path_join($dirname, $file->local_filename), $content);
        }
        return $file;
    }

    /**
     * Get file model using path or uuid
     *
     * @param string|File|array $pathOrUuids
     * @return File|null
     */

    // @phpstan-ignore-next-line
    public static function getData($pathOrUuids)
    {
        if (is_nullorempty($pathOrUuids)) {
            return null;
        }

        if ($pathOrUuids instanceof File) {
            return $pathOrUuids;
        }

        $funcUuid = function ($pathOrUuid) {
            if (!is_uuid($pathOrUuid)) {
                return null;
            }
            return static::where('uuid', $pathOrUuid)->first();
        };
        $funcPath = function ($pathOrUuid) {
            // read together beforehand by prefetch() (false: not in the database)
            $key = static::prefetchKey($pathOrUuid);
            if (System::hasRequestSession($key)) {
                return System::requestSession($key) ?: null;
            }

            // get by $dirname, $filename
            list($dirname, $filename) = static::getDirAndFileName($pathOrUuid);
            $file = static::where('local_dirname', $dirname)
                ->where('local_filename', $filename)
                ->first();
            if (isset($file)) {
                return $file;
            }
            return null;
        };

        foreach (toArray($pathOrUuids) as $pathOrUuid) {
            if (strpos($pathOrUuid, '/') !== false) {
                $val = $funcPath($pathOrUuid) ?: $funcUuid($pathOrUuid) ?: null;
            } else {
                $val = $funcUuid($pathOrUuid) ?: $funcPath($pathOrUuid) ?: null;
            }

            if ($val !== null) {
                return $val;
            }
        }

        return null;
    }

    /**
     * get unique file name
     */

    // @phpstan-ignore-next-line
    public static function getUniqueFileName($dirname, $filename = null, $override = false)
    {
        if ($override) {
            if (!isset($filename)) {
                list($dirname, $filename) = static::getDirAndFileName($dirname);
            }

            // get by dir and filename
            $file = static::where('local_dirname', $dirname)->where('filename', $filename)->first();

            if (!is_null($file)) {
                return $file->local_filename;
            }
        }

        $ext = file_ext($filename);
        return make_uuid() . (!is_nullorempty($ext) ? '.'.$ext : '');
    }

    /**
     * Read together the files of these paths ("dirname/filename", as a file column stores
     * them), for getData() to answer from the request: a page of rows with file columns
     * asked once per row and column, twice for a link (ISS00413).
     *
     * @param iterable<mixed> $paths
     * @return void
     */
    public static function prefetch($paths)
    {
        $wanted = [];
        foreach ($paths as $path) {
            foreach (is_null($path) ? [] : (array) toArray($path) as $p) {
                if (!is_string($p) || strpos($p, '/') === false || System::hasRequestSession(static::prefetchKey($p))) {
                    continue;
                }
                list($dirname, $filename) = static::getDirAndFileName($p);
                $wanted[$p] = [$dirname, $filename];
            }
        }
        if (empty($wanted)) {
            return;
        }

        $found = [];
        foreach (array_chunk(array_unique(array_column($wanted, 1)), 500) as $filenames) {
            foreach (static::whereIn('local_filename', $filenames)->get() as $file) {
                $found[$file->local_dirname . '/' . $file->local_filename] = $file;
            }
        }
        foreach ($wanted as $p => list($dirname, $filename)) {
            System::requestSession(static::prefetchKey($p), $found[$dirname . '/' . $filename] ?? false);
        }
    }

    /**
     * The request session key of a prefetched path (hashed: a path has dots, which the
     * request session reads as nesting).
     */
    protected static function prefetchKey(string $path): string
    {
        return 'file_prefetch_' . md5($path);
    }

    /**
     * (booted, not boot: boot is the Uuids trait's, which gives the file its uuid)
     *
     * @return void
     */
    protected static function booted()
    {
        // a saved or deleted file is read again, not from what prefetch() kept
        $forget = function ($file) {
            // the path it has, and the one it had (a moved file)
            foreach ([[$file->local_dirname, $file->local_filename], [$file->getOriginal('local_dirname'), $file->getOriginal('local_filename')]] as list($dirname, $filename)) {
                if (isset($dirname, $filename)) {
                    System::clearRequestSession(static::prefetchKey($dirname . '/' . $filename));
                }
            }
        };
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * get directory and filename from path
     */

    // @phpstan-ignore-next-line
    protected static function getDirAndFileName($path)
    {
        $pathinfo = pathinfo($path);
        if (isMatchString(array_get($pathinfo, 'dirname'), '.')) {
            return [$path, make_uuid()];
        }

        $dirname = dirname($path);
        $filename = mb_basename($path);
        return [$dirname, $filename];
    }
}
