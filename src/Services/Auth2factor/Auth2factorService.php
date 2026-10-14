<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Carbon\Carbon;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Services\Login\LoginHistoryService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * For login 2 factor
 */
class Auth2factorService
{
    protected const VERIFY_TYPE_2FACTOR = '2factor_';

    /**
     * Default minutes a required user can use Exment without setting up 2factor. (setting "login_2factor_setup_grace_minutes")
     */
    public const DEFAULT_SETUP_GRACE_MINUTES = 30;

    /**
     * Max value of the setting "login_2factor_setup_grace_minutes". (1 day)
     */
    public const SETUP_GRACE_MINUTES_MAX = 1440;

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

    /**
     * Get the 2factor provider of the login user. (the user's setting, or the system default)
     *
     * @param LoginUser|null $login_user null: the login user of this request.
     * @return mixed
     */
    public static function getProvider(?LoginUser $login_user = null)
    {
        $login_user = $login_user ?? \Exment::user();

        $provider = $login_user->getSettingValue(
            implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
            System::login_2factor_provider() ?? Login2FactorProviderType::EMAIL
        );

        if (!array_has(static::$providers, $provider)) {
            throw new \Exception("Login 2factor provider [$provider] does not exist.");
        }

        return new static::$providers[$provider]();
    }

    /**
     * Whether 2factor authentication is enabled in the system. (config "exment.login_use_2factor" and the system setting)
     *
     * @return bool
     */
    public static function isUse2factor(): bool
    {
        return boolval(config('exment.login_use_2factor', false)) && boolval(System::login_use_2factor());
    }

    /**
     * Whether the users required 2factor are specified by role group. (system setting "login_2factor_target_role_group")
     * false (default): every user is required, as before the setting was added.
     *
     * @return bool
     */
    public static function isTargetRoleGroup(): bool
    {
        return boolval(System::login_2factor_target_role_group());
    }

    /**
     * Whether 2factor authentication is required for the login user.
     * - not specified by role group (default): every user.
     * - specified by role group: the users of a role group whose "require_2factor" is on (as a user, or through the organization),
     *   and the users who belong to no role group.
     *
     * @param LoginUser|null $login_user null: no user to judge. true only when not specified by role group (required whoever the user is).
     * @return bool
     */
    public static function isRequiredFor(?LoginUser $login_user): bool
    {
        if (!static::isUse2factor()) {
            return false;
        }

        if (!static::isTargetRoleGroup()) {
            return true;
        }

        if (is_null($login_user)) {
            return false;
        }

        $role_groups = $login_user->getJoinedRoleGroups();
        if ($role_groups->isEmpty()) {
            return true;
        }

        return $role_groups->contains(function ($role_group) {
            return boolval($role_group->require_2factor);
        });
    }

    /**
     * Whether the login user has set up 2factor authentication of the user's provider.
     * Email: nothing to set up. Google: the authenticator app is registered.
     *
     * @param LoginUser $login_user
     * @return bool
     */
    public static function isSetUp(LoginUser $login_user): bool
    {
        $provider = static::getProvider($login_user);
        if (!is_object($provider) || !method_exists($provider, 'isSetUp')) {
            return true;
        }
        return boolval($provider->isSetUp($login_user));
    }

    /**
     * Whether the login user can get and use recovery codes: required 2factor, and has set it up.
     * A user who has not set up 2factor must never get recovery codes, otherwise the codes would replace the setup.
     *
     * @param LoginUser|null $login_user
     * @return bool
     */
    public static function canUseRecoveryCodes(?LoginUser $login_user): bool
    {
        return !is_null($login_user) && static::isRequiredFor($login_user) && static::isSetUp($login_user);
    }

    /**
     * Minutes a required user can use Exment without setting up 2factor, counted from the first login. 0: must set up at once.
     *
     * @return int
     */
    public static function getSetupGraceMinutes(): int
    {
        $minutes = System::login_2factor_setup_grace_minutes();
        if (is_null($minutes)) {
            return static::DEFAULT_SETUP_GRACE_MINUTES;
        }
        return max(0, intval($minutes));
    }

    /**
     * Whether the login user has to set up 2factor before passing it: specified by role group, and not set up yet.
     * (When every user is required, the setup is asked at once, as before, so there is never a grace period.)
     *
     * @param LoginUser $login_user
     * @return bool
     */
    public static function isSetupPending(LoginUser $login_user): bool
    {
        if (!static::isTargetRoleGroup()) {
            return false;
        }
        return !static::isSetUp($login_user);
    }

    /**
     * Start the grace period for setting up 2factor, if not started yet: save now to "auth2fa_required_at" of the login user.
     * Never restarted until clearRequiredAt() is called, so logging in again does not extend the period.
     *
     * @param LoginUser $login_user
     * @return void
     */
    public static function startSetupGrace(LoginUser $login_user): void
    {
        if (!is_null(static::getRequiredAt($login_user))) {
            return;
        }
        static::saveRequiredAt($login_user, Carbon::now());
    }

    /**
     * Remaining minutes of the grace period for setting up 2factor. (rounded up, at least 1 while in the period)
     *
     * @param LoginUser $login_user
     * @return int|null null: not in the grace period (not started, or the period is over).
     */
    public static function getSetupGraceRemainingMinutes(LoginUser $login_user): ?int
    {
        $required_at = static::getRequiredAt($login_user);
        if (is_null($required_at)) {
            return null;
        }

        $deadline = $required_at->copy()->addMinutes(static::getSetupGraceMinutes());
        $now = Carbon::now();
        if ($deadline->lessThanOrEqualTo($now)) {
            return null;
        }

        return max(1, (int)ceil($now->diffInSeconds($deadline, true) / 60));
    }

    /**
     * Forget the start of the grace period. Call when 2factor is set up, or when the user is not required 2factor any more.
     *
     * @param LoginUser $login_user
     * @return void
     */
    public static function clearRequiredAt(LoginUser $login_user): void
    {
        if (is_null(static::getRequiredAt($login_user))) {
            return;
        }
        static::saveRequiredAt($login_user, null);
    }

    /**
     * @param LoginUser $login_user
     * @return Carbon|null
     */
    protected static function getRequiredAt(LoginUser $login_user): ?Carbon
    {
        $value = $login_user->auth2fa_required_at;
        if (is_nullorempty($value)) {
            return null;
        }
        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    /**
     * Save "auth2fa_required_at" without firing the model events of the login user. (nothing else changes)
     * Does nothing before "exment:update" of the version that added the column.
     *
     * @param LoginUser $login_user
     * @param Carbon|null $required_at
     * @return void
     */
    protected static function saveRequiredAt(LoginUser $login_user, ?Carbon $required_at): void
    {
        if (!hasColumn(SystemTableName::LOGIN_USER, 'auth2fa_required_at')) {
            return;
        }

        $value = is_null($required_at) ? null : $required_at->format('Y-m-d H:i:s');
        LoginUser::query()->where('id', $login_user->id)->update(['auth2fa_required_at' => $value]);
        $login_user->auth2fa_required_at = $value;
        $login_user->syncOriginalAttribute('auth2fa_required_at');
    }

    /**
     * Response after the login user passed 2factor authentication.
     * Marks the session and the login history as verified.
     * At the first time (the user has no recovery code yet), issues the recovery codes and shows them once.
     *
     * @param LoginUser $login_user
     * @param bool $issueRecoveryCodes false: never issue recovery codes. (Ex. logged in by a recovery code)
     * @param string|null $toastMessage message shown on the admin page instead of "logged in". null: "logged in".
     * @param string $toastType type of the message. (success, warning, ...)
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public static function sendVerifiedResponse(LoginUser $login_user, bool $issueRecoveryCodes = true, ?string $toastMessage = null, string $toastType = 'success')
    {
        // set session for 2factor
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        LoginHistoryService::verified2factor();

        if ($issueRecoveryCodes) {
            $codes = Auth2factorRecoveryCodeService::issueIfNone($login_user);
            if (!is_null($codes)) {
                session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR_RECOVERY_CODES => $codes]);
                return redirect(admin_urls('auth-2factor', 'recovery-codes'));
            }
        }

        admin_toastr($toastMessage ?? trans('admin.login_successful'), $toastType);

        return redirect(admin_url(''));
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
        $loginuser = \Admin::user();

        // remove old datetime value
        \DB::table('email_code_verifies')
            ->where('valid_period_datetime', '<', \Carbon\Carbon::now())
            ->delete();

        // get from database
        $query = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
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
            \Log::error($ex);
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
        $loginuser = \Exment::user();

        // set database
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
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
        $loginuser = \Admin::user();

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
        $loginuser = \Admin::user();
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('verify_code', $verify_code)
            ->where('verify_type', static::VERIFY_TYPE_2FACTOR . $verify_type)
            ->where('email', $loginuser->email)
            ->where('login_user_id', $loginuser->id)
            ->delete();
    }
}
