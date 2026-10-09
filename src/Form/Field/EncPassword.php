<?php

namespace Exceedone\Exment\Form\Field;

/**
 * Encrypt password before save
 */
class EncPassword extends Password
{
    /**
     * @bool update if empty flag
     */
    // @phpstan-ignore-next-line
    protected $updateIfEmpty = false;

    /**
     * Prepare for a field value before update or insert.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function prepare($value)
    {
        // if don't input by user, return original
        if (!$this->updateIfEmpty && is_nullorempty($value)) {
            return $this->original;
        }

        return encrypt($value);
    }

    /**
     * Format value by passing custom formater.
     * Always null.
     */
    // @phpstan-ignore-next-line
    protected function formatValue()
    {
        $this->value = trydecrypt($this->value);
    }

    /**
     * set flag update if empty.
     */
    // @phpstan-ignore-next-line
    public function updateIfEmpty(bool $updateIfEmpty = true)
    {
        $this->updateIfEmpty = $updateIfEmpty;
        return $this;
    }
}
