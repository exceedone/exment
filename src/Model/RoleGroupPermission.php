<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;

class RoleGroupPermission extends ModelBase
{
    protected $casts = ['permissions' => 'json'];
}
