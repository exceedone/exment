<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field as AdminField;
use Encore\Admin\Form;
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
        // If public form tmp file, return Only file name.
        if (is_string($v) && strpos($v, AdminField\File::TMP_FILE_PREFIX) === 0) {
            return esc_html(array_get($this->getTmpFileInfo($v), 'originalFileName'));
        }

        // get image url
        $url = ExmentFile::getUrl($this->fileValue($v));
        if (!isset($url)) {
            return $url;
        }

        return \Exment::getUrlTag($url, '<img src="'.$url.'" class="mw-100 image_html" />', UrlTagType::BLANK, [], [
            'notEscape' => true,
        ]);
    }

    protected function getAdminFieldClass()
    {
        if ($this->isMultipleEnabled()) {
            return Field\MultipleImage::class;
        }
        return Field\Image::class;
    }

    protected function setAdminOptions(&$field)
    {
        parent::setAdminOptions($field);

        $field->attribute(['accept' => "image/*"]);
    }

    protected function setValidates(&$validates)
    {
        $validates[] = new \Exceedone\Exment\Validator\ImageRule();

        parent::setValidates($validates);
    }

    /**
     * Get separate word for multiple
     *
     * @return string|null
     */
    protected function getSeparateWord(): ?string
    {
        if (boolval(array_get($this->options, 'as_confirm'))) {
            return parent::getSeparateWord();
        }
        return '';
    }
}
