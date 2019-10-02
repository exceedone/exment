<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Auth\HasPermissions;
use Exceedone\Exment\Providers\CustomUserProvider;
use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Traits\AdminBuilder;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Enums\MailKeyName;
use Illuminate\Support\Facades\Hash;

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
    public function base_user()
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
        return asset('vendor/exment/images/user.png');
    }
    
    public function isLoginProvider()
    {
        return !is_nullorempty($this->login_provider);
    }
    
    public function findForPassport($username)
    {
        return CustomUserProvider::RetrieveByCredential(['username' => $username]);
    }

    public function validateForPassportPasswordGrant($password)
    {
        return CustomUserProvider::ValidateCredential($this, ['password' => $password]);
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
     * send Password
     */
    protected function send($is_newuser)
    {
        if (!isset($this->send_password)) {
            return;
        }
        $user = $this->base_user;
        $prms = [];
        $prms['user'] = $this->base_user->value;
        $prms['user']['password'] = $this->send_password;
        MailSender::make($is_newuser ? MailKeyName::CREATE_USER : MailKeyName::RESET_PASSWORD_ADMIN, $user)
            ->prms($prms)
            ->user($user)
            ->disableHistoryBody()
            ->send();
    }

    /**
     * get value from user setting table
     */
    public function getSettingValue($key, $default = null)
    {
        if (is_null($this->base_user)) {
            return $default;
        }
        // get settings from settion
        $settings = Session::get("user_setting.$key");
        // if empty, get User Setting table
        if (!isset($settings)) {
            $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->base_user->id]);
            $settings = $usersetting->settings ?? [];
        }
        return array_get($settings, $key) ?? $default;
    }
    public function setSettingValue($key, $value)
    {
        if (is_null($this->base_user)) {
            return null;
        }
        // set User Setting table
        $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->base_user->id]);
        $settings = $usersetting->settings;
        if (!isset($settings)) {
            $settings = [];
        }
        // set value
        array_set($settings, $key, $value);
        $usersetting->settings = $settings;
        $usersetting->saveOrFail();

        // put session
        Session::put("user_setting.$key", $settings);
    }

    protected function setBcryptPassword()
    {
        $password = $this->password;
        $original = $this->getOriginal('password');

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
