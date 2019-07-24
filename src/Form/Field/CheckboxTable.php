<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Checkbox;

class CheckboxTable extends Checkbox
{
    protected $view = 'exment::form.field.checkboxtable';
    
    protected $checkWidth = 100;

    public function checkWidth($checkWidth)
    {
        $this->checkWidth = $checkWidth;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        return parent::render()->with([
            'checkWidth' => $this->checkWidth
        ]);
    }
}
