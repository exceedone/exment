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
     * Check extension, and if uploaded file, check the file content is image too.
     * (Not only the original file name's extension.)
     *
     * @param string $attribute
     * @param mixed $value
     * @return bool
     */
    protected function validateExtension($attribute, $value)
    {
        if (!parent::validateExtension($attribute, $value)) {
            return false;
        }

        if (!($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) || !$value->isFile()) {
            return true;
        }

        // guess extension from file content (mime type). Allow other image type in the list. (ex: png file named .jpg)
        $ext = $value->guessExtension();
        if (is_nullorempty($ext)) {
            return false;
        }
        foreach ($this->extensions as $val) {
            if (strcasecmp($val, $ext) == 0) {
                return true;
            }
        }
        return false;
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
