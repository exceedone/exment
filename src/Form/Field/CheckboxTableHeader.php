<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Illuminate\Support\Collection;

class CheckboxTableHeader extends Field
{
    protected $view = 'exment::form.field.checkboxtableheader';
    
    protected $options = [];
    
    protected $checkWidth = 110;

    public function __construct($options)
    {
        $this->options = $options;
    }

    public function checkWidth($checkWidth){
        $this->checkWidth = $checkWidth;
    }

    /**
     * Set help block for current field.
     *
     * @param string $text
     * @param string $icon
     *
     * @return $this
     */
    public function help($text = '', $icon = 'fa-info-circle')
    {
        if(is_array($text)){
            $this->help = $text;
        }elseif($text instanceof Collection){
            $this->help = $text->values()->toArray();
        }else{
            $this->help[] = $text;
        }

        return $this;
    }


    /**
     * {@inheritdoc}
     */
    public function render()
    {
        return parent::render()->with([
            'checkWidth' => $this->checkWidth,
            'options' => $this->options
        ]);
    }
}
