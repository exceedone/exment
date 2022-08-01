<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Plugin;

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
        if (!$notify || !$notify->action_settings) {
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

        foreach ($keys as $key) {
            $enable = boolval($this->getOption($key['enable']));
            $notify = $this->{$key['notify']};
            $tmp_mail_template = $this->{'tmp_' . $key['mail_template']};
            $tmp_params = $this->{'tmp_' . $key['params']};

            // If enable, create or update notify
            if ($enable) {
                if (!$tmp_params || !$tmp_mail_template) {
                    continue;
                }

                if (!$notify) {
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
            } else {
                if (!$notify) {
                    continue;
                }
                $notify->delete();
            }
        }
    }


    /**
     * Export template replace json
     *
     * @param array $json
     * @return void
     */
    protected function exportReplaceJson(&$json)
    {
        // Append notify_complete_admin, notify_complete_user, notify_error
        foreach (['notify_complete_admin', 'notify_complete_user', 'notify_error'] as $key) {
            $notify = $this->{$key};
            if (!$notify) {
                $json[$key] = null;
                continue;
            }

            // get action_settings and replace notify_action_target
            $action_settings = $notify->action_settings;
            foreach ($action_settings as &$action_setting) {
                if (!isset($action_setting['notify_action_target'])) {
                    continue;
                }
                $notify_action_target_result = [];
                foreach ($action_setting['notify_action_target'] as $notify_action_target) {
                    // if numeric, this is customcolumn.
                    if (is_numeric($notify_action_target)) {
                        $notify_action_target_result[] = $this->getUniqueKeyValues($notify_action_target);
                    } else {
                        $notify_action_target_result[]['key'] = $notify_action_target;
                    }
                }

                $action_setting['notify_action_target'] = $notify_action_target_result;
            }

            // Get mail template ----------------------------------------------------
            $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)->getValueModel($notify->mail_template_id);

            $json[$key] = [
                'notify_trigger' => $notify->notify_trigger,
                'action_settings' => $action_settings,
                'mail_template_key_name' => $mail_template->getValue('mail_key_name'),
            ];
        }

        // Get plugins ----------------------------------------------------
        $plugin_css = Plugin::query()->whereOrIn('id', $this->getOption('plugin_css'))->pluck('plugin_name')->toArray();
        $plugin_js = Plugin::query()->whereOrIn('id', $this->getOption('plugin_js'))->pluck('plugin_name')->toArray();

        $json['options']['plugin_css'] = $plugin_css;
        $json['options']['plugin_js'] = $plugin_js;
    }

    /**
     * Callback template import event
     *
     * @param array $json
     */
    public function createNotifyImported(array $json)
    {
        // Append notify_complete_admin, notify_complete_user, notify_error
        foreach (['notify_complete_admin', 'notify_complete_user', 'notify_error'] as $key) {
            $notify_json = array_get($json, $key);
            if (!$notify_json) {
                array_forget($json, $key);
                continue;
            }

            $notify = new Notify([
                'target_id' => $this->id,
                'notify_view_name' => make_uuid(),
                'active_flg' => 1,
                'notify_trigger' => $notify_json['notify_trigger'],
            ]);

            // get action_settings and replace notify_action_target
            $action_settings = [];
            foreach (array_get($notify_json, 'action_settings') as $action_setting) {
                if (isset($action_setting['notify_action_target'])) {
                    $notify_action_targets = [];
                    foreach ($action_setting['notify_action_target'] as $notify_action_target) {
                        // if contains "key", set
                        if (array_has($notify_action_target, 'key')) {
                            $notify_action_targets[] = array_get($notify_action_target, 'key');
                        } else {
                            $custom_column = CustomColumn::getEloquent(array_get($notify_action_target, 'column_name'), array_get($notify_action_target, 'table_name'));
                            $notify_action_targets[] = $custom_column ? $custom_column->id : null;
                        }
                    }

                    $action_setting['notify_action_target'] = $notify_action_targets;
                }

                $action_settings[] = $action_setting;
            }

            $notify->action_settings = $action_settings;

            // get mail template ----------------------------------------------------
            $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)->findValue('mail_key_name', $notify_json['mail_template_key_name']);
            $notify->mail_template_id = $mail_template ? $mail_template->id : 0;

            $notify->save();
        }
    }



    /**
     * Callback template import event
     *
     * @param array $json
     */
    public function setPluginImported(array $json)
    {
        // set plugins ----------------------------------------------------
        $plugin_css = Plugin::query()->whereOrIn('plugin_name', array_get($json, 'options.plugin_css', []))->pluck('id')->filter();
        $plugin_js = Plugin::query()->whereOrIn('plugin_name', array_get($json, 'options.plugin_js', []))->pluck('id')->filter();

        if (is_nullorempty($plugin_css)) {
            $this->forgetOption('plugin_css');
        } else {
            $this->setOption('plugin_css', $plugin_css);
        }

        if (is_nullorempty($plugin_js)) {
            $this->forgetOption('plugin_js');
        } else {
            $this->setOption('plugin_js', $plugin_js);
        }

        $this->save();
    }
}
