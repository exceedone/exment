<?php

namespace Exceedone\Exment\Database;

trait ConnectionTrait
{
    /**
     * Get database version.
     *
     * @return void
     */
    public function getVersion(){
        return \Schema::getVersion();
    }

    public function canConnection(){
        try{
            \Schema::getTableListing();
            return true;
        }catch(\Exception $ex){
            return false;
        }
    }
}
