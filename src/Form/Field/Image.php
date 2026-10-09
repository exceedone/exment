<?php

namespace Exceedone\Exment\Form\Field;

class Image extends \Encore\Admin\Form\Field\Image
{
    /**
     *  Validation rules.
     */
    // @phpstan-ignore-next-line
    protected $rules = [];

    /**
     * @return array|string
     */
    // @phpstan-ignore-next-line
    protected function getRules()
    {
        /** @var array $rules */
        // @phpstan-ignore-next-line
        $rules = parent::getRules();
        $rules[] = new \Exceedone\Exment\Validator\ImageRule();
        return $rules;
    }
}
