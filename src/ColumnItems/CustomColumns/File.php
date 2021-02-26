<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Grid\Filter\Where as ExmWhere;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\UrlTagType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Validator;

class File extends CustomItem
{
    use SelectTrait;
    
    /**
     * get file info
     */
    public function file()
    {
        return ExmentFile::getFile($this->fileValue($this->value));
    }

    /**
     * get text
     */
    protected function _text($v)
    {
        // get image url
        return ExmentFile::getUrl($this->fileValue($v), boolval(array_get($this->options, 'asApi')));
    }

    /**
     * get html. show link to file
     */
    protected function _html($v)
    {
        // get image url
        $url = ExmentFile::getUrl($this->fileValue($v));
        $file = ExmentFile::getData($this->fileValue($v));
        if (!isset($url)) {
            return $url;
        }
        
        return \Exment::getUrlTag($url, $file->filename, UrlTagType::BLANK, [], [
            'tooltipTitle' => exmtrans('common.download'),
        ]);
    }

    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return void
     */
    public function getImportValue($value, $setting = [])
    {
        if (is_nullorempty($value)) {
            return [
                'skip' => true,
            ];
        }

        // Get file info by url
        // only check by uuid
        $uuid = pathinfo(trim($value, '/'), PATHINFO_FILENAME);
        if (is_nullorempty($uuid)) {
            return [
                'skip' => true,
            ];
        }

        $file = ExmentFile::where('uuid', $uuid)->first();
        if (!isset($file)) {
            return [
                'skip' => true,
            ];
        }

        // return file path
        return [
            'result' => true,
            'value' => $file->path,
        ];
    }

    protected function getAdminFieldClass()
    {
        if($this->isMultipleEnabled()){
            return Field\MultipleFile::class;
        }
        return Field\File::class;
    }
    
    protected function getAdminFilterClass()
    {
        return ExmWhere::class;
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        // set file options
        $fileOption = File::getFileOptions($this->custom_column, $this->id);
        $field->options($fileOption)->removable();
        $field->help(array_get($fileOption, 'maxFileSizeHelp'));
        
        // set filename rule
        $custom_table = $this->getCustomTable();
        $multiple = $this->isMultipleEnabled();
        $field->move($custom_table->table_name)
        ->callableName(function ($file) use ($custom_table, $multiple) {
            return \Exment::setFileInfo($this, $file, FileType::CUSTOM_VALUE_COLUMN, $custom_table, !$multiple);
        })->fileIndex(function ($index, $file) {
            $file = ExmentFile::getData($file);
            return $file->uuid ?? 0;
        })->caption(function ($caption, $key) {
            $file = ExmentFile::getData($key);
            return $file->filename ?? basename($caption);
        });
    }


    /**
     * Delete attachment's file
     *
     * @param string $del_key
     * @return void
     */
    public function deleteFile(string $del_key)
    {
        $del_column_name = $this->custom_column->column_name;
        $field = $this->getAdminField();

        // get original value
        $value = $this->getOriginalForDeleteFile();
        $field->setOriginal($value);
        $fileValue = array_get($value, $del_column_name);

        $field->destroy($del_key); // delete file
        ExmentFile::deleteFileInfo($del_key); // delete file table
        
        // updated value
        if(!$this->isMultipleEnabled()){
            $updatedValue = null;
        }
        else{
            array_forget($fileValue, $del_key);
            $updatedValue = array_values($fileValue);
        }

        $this->custom_value->setValue($this->custom_column->column_name, $updatedValue)
            ->remove_file_columns($del_column_name)
            ->save();
    }

    /**
     * Get original for deleting file
     *
     * @return mixed
     */
    protected function getOriginalForDeleteFile()
    {
        $del_column_name = $this->custom_column->column_name;
        $value = $this->custom_value->value;
        $fileValue = array_get($value, $del_column_name);
        
        if(is_nullorempty($fileValue)){
            return null;
        }

        // if multiple, return uuid and file mapping
        if(!$this->isMultipleEnabled()){
            return $value;
        }

        if(!is_array($fileValue)){
            $fileValue = array_filter([$fileValue]);
        }

        $value[$del_column_name] = collect($fileValue)
            ->mapWithKeys(function($v){
                $filedata = ExmentFile::getData($v);
                $uuid = $filedata->uuid ?? null;
                return [$uuid  => $v];
        })->toArray();
        return $value;
    }
    
    protected static function getFileOptions($custom_column, $id)
    {
        return array_merge(
            Define::FILE_OPTION(),
            [
                'showPreview' => true,
                'deleteUrl' => admin_urls('data', $custom_column->custom_table->table_name, $id, 'filedelete'),
                'deleteExtraData'      => [
                    Field::FILE_DELETE_FLAG         => $custom_column->column_name,
                    '_token'                         => csrf_token(),
                    '_method'                        => 'PUT',
                ],
                'deletedEvent' => 'Exment.CommonEvent.CallbackExmentAjax(jqXHR.responseJSON);',
            ]
        );
    }

    /**
     * Get File Value. checking array
     *
     * @return string
     */
    protected function fileValue($v)
    {
        if (is_null($v)) {
            return null;
        }

        if (is_array($v)) {
            return count($v) == 0 ? null : $v[0];
        }

        return $v;
    }

    protected function setValidates(&$validates, $form_column_options)
    {
        $options = $this->custom_column->options;

        if ((boolval(array_get($options, 'required')) || boolval(array_get($form_column_options, 'required', [])))) {
            $validates[] = new Validator\FileRequredRule($this->custom_column, $this->custom_value);
        }
    }
    
    protected function getCustomField($classname, $form_column_options = null, $column_name_prefix = null)
    {
        $field = parent::getCustomField($classname, $form_column_options, $column_name_prefix);

        $options = $this->custom_column->options;

        // required
        if ((boolval(array_get($options, 'required')) || boolval(array_get($form_column_options, 'required')))) {
            $field->removeRule('required');
        }

        // if not has value "old", and $custom_value has file path value, set again
        // Because if validation error, file column's value is always null.
        $custom_value = $this->custom_value;
        $custom_column = $this->custom_column;
        $field->callbackValue(function ($value) use ($custom_value, $custom_column) {
            if (!is_nullorempty($value)) {
                return $value;
            }
            if (!isset($custom_value)) {
                return $value;
            }
            return array_get($custom_value->value, $custom_column->column_name);
        });

        return $field;
    }
    

    public function getAdminFilterWhereQuery($query, $input)
    {
        list($mark, $value) = \Exment::getQueryMarkAndValue(true, $input);
        // get values ids
        $ids = $this->getQueryIds($mark, $value);
        if (is_nullorempty($ids)) {
            $query->whereNotMatch();
        }
        
        $query->whereOrIn('id', $ids);
    }
    

    /**
     * Get Search queries for free text search
     *
     * @param string $mark
     * @param string $value
     * @param int $takeCount
     * @param string|null $q
     * @return array
     */
    public function getSearchQueries($mark, $value, $takeCount, $q, $options = [])
    {
        // get values ids
        $ids = $this->getQueryIds($mark, $value);
        if (is_nullorempty($ids)) {
            return [];
        }
        
        $query = $this->custom_table->getValueModel()->query();
        $query->whereOrIn('id', $ids)->select('id');
        
        $query->take($takeCount);

        return [$query];
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param Builder $mark
     * @param string $mark
     * @param string $value
     * @param string|null $q
     * @return void
     */
    public function setSearchOrWhere(&$query, $mark, $value, $q)
    {
        $ids = $this->getQueryIds($mark, $value);
        if (is_nullorempty($ids)) {
            return $this;
        }
        $query->orWhereIn('id', $ids);

        return $this;
    }

    /**
     * Get query search bar
     *
     * @param string $mark
     * @param string $value
     * @return array target custom values's id list
     */
    protected function getQueryIds($mark, $value)
    {
        ///// first, search document table
        $file_query = ExmentFile::query();
        // get values
        return $file_query->where('custom_column_id', $this->custom_column->id)
            ->where('parent_type', $this->custom_table->table_name)
            ->where('filename', $mark, $value)
            ->select(['parent_id'])
            ->get()->pluck('parent_id');
    }

    public function isMultipleEnabled()
    {
        return $this->isMultipleEnabledTrait();
    }

    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        // enable multiple
        $form->switchbool('multiple_enabled', exmtrans("custom_column.options.multiple_enabled"));
    }
}
