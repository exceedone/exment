<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\Define;

/**
 * ImageRule.
 */
class ImageRule extends FileRule
{
    /**
     * @param array $extensions
     * @phpstan-ignore-next-line
     */
    public function __construct(array $extensions = [])
    {
        $this->extensions = Define::IMAGE_RULE_EXTENSIONS;
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
