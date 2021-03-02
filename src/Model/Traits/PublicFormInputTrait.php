<?php

namespace Exceedone\Exment\Model\Traits;

use Encore\Admin\Form;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Form\PublicContent;
use Exceedone\Exment\DataItems\Show\PublicFormShow;
use Exceedone\Exment\DataItems\Form\PublicFormForm;
use Exceedone\Exment\Form\Field\ReCaptcha;

/**
 * Public from, for setting input logic.
 */
trait PublicFormInputTrait
{
    /**
     * error_notify_actions. If set from display, called after saved.
     *
     * @var mixed
     */
    protected $tmp_notify_action_error;
    protected $tmp_notify_mail_template_error;
    protected $tmp_notify_action_complete_user;
    protected $tmp_notify_action_complete_admin;
    protected $tmp_notify_mail_template_complete_user;
    protected $tmp_notify_mail_template_complete_admin;

    
    

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
    public function getConfirmCompleteSetting2Attribute()
    {
        return $this->options;
    }
    public function setConfirmCompleteSetting2Attribute(?array $options)
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

    public function getOptionSettingAttribute()
    {
        return $this->options;
    }
    public function setOptionSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }

    public function getCssJsSettingAttribute()
    {
        return $this->options;
    }
    public function setCssJsSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }

    public function getNotifyActionsCompleteUserAttribute()
    {
        $notify = $this->notify_complete_user;
        if(!$notify || !$notify->action_settings){
            return null;
        }

        // Convert notify_action_target as signle.
        return collect($notify->action_settings)->first();
    }
    public function setNotifyActionsCompleteUserAttribute($json)
    {
        // action target convert as array 
        $this->tmp_notify_action_complete_user = [$json];
        return $this;
    }
    public function getNotifyActionsCompleteAdminAttribute()
    {
        $notify = $this->notify_complete_admin;
        return $notify ? $notify->action_settings : null;
    }
    public function setNotifyActionsCompleteAdminAttribute($json)
    {
        $this->tmp_notify_action_complete_admin = $json;
        return $this;
    }
    public function getNotifyActionsErrorAttribute()
    {
        $notify = $this->notify_error;
        return $notify ? $notify->action_settings : null;
    }
    public function setNotifyActionsErrorAttribute($json)
    {
        $this->tmp_notify_action_error = $json;
        return $this;
    }

    public function getNotifyMailTemplateCompleteUserAttribute()
    {
        $notify = $this->notify_complete_user;
        return $notify ? $notify->mail_template_id : null;
    }
    public function setNotifyMailTemplateCompleteUserAttribute($value)
    {
        $this->tmp_notify_mail_template_complete_user = $value;
        return $this;
    }


    public function getNotifyMailTemplateCompleteAdminAttribute()
    {
        $notify = $this->notify_complete_admin;
        return $notify ? $notify->mail_template_id : null;
    }
    public function setNotifyMailTemplateCompleteAdminAttribute($value)
    {
        $this->tmp_notify_mail_template_complete_admin = $value;
        return $this;
    }
    public function getNotifyMailTemplateErrorAttribute()
    {
        $notify = $this->notify_error;
        return $notify ? $notify->mail_template_id : null;
    }
    public function setNotifyMailTemplateErrorAttribute($value)
    {
        $this->tmp_notify_mail_template_error = $value;
        return $this;
    }

    /**
     * Save or delete notify
     *
     * @return void
     */
    protected function toggleNotify()
    {
        $keys = [
            [
                'enable' => 'use_notify_error',
                'notify' => 'notify_error',
                'params' => 'notify_action_error',
                'mail_template' => 'notify_mail_template_error',
                'trigger' => NotifyTrigger::PUBLIC_FORM_ERROR,
            ],
            [
                'enable' => 'use_notify_complete_user',
                'notify' => 'notify_complete_user',
                'params' => 'notify_action_complete_user',
                'mail_template' => 'notify_mail_template_complete_user',
                'trigger' => NotifyTrigger::PUBLIC_FORM_COMPLETE_USER,
            ],
            [
                'enable' => 'use_notify_complete_admin',
                'notify' => 'notify_complete_admin',
                'params' => 'notify_action_complete_admin',
                'mail_template' => 'notify_mail_template_complete_admin',
                'trigger' => NotifyTrigger::PUBLIC_FORM_COMPLETE_ADMIN,
            ],
        ];

        foreach($keys as $key){
            $enable = boolval($this->getOption($key['enable']));
            $notify = $this->{$key['notify']};
            $tmp_mail_template = $this->{'tmp_' . $key['mail_template']};
            $tmp_params = $this->{'tmp_' . $key['params']};

            // If enable, create or update notify
            if($enable){
                if(!$tmp_params || !$tmp_mail_template){
                    continue;
                }

                if(!$notify){
                    $notify = new Notify([
                        'target_id' => $this->id,
                        'notify_view_name' => make_uuid(),
                        'active_flg' => 1,
                        'notify_trigger' => $key['trigger'],
                    ]);
                }

                $notify->action_settings = $tmp_params;
                $notify->mail_template_id = $tmp_mail_template;
                $notify->save();
            }
            else{
                if (!$notify) {
                    continue;
                }
                $notify->delete();
            }
        }
    }
}
