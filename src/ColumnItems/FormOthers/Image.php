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

        $public_form = $this->isPublicForm() ? array_get($this->options, 'public_form') : null;
        $url = ExmentFile::getUrl($file, [
            'asPublicForm' => $this->isPublicForm(),
            'publicFormKey' => $public_form ? $public_form->uuid : null,
        ]);

        $imageTag = '<img src="'.$url.'" class="mw-100 image_html" />';
        if(!boolval(array_get($this->form_column, 'options.image_aslink', false))){
            return $imageTag;
        }

        return \Exment::getUrlTag($url, $imageTag, UrlTagType::BLANK, [], [
            'notEscape' => true,
        ]);
    }
    
    protected function setAdminOptions(&$field)
    {
    }
}
