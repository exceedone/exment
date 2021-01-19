<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Encore\Admin\Form\Field;
use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\UrlTagType;

class Image extends FormOtherItem
{
    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }

    /**
     * get html(for display)
     * *Please escape
     */
    public function _html($v)
    {
        $file = ExmentFile::getFileFromFormColumn(array_get($this->form_column, 'id'));
        if(!$file){
            return null;
        }

        $url = ExmentFile::getUrl($file);
        return \Exment::getUrlTag($url, '<img src="'.$url.'" class="mw-100 image_html" />', UrlTagType::BLANK, [], [
            'notEscape' => true,
        ]);
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
    }
}
