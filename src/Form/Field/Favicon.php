<?php

namespace Exceedone\Exment\Form\Field;

class Favicon extends \Encore\Admin\Form\Field\Image
{
    /**
     *  Validation rules.
     *
     * @var array
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
        $rules[] = new \Exceedone\Exment\Validator\FaviconRule();
        return $rules;
    }
}
