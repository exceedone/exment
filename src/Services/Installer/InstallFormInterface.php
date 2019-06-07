<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;

/**
 * 
 */
interface InstallFormInterface
{
    public function index();

    public function post();
}
