<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class RoleGroupPermissionMasterProvider extends RoleGroupPermissionProvider
{
    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_master';
    }
    
    protected function setRoleTypeFilter(&$query)
    {
        $ids = CustomTable::whereIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER())
            ->pluck('id');
        $query->where('role_group_permission_type', RoleType::TABLE)
            ->whereIn('role_group_target_id', $ids);
    }
    
    protected function getRoleGroupType(): RoleGroupType
    {
        return RoleGroupType::MASTER();
    }

    protected function setHeadersOfType(array &$headers, array &$titles): void
    {
        $headers[] = "role_group_target_id"; 
        $titles[] = exmtrans('role_group.role_group_target_table'); 
    }

    protected function setBodiesOfType(array &$body_items, $record): void
    {
        $body_items[] = $record->role_group_target_id;
    }
}
