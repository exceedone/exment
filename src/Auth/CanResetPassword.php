<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Enums\MailKeyName;

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
     * @param string $token
     * @return MailSender
     */
    public function sendPasswordResetNotification($token): MailSender
    {
        $sender = MailSender::make(MailKeyName::RESET_PASSWORD, $this->getEmailForPasswordReset())
            ->prms([
                'system.password_reset_url' => admin_url("auth/reset/".$token)
            ]);
        $sender->send();

        return $sender;
    }
}
