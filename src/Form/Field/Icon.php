<?php

namespace Exceedone\Exment\Form\Field;

class Icon extends \Encore\Admin\Form\Field\Image
{
    /**
     *  Validation rules.
     *
     * @var array
     */
    protected $rules = [];

    protected function getRules()
    {
        $rules = parent::getRules();
        $rules[] = new \Exceedone\Exment\Validator\IconRule;
        return $rules;
    }
}
