<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Form;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Exceedone\Exment\Form\PublicContent;

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
        return asset(config('exment.publicform_route_prefix', 'publicform') . '?key=' . $this->uuid);
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
            ->whereIn('options->active_flg', ["1", 1])->first();
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
        $custom_form = $this->custom_form;
        if(!$custom_form){
            return null;
        }
        $form = $custom_form->form_item
            ->disableToolsButton()
            ->disableSavedRedirectCheck()
            ->form()
            ->setView('exment::public-form.form')
            ->setAction(asset(config('exment.publicform_route_prefix', 'publicform')));

        $form->hidden('key')
            ->default($this->uuid);
        $form->ignore('key');

        return $form;
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
