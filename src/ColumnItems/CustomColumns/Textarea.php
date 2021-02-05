<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Validator;

class Textarea extends CustomItem
{
    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }
        return strval($this->value);
    }

    protected function _html($v)
    {
        $text = $this->_text($v);
        $text = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($text) : $text;
        $text = replaceBreakEsc($text);

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

        if (array_get($options, 'string_length')) {
            $field->attribute(['maxlength' => array_get($options, 'string_length')]);
        }
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'string_length')) {
            $validates[] = 'max:'.array_get($options, 'string_length');
        }

        // value string
        $validates[] = new Validator\StringNumericRule();
    }
}
