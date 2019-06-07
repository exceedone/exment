<?php

namespace Exceedone\Exment\Database;

trait ConnectionTrait
{
    public function canConnection(){
        try{
            \Schema::getTableListing();
            return true;
        }catch(\Exception $ex){
            return false;
        }
    }
}
