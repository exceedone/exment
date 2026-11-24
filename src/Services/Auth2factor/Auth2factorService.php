<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * For login 2 factor
 */
class Auth2factorService
{
    protected const VERIFY_TYPE_2FACTOR = '2factor_';

    // @phpstan-ignore-next-line
    protected static $providers = [
    ];

    /**
     * Register providers.
     *
     * @param string $abstract
     * @param string $class
     *
     * @return void
     */
    public static function providers($abstract, $class)
    {
        static::$providers[$abstract] = $class;
    }

    // @phpstan-ignore-next-line
    public static function getProvider()
    {
        $provider = \Exceedone\Exment\Facades\ExmentFacade::user()->getSettingValue(
            implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
            System::login_2factor_provider() ?? Login2FactorProviderType::EMAIL
        );

        if (!array_has(static::$providers, $provider)) {
            throw new \Exception("Login 2factor provider [$provider] does not exist.");
        }

        return new static::$providers[$provider]();
    }

    /**
     * Verify code
     *
     * @param string $verify_type
     * @param string $verify_code
     * @param bool $matchDelete if true, remove match records
     * @return mixed|bool
     */
    public static function verifyCode($verify_type, $verify_code, $matchDelete = false)
    {
        $loginuser = \ExmentAdminCore\Admin\Facades\Admin::user();

        // remove old datetime value
        \Illuminate\Support\Facades\DB::table('email_code_verifies')
            ->where('valid_period_datetime', '<', \Carbon\Carbon::now())
            ->delete();

        // get from database
        $query = \Illuminate\Support\Facades\DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('verify_code', $verify_code)
            ->where('verify_type', static::VERIFY_TYPE_2FACTOR . $verify_type)
            ->where('email', $loginuser->email)
            ->where('login_user_id', $loginuser->id);

        if ($query->count() == 0) {
            return false;
        }

        $verify = $query->first();

        if ($matchDelete) {
            static::deleteCode($verify_type, $verify_code);
        }

        return $verify;
    }

    /**
     * Add database and Send verify
     *
     * @param string $verify_type
     * @param string|int $verify_code
     * @param \Carbon\Carbon $valid_period_datetime
     * @param string|CustomValue $mail_template
     * @param array $mail_prms
     * @return bool
     */
    // @phpstan-ignore-next-line
    public static function addAndSendVerify($verify_type, $verify_code, $valid_period_datetime, $mail_template, $mail_prms = [])
    {
        // @phpstan-ignore-next-line
        static::addVerify($verify_type, $verify_code, $valid_period_datetime);

        // send mail
        try {
            static::sendVerify($mail_template, $mail_prms);

            return true;
        }
        // throw mailsend Exception
        catch (TransportExceptionInterface $ex) {
            \Illuminate\Support\Facades\Log::error($ex);
            return false;
        }
    }

    /**
     * Add database
     *
     * @param string $verify_type
     * @param string $verify_code
     * @param \Carbon\Carbon $valid_period_datetime
     * @return bool
     */
    protected static function addVerify($verify_type, $verify_code, $valid_period_datetime)
    {
        $loginuser = \Exceedone\Exment\Facades\ExmentFacade::user();

        // only the newest code is good: a code sent before is taken back, so
        // logging in again and again does not make many codes good at once
        \Illuminate\Support\Facades\DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('verify_type', static::VERIFY_TYPE_2FACTOR . $verify_type)
            ->where('login_user_id', $loginuser->id)
            ->delete();

        // set database
        \Illuminate\Support\Facades\DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->insert(
                [
                    'login_user_id' => $loginuser->id,
                    'email' => $loginuser->email,
                    'verify_code' => $verify_code,
                    'verify_type' => static::VERIFY_TYPE_2FACTOR . $verify_type,
                    'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
                ]
            );

        return true;
    }

    /**
     * Send verify
     *
     * @param string|CustomValue $mail_template
     * @param array $mail_prms
     * @return MailSender
     */
    // @phpstan-ignore-next-line
    protected static function sendVerify($mail_template, $mail_prms = []): MailSender
    {
        $loginuser = \ExmentAdminCore\Admin\Facades\Admin::user();

        // send mail
        $sender = MailSender::make($mail_template, $loginuser->email)
            ->prms($mail_prms)
            ->user($loginuser)
            ->disableHistoryBody();
        $sender->send();
        return $sender;
    }


    // @phpstan-ignore-next-line
    public static function deleteCode($verify_type, $verify_code)
    {
        $loginuser = \ExmentAdminCore\Admin\Facades\Admin::user();
        \Illuminate\Support\Facades\DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('verify_code', $verify_code)
            ->where('verify_type', static::VERIFY_TYPE_2FACTOR . $verify_type)
            ->where('email', $loginuser->email)
            ->where('login_user_id', $loginuser->id)
            ->delete();
    }
}
