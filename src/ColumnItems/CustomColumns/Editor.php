<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Validator;
use Illuminate\Support\Facades\Storage;

class Editor extends CustomItem
{
    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }

        $value = $this->savedFileInEditor($this->value);

        return strval($value);
    }

    protected function _text($v)
    {
        // replace img html
        $v = $this->replaceImgUrl($v);

        return $v;
    }

    protected function _html($v)
    {
        $text = $this->_text($v);
        if (is_null($text)) {
            return null;
        }

        if (boolval(array_get($this->options, 'grid_column'))) {
            // if grid, remove tag and omit string
            $text = get_omitted_string(strip_tags($text));
        }
        
        return  '<div class="show-tinymce">'.replaceBreak(html_clean($text), false).'</div>';
    }
    
    protected function getAdminFieldClass()
    {
        return Field\Tinymce::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        $field->rows(array_get($options, 'rows', 6));

        $item = $this;
        $field->callbackValue(function($value) use($item){
            return $item->replaceImgUrl($value);
        });
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        // value string
        $validates[] = new Validator\StringNumericRule();
    }


    /**
     * Replace "src" value
     *
     * @param ?string $v
     * @return string
     */
    public function replaceImgUrl($v){
        // replace img html
        preg_match_all('/\<img(.*?)data-exment-file-uuid="(?<file_uuid>.*?)"(.*?)\>/u', $v, $matches);
        if(is_nullorempty($matches)){
            return $v;
        }
        
        for($index = 0; $index < count($matches[0]); $index++){
            $replaceValue = $matches[0][$index];
            $file_uuid = array_get($matches, 'file_uuid')[$index];
            if(is_nullorempty($file_uuid)){
                continue;
            }

            $url = ExmentFile::getUrl($file_uuid);

            //replace src
            $replaceValue = preg_replace('/src="(.*?)"/u', 'src="' . $url . '"', $replaceValue);
            //$replaceValue = preg_replace('/data-exment-file-uuid="(.*?)"/u', "", $replaceValue);

            $v = str_replace($matches[0][$index], $replaceValue, $v);
        }

        return $v;
    }


    protected function savedFileInEditor($value)
    {
        if (is_nullorempty($value)) {
            return $value;
        }
        // find <img alt="" src=""> tag
        preg_match_all('/\<img([^\>]*?)src="(?<src>.*?)"(.*?)\>/u', $value, $matches);
        if(is_nullorempty($matches)){
            return $value;
        }

        // replace src="" and save file
        for($index = 0; $index < count($matches[0]); $index++){
            $match = $matches[0][$index];
            // group
            $src = array_get($matches, 'src')[$index];
            $filename = pathinfo($src, PATHINFO_FILENAME);

            $exists = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->exists($filename);
            $tmpUrl = strpos($src, admin_urls('tmpfiles')); // check url
            $fileUrl = strpos($src, admin_urls('files')); // check url

            // if not exment path url, continue
            if ($tmpUrl === false && $fileUrl === false) {
                continue;
            }
            
            // save tmp files
            $uuid = null;
            if ($exists && $tmpUrl !== false) {
                // get temporary file data
                $file = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->get($filename);            
                // save file info
                $exmentfile = ExmentFile::put(path_join($this->custom_table->table_name, make_uuid()), $file);
                    
                // save document model
                $exmentfile->saveDocumentModel($this->custom_value, $filename);

                // delete temporary file
                Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->delete($filename);            

                // set request session to save this custom_value's id and type into files table.
                $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
                $file_uuid = [
                    'uuid' => $exmentfile->uuid,
                    'column_name' => $this->custom_column->column_name,
                    'custom_table' => $this->custom_table,
                    'path' => $exmentfile->path
                ];
                $file_uuids[] = $file_uuid;
                System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);

                $uuid = $exmentfile->uuid;
                $this->custom_value->file_uuids($file_uuid);
            }
        
            // replace src to uuid
            $replaceValue = $match;
            preg_match('/src="(.*?)"/u', $replaceValue, $replaceMatch);
            if($replaceMatch){
                $replaceValue = preg_replace('/src="(.*?)"/u', 'src=""', $replaceValue);
            }

            preg_match('/data-exment-file-uuid="(?<uuid>.*?)"/u', $replaceValue, $replaceMatch);
            if($replaceMatch){
                $uuid = isset($uuid) ? $uuid : array_get($replaceMatch, 'uuid');
                $replaceValue = preg_replace('/data-exment-file-uuid="(.*?)"/u', 'data-exment-file-uuid="' . $uuid . '"', $replaceValue);
            }
            // not exists "data-exment-file-uuid", add
            else{
                $replaceValue = str_replace('<img', '<img data-exment-file-uuid="' . $uuid . '"' , $replaceValue);
            }
            $value = str_replace($match, $replaceValue, $value);
        }

        return $value;
    }

    protected function getExtention($type){
        if(is_nullorempty($type)){
            return null;
        }
        
        $types = [
            'image/gif' => 'gif', 
            'image/jpeg'=>'jpg', 
            'image/png'=>'png', 
            'image/svg+xml'=>'svg', 
            'image/bmp'=>'bmp', 
        ];
        foreach($types as $t => $ext){
            if(isMatchString($t, $type)){
                return ".{$ext}";
            }
        }

        return null;
    }
}
