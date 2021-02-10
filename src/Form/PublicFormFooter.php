<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Admin;
use Encore\Admin\Form\Footer as FooterBase;

class PublicFormFooter extends FooterBase
{
    /**
     * Undocumented variable
     *
     * @var bool
     */
    protected $useRecaptcha = false;

    /**
     * Footer view.
     *
     * @var string
     */
    protected $view = 'exment::public-form.footer';

    /**
     * Wherther user Google Recaptcha
     *
     * @return $this
     */
    public function useRecaptcha(){
        // if no exixts class, return $this
        if(!\Exment::isAvailableGoogleRecaptcha()){
            return $this;
        }

        $this->useRecaptcha = true;
        return $this;
    }

    /**
     * Render footer.
     *
     * @return string
     */
    public function render()
    {
        return parent::render()->with([
            'useRecaptcha' => $this->useRecaptcha,
        ]);
    }
}
