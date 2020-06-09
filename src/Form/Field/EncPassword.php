<?php

namespace Exceedone\Exment\Form\Field;

/**
 * Encrypt password before save
 */
class EncPassword extends Password
{
    /**
     * Prepare for a field value before update or insert.
     *
     * @param $value
     *
     * @return mixed
     */
    public function prepare($value)
    {
        // if don't input by user, return original
        if (is_nullorempty($value)) {
            return $this->original;
        }

        return encrypt($value);
    }
    
    /**
     * Format value by passing custom formater.
     * Always null.
     */
    protected function formatValue()
    {
        $this->value = null;
    }
}
