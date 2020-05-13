<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Validator;

class File extends CustomItem
{
    /**
     * get file info
     */
    public function file()
    {
        return ExmentFile::getFile($this->fileValue());
    }

    /**
     * get text
     */
    public function text()
    {
        // get image url
        return ExmentFile::getUrl($this->fileValue(), boolval(array_get($this->options, 'asApi')));
    }

    /**
     * get html. show link to file
     */
    public function html()
    {
        // get image url
        $url = ExmentFile::getUrl($this->fileValue());
        $file = ExmentFile::getData($this->fileValue());
        if (!isset($url)) {
            return $url;
        }

        $title = exmtrans('common.download');
        return "<a href='$url' target='_blank' data-toggle='tooltip' title='$title'>".esc_html($file->filename).'</a>';
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
        return Field\File::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        // set file options
        $fileOption = File::getFileOptions($this->custom_column, $this->id);
        $field->options($fileOption)->removable();
        $field->help(array_get($fileOption, 'maxFileSizeHelp'));
        
        // set filename rule
        $custom_table = $this->getCustomTable();
        $field->move($custom_table->table_name);
        $field->callableName(function ($file) use ($custom_table) {
            return File::setFileInfo($this, $file, $custom_table);
        });
        $field->caption(function ($caption) {
            $file = ExmentFile::getData($caption);
            return $file->filename ?? basename($caption);
        });
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
                ]
            ]
        );
    }

    /**
     * save file info to database
     */
    public static function setFileInfo($field, $file, $custom_table)
    {
        // get local filename
        $dirname = $field->getDirectory();
        $filename = $file->getClientOriginalName();
        // save file info
        $exmentfile = ExmentFile::saveFileInfo($dirname, $filename);

        // set request session to save this custom_value's id and type into files table.
        $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
        $file_uuids[] = [
            'uuid' => $exmentfile->uuid,
            'column_name' => $field->column(),
            'custom_table' => $custom_table,
            'path' => $exmentfile->path
        ];
        System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);
        
        // return filename
        return $exmentfile->local_filename;
    }

    /**
     * Get File Value. checking array
     *
     * @return void
     */
    protected function fileValue()
    {
        if (is_null($this->value)) {
            return null;
        }

        if (is_array($this->value)) {
            return count($this->value) == 0 ? null : $this->value[0];
        }

        return $this->value;
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
}
