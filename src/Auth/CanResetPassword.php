<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Services\MailSender;

trait CanResetPassword
{
    /**
     * Get the e-mail address where password reset links are sent.
     *
     * @return string
     */
    public function getEmailForPasswordReset()
    {
        return array_get($this->base_user->value, 'email');
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        MailSender::make('system_reset_password', $this->getEmailForPasswordReset())
            ->prms([
                'system.password_reset_url' => admin_url("auth/reset/".$token)
            ])
            ->send();
    }
}
