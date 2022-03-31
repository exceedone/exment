<?php
namespace Exceedone\Exment\Validator;

/**
 * IconRule.
 */
class IconRule extends FileRule
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
