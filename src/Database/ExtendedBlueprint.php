<?php

namespace Exceedone\Exment\Database;

use Illuminate\Database\Schema\Blueprint;

class ExtendedBlueprint extends Blueprint
{
    /**
     * set created_user, updated_user, deleted_user
     */
    public function timeusers($precision = 0)
    {
        $this->unsignedInteger('created_user_id', $precision)->nullable();
        $this->unsignedInteger('updated_user_id', $precision)->nullable();
    }
}
