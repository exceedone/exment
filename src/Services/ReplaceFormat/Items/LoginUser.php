<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class LoginUser extends ItemBase
{
    /**
     * Replace login user infomation
     */
    public function replace($format, $options = [])
    {
        if (count($this->length_array) < 2) {
            return null;
        }

        $column_name = $this->length_array[1];

        $login_user = \Exment::user()?\Exment::user()->base_user: null;
        if ($login_user) {
            return $login_user->getValue($column_name, true);
        }
        return null;
    }
}
