<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Form\Footer as FooterBase;

class PublicFormFooter extends FooterBase
{
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

    /**
     * Footer view.
     *
     * @var string
     */
    protected $view = 'exment::public-form.footer';

    /**
     * Wherther user Google Recaptcha v2
     *
     * @return $this
     */
    public function useRecaptchaV2()
    {
        // if no exixts class, return $this
        if (!\Exment::isAvailableGoogleRecaptcha()) {
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
    public function useRecaptchaV3()
    {
        // if no exixts class, return $this
        if (!\Exment::isAvailableGoogleRecaptcha()) {
            return $this;
        }

        $this->useRecaptchaV3 = true;
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
            'useRecaptchaV2' => $this->useRecaptchaV2,
            'useRecaptchaV3' => $this->useRecaptchaV3,
        ]);
    }
}
