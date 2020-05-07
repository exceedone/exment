<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

class ExHtml extends Html
{
    /**
     * get Text(for display)
     */
    public function text()
    {
        $format = array_get($this->form_column, 'options.html');
        return replaceTextFromFormat($format, $this->value(), ['escapeValue' => true]);
    }

    public function setCustomValue($custom_value)
    {
        $this->value = $custom_value;

        $this->prepare();
        
        return $this;
    }
}
