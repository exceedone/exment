<?php

namespace Exceedone\Exment\Form\Field;

class Image extends \Encore\Admin\Form\Field\Image
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
       $rules[] = new \Exceedone\Exment\Validator\ImageRule;
       return $rules;
    }
}
