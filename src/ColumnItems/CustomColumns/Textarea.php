<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;

class Textarea extends CustomItem
{
    public function html()
    {
        $text = $this->text();
        $text = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($text) : $text;
        
        return  replaceBreak($text);
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
}
