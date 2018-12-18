<?php

namespace Exceedone\Exment;

use Exceedone\Exment\Model\Menu;
use Illuminate\Support\Facades\Auth;

/**
 * Class Admin.
 */
class Exment
{
    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    public function menu()
    {
        return (new Menu())->toTree();
    }

    /**
     * get user. multi supported admin and adminapi
     */
    public function user($guards = null){
        if (is_null($guards)) {
            $guards = ['adminapi', 'admin'];
        }
        if(is_string($guards)){
            $guards = [$guards];
        }
        
        foreach ($guards as $guard) {
            # code...
            $user = Auth::guard($guard)->user();
            if(isset($user)){ 
                return $user; 
            }
        }
        return null;
    }
}
