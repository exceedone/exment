<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

class ExHtml extends Html
{
    /**
     * get Text(for display)
     */
    protected function _text($v)
    {
        $format = array_get($this->form_column_options, 'html');
        return replaceTextFromFormat($format, $v);
    }

    public function setCustomValue($custom_value)
    {
        $this->value = $custom_value;

        $this->prepare();

        return $this;
    }
}
