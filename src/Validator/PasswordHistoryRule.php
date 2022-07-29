<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PasswordHistory;

/**
 * PasswordHistoryRule
 */
class PasswordHistoryRule implements Rule
{
    protected $login_user;

    public function __construct(?LoginUser $login_user)
    {
        $this->login_user = $login_user;
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        if (is_null($value)) {
            return true;
        }

        if (empty($cnt = System::password_history_cnt())) {
            $cnt = 1;
        }

        // can't get user when initialize
        if (!isset($this->login_user)) {
            return true;
        }

        // get password history
        $old_passwords = PasswordHistory::where('login_user_id', $this->login_user->id)
            ->orderby('created_at', 'desc')->limit($cnt)->pluck('password');

        if (count($old_passwords) == 0) {
            return true;
        }

        return !($old_passwords->contains(function ($current_password) use ($value) {
            return \Hash::check($value, $current_password);
        }));
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.password_history');
    }
}
