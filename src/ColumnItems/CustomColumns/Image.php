<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\UrlTagType;

class Image extends File
{
    /**
     * get html. show link to image
     */
    protected function _html($v)
    {
        // get image url
        $url = ExmentFile::getUrl($this->fileValue($v));
        if (!isset($url)) {
            return $url;
        }

        return \Exment::getUrlTag($url, '<img src="'.$url.'" class="image_html" />', UrlTagType::BLANK, [], [
            'notEscape' => true,
        ]);
    }

    protected function getAdminFieldClass()
    {
        return Field\Image::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        parent::setAdminOptions($field, $form_column_options);

        $field->attribute(['accept' => "image/*"]);
    }

    protected function setValidates(&$validates, $form_column_options)
    {
        $validates[] = new \Exceedone\Exment\Validator\ImageRule;
    }
}
