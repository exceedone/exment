<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;

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

        return '<a href="'.$url.'" target="_blank">'.esc_html($file->filename).'</a>';
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
        $field->move($this->getCustomTable()->table_name);
        $field->name(function ($file) {
            return File::setFileInfo($this, $file);
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
    public static function setFileInfo($field, $file)
    {
        // get local filename
        $dirname = $field->getDirectory();
        $filename = $file->getClientOriginalName();
        // save file info
        $exmentfile = ExmentFile::saveFileInfo($dirname, $filename);

        // set request session to save this custom_value's id and type into files table.
        $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
        $file_uuids[] = ['uuid' => $exmentfile->uuid, 'column_name' => $field->column()];
        System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);
        
        // return filename
        return $exmentfile->local_filename;
    }

    /**
     * Get File Value. checking array
     *
     * @return void
     */
    protected function fileValue(){
        if(is_null($this->value)){
            return null;
        }

        if(is_array($this->value)){
            return count($this->value) == 0 ? null : $this->value[0];
        }

        return $this->value;
    }
}
