<?php
namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\Define;

/**
 * ImageRule.
 */
class ImageRule extends FileRule
{
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
