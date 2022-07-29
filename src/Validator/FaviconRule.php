<?php

namespace Exceedone\Exment\Validator;

/**
 * FaviconRule.
 */
class FaviconRule extends FileRule
{
    public function __construct(array $extensions = [])
    {
        $this->extensions = ['ico'];
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.image');
    }
}
