<?php

namespace Exceedone\Exment\Model;

class PasswordHistory extends ModelBase
{
    protected $guarded = ['id'];

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
        
        if (!isset($original) || !Hash::check($password, $original)) {
            $this->password = bcrypt($password);
        }
    }
    
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->setBcryptPassword();
        });
    }
}
