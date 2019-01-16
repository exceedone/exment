<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;

trait UseRequestSessionTrait
{
    /**
     * get all records. use system session
     */
    public static function allRecords(){
        return System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName()), function(){
            return self::all();
        });
    }
}
