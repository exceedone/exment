<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Providers\CustomUserProvider;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\PasswordHistory;

/**
 * PasswordHistoryRule
 */
class PasswordHistoryRule implements Rule
{
    public function __construct()
    {
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

        // get login user info
        $login_user = \Exment::user();
        if (!isset($login_user)) {
            // get user info by email
            $login_user = CustomUserProvider::RetrieveByCredential(['username' => $this->data['email']]);
        }
        // can't get user when initialize
        if (!isset($login_user)) {
            return true;
        }

        // get password history
        $old_passwords = PasswordHistory::where('login_user_id', $login_user->id)
            ->orderby('created_at', 'desc')->limit($cnt)->pluck('password');
        
        if (count($old_passwords) == 0) {
            return true;
        } 

        return !($old_passwords->contains(function ($old_password) use($value){
            return \Hash::check($value, $old_password);
        }));
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('error.password_history');
    }
}
