<?php

namespace Exceedone\Exment\Form\Field;

/**
 * The options of the custom column of a field (set by DefaultForm), for the scripts of the field.
 */
trait HasCustomOptions
{
    /** @var array<string, mixed> */
    protected $customOptions = [];

    /**
     * @param array<string, mixed> $customOptions
     * @return $this
     */
    public function setCustomOptions(array $customOptions)
    {
        $this->customOptions = $customOptions;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomOptions()
    {
        return $this->customOptions;
    }
}
