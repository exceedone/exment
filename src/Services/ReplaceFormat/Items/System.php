<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Model\System as SystemModel;

/**
 * replace value
 */
class System extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        if (count($this->length_array) < 2) {
            return null;
        }

        $key_system = $this->length_array[1];
        if (SystemModel::hasFunction($key_system)) {
            return SystemModel::{$key_system}();
        }

        // get static value
        if ($key_system == "login_url") {
            return admin_url("auth/login");
        }

        if ($key_system == "system_url") {
            return admin_url("");
        }

        return '';
    }
}
