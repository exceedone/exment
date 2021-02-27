<?php

namespace Exceedone\Exment\Form\Field;

class MultipleImage extends \Encore\Admin\Form\Field\MultipleImage
{
    /**
     *  Validation rules.
     *
     * @var array
     */
    protected $rules = [];

    /**
     * Render file upload field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->filetype('image');
        return parent::render();
    }

    protected function getRules()
    {
       $rules = parent::getRules();
       $rules[] = new \Exceedone\Exment\Validator\ImageRule;
       return $rules;
    }
}
