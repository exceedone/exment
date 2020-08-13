<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;

class Editor extends CustomItem
{
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
        
        return  '<div class="show-tinymce">'.replaceBreak(esc_script_tag($text), false).'</div>';
    }
    
    protected function getAdminFieldClass()
    {
        return Field\Tinymce::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        $field->rows(array_get($options, 'rows', 6));
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        // value size
        $validates[] = 'string';
    }
}
