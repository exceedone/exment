<?php

namespace Exceedone\Exment\Form\Field;

class MultipleImage extends \Encore\Admin\Form\Field\MultipleImage
{
    /**
     *  Validation rules.
     * @phpstan-ignore-next-line Need to fix laravel-admin
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
        /** @phpstan-ignore-next-line Need to fix laravel-admin */
        $rules[] = new \Exceedone\Exment\Validator\ImageRule();
        return $rules;
    }
}
