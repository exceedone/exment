<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Auth\HasPermissions;
use Exceedone\Exment\Providers\CustomUserProvider;
use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Traits\AdminBuilder;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Enums\MailKeyName;

class LoginUser extends ModelBase implements \Illuminate\Contracts\Auth\Authenticatable, \Illuminate\Contracts\Auth\CanResetPassword
{
    use AdminBuilder;
    use HasPermissions;
    use HasApiTokens;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    /**
     * send password
     */
    protected $send_password = null;

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
        return array_get($this->base_user->value, "user_name");
    }

    /**
     * Get avatar attribute.
     *
     * @param string $avatar
     *
     * @return string
     */
    public function getAvatarAttribute($avatar = null)
    {
        if ($avatar) {
            return Storage::disk(config('admin.upload.disk'))->url($avatar);
        }
        return asset('vendor/exment/images/user.png');
    }
    
    public function getDatabaseAvatarAttribute(){
        return $this->attributes['avatar'];
    }

    public function setDatabaseAvatarAttribute($avatar){
        $this->attributes['avatar'] = $avatar;
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
    public function getSettingValue($key)
    {
        if (is_null($this->base_user)) {
            return null;
        }
        // get settings from settion
        $settings = Session::get("user_setting.$key");
        // if empty, get User Setting table
        if (!isset($settings)) {
            $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->base_user->id]);
            $settings = $usersetting->settings ?? [];
        }
        return array_get($settings, $key) ?? null;
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
    
    protected static function boot()
    {
        parent::boot();

        static::created(function ($model) {
            $model->send(true);
        });
        static::updated(function ($model) {
            $model->send(false);
        });
    }
}
