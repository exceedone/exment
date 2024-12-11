<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Form\Footer as FooterBase;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

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
     */
    public function render()
    {
        /** @phpstan-ignore-next-line Maybe mistake laravel-admin type */
        return parent::render()->with([
            'useRecaptchaV2' => $this->useRecaptchaV2,
            'useRecaptchaV3' => $this->useRecaptchaV3,
        ]);
    }
}
