<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Auth\HasPermissions;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Traits\AdminBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Enums\MailKeyName;
use Illuminate\Support\Facades\Hash;

/**
 * @phpstan-consistent-constructor
 * @property mixed $password
 * @property mixed $login_provider
 * @property mixed $password_reset_flg
 * @property mixed $base_user_id
 * @property mixed $avatar
 * @property mixed $base_user
 * @property mixed $created_at
 * @property mixed $updated_at
 * @method static \Illuminate\Database\Query\Builder whereNull($columns, $boolean = 'and', $not = false)
 */
class LoginUser extends ModelBase implements \Illuminate\Contracts\Auth\Authenticatable, \Illuminate\Contracts\Auth\CanResetPassword
{
    use AdminBuilder;
    use HasPermissions;
    use HasApiTokens;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    /**
     * send password
     */
    protected $send_password = null;

    /**
     * is change password
     */
    protected $changePassword = false;

    /**
     * taale "user"
     */
    public function base_user(): BelongsTo
    {
        return $this->belongsTo(getModelName(SystemTableName::USER), 'base_user_id');
    }

    public function getUserNameAttribute()
    {
        return $this->base_user->value['user_name'] ?? null;
    }
    public function getUserCodeAttribute()
    {
        return $this->base_user->value['user_code'] ?? null;
    }
    public function getEmailAttribute()
    {
        return $this->base_user->value['email'] ?? null;
    }

    public function getNameAttribute()
    {
        return $this->base_user->value['user_name'] ?? null;
    }

    /**
     * Get header user info.
     *
     * @return string
     */
    public function getHeaderInfo()
    {
        $headers = [];
        foreach (System::header_user_info() as $field) {
            if ($field == SystemColumn::CREATED_AT) {
                $title = exmtrans('common.created_at');
                $value = $this->base_user->created_at;
            } else {
                /** @var CustomColumn|null $column */
                $column = CustomColumn::find($field);
                if (!isset($column)) {
                    continue;
                }
                $title = $column->column_view_name;
                $value = $this->base_user->getValue($column->column_name, true);
            }
            $headers[] = exmtrans('common.format_keyvalue', $title, $value);
        }
        return implode('<br />', $headers);
    }

    /**
     * Get avatar attribute.
     *
     * @param string $avatar
     *
     * @return string
     */
    public function getDisplayAvatarAttribute($avatar = null)
    {
        $avatar = $this->avatar;
        if ($avatar) {
            return Storage::disk(config('admin.upload.disk'))->url($avatar);
        }
        return asset(Define::USER_IMAGE_LINK);
    }

    /**
     * Get organizations user joined.
     * ONLY JOIN. not contains upper and downer.
     * @return mixed
     */
    public function belong_organizations()
    {
        return $this->base_user->belong_organizations();
    }


    public function isLoginProvider()
    {
        return !is_nullorempty($this->login_provider);
    }

    public function findForPassport($username, ?array $credentials = [])
    {
        return LoginUserProvider::RetrieveByCredential(array_merge(['username' => $username], $credentials));
    }

    public function validateForPassportPasswordGrant($password, ?array $credentials = [])
    {
        return LoginUserProvider::ValidateCredential($this, array_merge(['password' => $password], $credentials));
    }

    /**
     * set sendPassword param
     */
    public function sendPassword($sendPassword)
    {
        $this->send_password = $sendPassword;

        return $this;
    }

    /**
     * Get User Model's ID
     * "This function name defines Custom value's user and login user. But this function always return Custom value's user
     *
     * @return string|int
     */
    public function getUserId()
    {
        return $this->base_user_id;
    }

    /**
     * send Password
     */
    protected function send(bool $is_newuser): ?MailSender
    {
        if (!isset($this->send_password)) {
            return null;
        }
        $user = $this->base_user;
        $prms = [];
        $prms['user'] = $this->base_user->value;
        $prms['user']['password'] = $this->send_password;
        $sender = MailSender::make($is_newuser ? MailKeyName::CREATE_USER : MailKeyName::RESET_PASSWORD_ADMIN, $user)
            ->prms($prms)
            ->user($user)
            ->disableHistoryBody();
        $sender->send();

        return $sender;
    }

    /**
     * get value from user setting table
     */
    public function getSettingValue($key, $default = null)
    {
        if (is_null($this->base_user_id)) {
            return $default;
        }
        // get settings from settion
        $settings = System::requestSession("user_setting", function () {
            $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->getUserId()]);
            $settings = $usersetting->settings ?? [];
            return $settings;
        });

        return array_get($settings, $key) ?? $default;

        // $settings = Session::get("user_setting.$key");
        // // if empty, get User Setting table
        // if (!isset($settings)) {
        //     $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->base_user->id]);
        //     $settings = $usersetting->settings ?? [];
        // }
        // return array_get($settings, $key) ?? $default;
    }

    public function setSettingValue($key, $value)
    {
        if (is_null($this->base_user)) {
            return null;
        }
        // set User Setting table
        /** @var UserSetting $userSetting */
        $userSetting = UserSetting::firstOrCreate(['base_user_id' => $this->getUserId()]);
        $settings = $userSetting->settings;
        if (!isset($settings)) {
            $settings = [];
        }
        // set value
        array_set($settings, $key, $value);
        $userSetting->settings = $settings;
        $userSetting->saveOrFail();

        // set settings from settion
        System::clearRequestSession("user_setting");
    }

    /**
     * Clear setting value
     *
     * @param string $key
     * @return UserSetting|null
     * @throws \Throwable
     */
    public function forgetSettingValue($key)
    {
        if (is_null($this->base_user)) {
            return null;
        }
        // set User Setting table
        /** @var UserSetting $userSetting */
        $userSetting = UserSetting::firstOrCreate(['base_user_id' => $this->getUserId()]);
        $userSetting->forgetSetting($key);
        $userSetting->saveOrFail();

        // set settings from settion
        System::clearRequestSession("user_setting");

        return $userSetting;
    }

    protected function setBcryptPassword()
    {
        $password = $this->password;
        $original = $this->getRawOriginal('password');

        if (!isset($password)) {
            return;
        }

        if ($password == $original) {
            return;
        }

        if (isset($original) && Hash::check($password, $original)) {
            $this->password = $original;
        } else {
            $this->password = bcrypt($password);

            // only default login
            if (!isset($this->login_provider)) {
                $this->changePassword = true;
            }
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->setBcryptPassword();
        });

        static::saved(function ($model) {
            if ($model->changePassword) {
                // save password history
                PasswordHistory::create([
                    'login_user_id' => $model->id,
                    'password' => $model->password
                ]);
            }
        });

        static::created(function ($model) {
            $model->send(true);
        });
        static::updated(function ($model) {
            $model->send(false);
        });
    }
}
