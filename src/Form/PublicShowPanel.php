<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Show;

/**
 * Public show panel. 
 * *Contains create form*
 */
class PublicShowPanel extends \Encore\Admin\Show\Panel
{
    /**
     * The view to be rendered.
     *
     * @var string
     */
    protected $view = 'exment::public-form.confirmpanel';

    protected $action;
    protected $back_action;

    /**
     * Wherther user Google Recaptcha v2
     *
     * @var bool
     */
    protected $useRecaptchaV2 = false;

    /**
     * Wherther user Google Recaptcha v3
     *
     * @var bool
     */
    protected $useRecaptchaV3 = false;


    public function setAction(string $action)
    {
        $this->action = $action;

        return $this;
    }

    public function setBackAction(string $back_action)
    {
        $this->back_action = $back_action;

        return $this;
    }
    
    /**
     * Wherther user Google Recaptcha v2
     *
     * @return $this
     */
    public function useRecaptchaV2(){
        // if no exixts class, return $this
        if(!\Exment::isAvailableGoogleRecaptcha()){
            return $this;
        }

        $this->useRecaptchaV2 = true;
        return $this;
    }

    /**
     * Wherther user Google Recaptcha v3
     *
     * @return $this
     */
    public function useRecaptchaV3(){
        // if no exixts class, return $this
        if(!\Exment::isAvailableGoogleRecaptcha()){
            return $this;
        }

        $this->useRecaptchaV3 = true;
        return $this;
    }

    /**
     * Render this panel.
     *
     * @return string
     */
    public function render()
    {
        return parent::render()->with([
            'action' => $this->action,
            'back_action' => $this->back_action,
            'useRecaptchaV2' => $this->useRecaptchaV2,
            'useRecaptchaV3' => $this->useRecaptchaV3,
        ]);
    }
}
