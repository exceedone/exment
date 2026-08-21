<?php

namespace Exceedone\Exment\Form\Field;

use ExmentAdminCore\Admin\Form\Field\Hidden;

/**
 * Google reCAPTCHA
 */
class ReCaptcha extends Hidden
{
    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::form.hidden';

    /**
     * @var string
     */
    protected $rules = 'required';

    /**
     * @param $arguments
     */
    // @phpstan-ignore-next-line
    public function __construct($arguments = [])
    {
        // if (!\Exment::isAvailableGoogleRecaptcha()) {
        //     throw new \Exception('To use captcha field, please install [mews/captcha] first.');
        // }

        $this->column = 'g-recaptcha-response';
        $this->label = 'Google reCAPTCHA';
    }


    /**
     * Get field validation rules.
     *
     * @return string
     */
    protected function getRules()
    {
        $this->rules([new \Exceedone\Exment\Validator\CaptchaRule()]);
        return parent::getRules();
    }

    public function setForm($form = null)
    {
        $this->form = $form;

        $this->form->ignore($this->column);

        return $this;
    }
}
