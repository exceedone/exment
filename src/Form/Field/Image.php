<?php

namespace Exceedone\Exment\Form\Field;

class Image extends \Encore\Admin\Form\Field\Image
{
    /**
     *  Validation rules.
     * @phpstan-ignore-next-line
     */
    protected $rules = [];

    /**
     * @return array|string
     */
    protected function getRules()
    {
        /** @var array $rules */
        $rules = parent::getRules();
        $rules[] = new \Exceedone\Exment\Validator\ImageRule();
        return $rules;
    }
}
