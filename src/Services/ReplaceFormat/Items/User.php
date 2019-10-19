<?php
namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Carbon\Carbon;
use Exceedone\Exment\Model\Workflow as WorkflowModel;
use Exceedone\Exment\Model\WorkflowStatus;

/**
 * replace value
 */
class User extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        $subkey = count($this->length_array) > 1 ? $this->length_array[1] : null;
        if(is_nullorempty($subkey)){
            return null;
        }
        $user = \Exment::user()->base_user;

        switch($subkey){
            case 'user_name':
            case 'user_code':
            case 'email':
                return $user->getValue($subkey);
        }

        return null;
    }
}
