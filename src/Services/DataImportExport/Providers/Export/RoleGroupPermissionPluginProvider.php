<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;

class RoleGroupPermissionPluginProvider extends RoleGroupPermissionProvider
{
    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_plugin';
    }
    
    protected function setRoleTypeFilter(&$query)
    {
        $query->where('role_group_permission_type', RoleType::PLUGIN);
    }
    
    protected function getRoleGroupType(): RoleGroupType
    {
        return RoleGroupType::PLUGIN();
    }

    protected function setHeadersOfType(array &$headers, array &$titles): void
    {
        $headers[] = "role_group_target_id"; 
        $titles[] = exmtrans('role_group.role_group_target_plugin'); 
    }

    protected function setBodiesOfType(array &$body_items, $record): void
    {
        $body_items[] = $record->role_group_target_id;
    }
}
