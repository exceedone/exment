<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;

class File extends CustomItem 
{
    protected function getAdminFieldClass(){
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleFile::class;
        } else {
            return Field\File::class;
        }
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        // set file options
        $field->options(
            File::getFileOptions($custom_table, $column, $id)
        )->removable();
        // set filename rule
        $field->move($custom_table->table_name);
        $field->name(function ($file) {
            return File::setFileInfo($this, $file);
        });
    }
    
    protected static function getFileOptions($custom_table, $custom_column, $id)
    {
        return 
            [
                'showCancel' => false,
                'deleteUrl' => admin_urls('data', $custom_table->table_name, $id, 'filedelete'),
                'deleteExtraData'      => [
                    Field::FILE_DELETE_FLAG         => $custom_column->column_name,
                    '_token'                         => csrf_token(),
                    '_method'                        => 'PUT',
                ]
            ];
    }

    /**
     *
     */
    protected static function setFileInfo($field, $file)
    {
        // get local filename
        $dirname = $field->getDirectory();
        $filename = $file->getClientOriginalName();
        $local_filename = ExmentFile::getUniqueFileName($dirname, $filename);
        // save file info
        $exmentfile = ExmentFile::saveFileInfo($dirname, $filename, $local_filename);

        // set request session to save this custom_value's id and type into files table.
        $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
        $file_uuids[] = ['uuid' => $exmentfile->uuid, 'column_name' => $field->column()];
        System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);
        
        // return filename
        return $exmentfile->local_filename;
    }
}
