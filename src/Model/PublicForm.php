<?php

namespace Exceedone\Exment\Model;

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
     * Get from by request
     *
     * @return ?PublicForm
     */
    public static function getPublicFormByRequest()
    {
        $uuid = request()->get('key');
        if(!$uuid){
            return null;
        }

        return PublicForm::where('uuid', $uuid)->first();
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
