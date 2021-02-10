<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Form;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Exceedone\Exment\Form\PublicContent;
use Exceedone\Exment\Form\Field\ReCaptcha;

class PublicForm extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoUuidTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $casts = ['options' => 'json'];

    public function custom_form()
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }

    public function deletingChildren()
    {
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }


    /**
     * Get form url
     *
     * @return string
     */
    public function getUrl() : string
    {
        return asset(config('exment.publicform_route_prefix', 'publicform') . '?formkey=' . $this->uuid);
    }


    /**
     * Get from by request
     *
     * @return ?PublicForm
     */
    public static function getPublicFormByRequest($uuid)
    {
        if(!$uuid){
            return null;
        }

        $model = PublicForm::where('uuid', $uuid)
            ->where('active_flg', 1)->first();
        if(!$model){
            return null;
        }

        // check daterange
        $now = Carbon::now();
        $start = $model->getOption('validity_period_start');
        if(!is_nullorempty($start) && Carbon::parse($start)->gt($now)){
            return null;
        }
        
        $end = $model->getOption('validity_period_end');
        if(!is_nullorempty($end) && Carbon::parse($end)->lt($now)){
            return null;
        }
        

        return $model;
    }

    
    /**
     * Get form
     *
     * @param Request $request
     * @return Form
     */
    public function getForm(Request $request)
    {
        // set footer as PublicFormFooter
        \Encore\Admin\Form\Builder::$footerClassName = \Exceedone\Exment\Form\PublicFormFooter::class;

        $custom_form = $this->custom_form;
        if(!$custom_form){
            return null;
        }
        $form = $custom_form->form_item
            ->disableToolsButton()
            ->disableSavedRedirectCheck()
            ->disableDefaultSavedRedirect()
            ->form()
            ->disablePjax()
            ->setView('exment::public-form.form')
            ->setAction(asset(config('exment.publicform_route_prefix', 'publicform')))
            ;

        // get footer
        $footer = $form->builder()->getFooter();
        // Google recaptcha
        if(static::isEnableRecaptcha() && boolval($this->getOption('use_recaptcha', false))){
            $version = static::recaptchaVersion();
            if($version == 'v2'){
                $footer->useRecaptchaV2();
                $form->pushField(new ReCaptcha);
            }
            elseif($version == 'v3'){
                $footer->useRecaptchaV3();
                $form->pushField(new ReCaptcha);
            }
        }

        $form->submitLabel(boolval($this->getOption('use_confirm')) ? exmtrans('custom_form_public.confirm_label') : trans('admin.submit'));

        $form->hidden('formkey')
            ->default($this->uuid);
        $form->ignore('formkey');

        return $form;
    }

    
    /**
     * getCompleteView
     *
     * @param Request $request
     * @return Form
     */
    public function getCompleteView(Request $request, CustomValue $custom_value)
    {
        // create link
        if(($url = $this->getOption('complete_link_url')) && ($text = $this->getOption('complete_link_text'))){
            $link = view('exment::tools.a', [
                'href' => $url,
                'label' => $text,
            ]);
        }

        return view('exment::public-form.complete', [
            'model' => $custom_value,
            'complete_title' => $this->getOption('complete_title'),
            'complete_text' => $this->getOption('complete_text'),
            'link' => $link ?? null,
        ]);
    }


    /**
     * Set content option
     *
     * @param PublicContent $content
     * @return $this
     */
    public function setContentOption(PublicContent $content)
    {
        $content->setBackgroundColor($this->getOption('background_color') ?? '#FFFFFF')
            ->setBackgroundColorOuter($this->getOption('background_color_outer') ?? '#FFFFFF')
            ->setHeaderBackgroundColor($this->getOption('header_background_color'))
            ->setFooterBackgroundColor($this->getOption('footer_background_color'))
            ->setFooterTextColor($this->getOption('footer_text_color') ?? '#FFFFFF')
            ->setUseHeader($this->getOption('use_header') ?? true)
            ->setUseFooter($this->getOption('use_footer') ?? true)
            ->setIsContainerFluid(($this->getOption('body_content_type') ?? 'width100') == 'width100')
            ->setHeaderLabel($this->getOption('header_label'))
            ;

        return $this;
    }


    /**
     * Get secret key for Google reCaptcha
     *
     * @return string|null
     */
    public static function recaptchaVersion() : ?string
    {
        return System::recaptcha_type() ?? config('no-captcha.version');
    }

    /**
     * Get site key for Google reCaptcha
     *
     * @return string|null
     */
    public static function recaptchaSiteKey() : ?string
    {
        return config('no-captcha.sitekey') ?? System::recaptcha_site_key();
    }

    /**
     * Get secret key for Google reCaptcha
     *
     * @return string|null
     */
    public static function recaptchaSecretKey() : ?string
    {
        return config('no-captcha.secret') ?? System::recaptcha_secret_key();
    }

    /**
     * Whether enable use reCAPTCHA
     *
     * @return true|string If true, enable reCaptcha. If string, showing message.
     */
    public static function isEnableRecaptcha()
    {
        $message = null;
        // checking NoCaptcha
        if(!\Exment::isAvailableGoogleRecaptcha()){
            $message = exmtrans('login.message.not_install_library', [
                'name' => 'Google reCaptcha',
                'url' => getManualUrl('public_form#recaptcha'),
            ]);
        }
        // check system setting
        else{
            $type = System::recaptcha_type();
            $site_key = static::recaptchaSiteKey();
            $secret = static::recaptchaSecretKey();
            if(is_nullorempty($type) || is_nullorempty($site_key) || is_nullorempty($secret)){
                $message = exmtrans('custom_form_public.message.recaptcha_not_setting');
            }
        }

        if($message){
            return '<span class="red">' . $message . '</span>';
        }
        return true;
    }

    // For tab ----------------------------------------------------
    public function getBasicSettingAttribute()
    {
        return $this->options;
    }

    public function setBasicSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }

    public function getDesignSettingAttribute()
    {
        return $this->options;
    }

    public function setDesignSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }

    public function getConfirmCompleteSettingAttribute()
    {
        return $this->options;
    }

    public function setConfirmCompleteSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }

    public function getErrorSettingAttribute()
    {
        return $this->options;
    }

    public function setErrorSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }
}
