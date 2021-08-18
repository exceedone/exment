<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

/**
 * text, textarea, editor common logic
 */
trait TextTrait
{
    /**
     * get max length setting
     */
    protected function getMaxLength($options = null)
    {
        if (!isset($options)) {
            $options = $this->custom_column->options;
        }

        $config_length = config('exment.char_length_limit', 63999);
        $string_length = array_get($options, 'string_length')?? $config_length;
        return min($string_length, $config_length);
    }
}
