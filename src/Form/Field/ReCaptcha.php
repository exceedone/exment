<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Hidden;

/**
 * Gooele ReCaptcha
 */
class ReCaptcha extends Hidden
{
    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::form.hidden';

    /**
     * @var string
     * @phpstan-ignore-next-line
     */
    protected $rules = 'required';

    /**
     * @param $arguments
     * @phpstan-ignore-next-line
     */
    public function __construct($arguments = [])
    {
        // if (!\Exment::isAvailableGoogleRecaptcha()) {
        //     throw new \Exception('To use captcha field, please install [mews/captcha] first.');
        // }

        $this->column = 'g-recaptcha-response';
        $this->label = 'Gooele reCaptcha';
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
