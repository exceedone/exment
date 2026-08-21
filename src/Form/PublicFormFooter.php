<?php

namespace Exceedone\Exment\Form;

use Exceedone\Exment\Services\ReCaptchaService;
use ExmentAdminCore\Admin\Form\Footer as FooterBase;
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
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'useRecaptchaV2' => $this->useRecaptchaV2,
            'useRecaptchaV3' => $this->useRecaptchaV3,
            'recaptchaWidget' => $this->recaptchaWidget(),
            'recaptchaScript' => $this->recaptchaScript(),
        ]);
    }

    /**
     * Markup placed inside the form: the checkbox for v2, or the hidden input
     * the token is written into for v3, which asks the visitor nothing.
     *
     * @return string
     */
    protected function recaptchaWidget(): string
    {
        if ($this->useRecaptchaV2) {
            return ReCaptchaService::widgetV2();
        }

        if ($this->useRecaptchaV3) {
            return ReCaptchaService::inputV3();
        }

        return '';
    }

    /**
     * Scripts, placed after the form so api.js already sees the widget markup.
     *
     * @return string
     */
    protected function recaptchaScript(): string
    {
        if ($this->useRecaptchaV2) {
            return ReCaptchaService::apiScript(ReCaptchaService::VERSION_V2);
        }

        if ($this->useRecaptchaV3) {
            return ReCaptchaService::apiScript(ReCaptchaService::VERSION_V3)
                . ReCaptchaService::scriptV3();
        }

        return '';
    }
}
