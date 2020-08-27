<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;

class Textarea extends CustomItem
{
    protected function _html($v)
    {
        $text = $this->_text($v);
        $text = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($text) : $text;
        $text = replaceBreak($text);

        if (!config('exment.textarea_space_tag', true)) {
            return $text;
        }

        // replace space to tag
        return preg_replace('/ /', '<span style="margin-right: 0.5em;"></span>', $text);
    }
    protected function getAdminFieldClass()
    {
        return Field\Textarea::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        $field->rows(array_get($options, 'rows', 6));
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        // value string
        $validates[] = new Validator\StringNumericRule();
    }
}
