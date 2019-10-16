<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\ChangeFieldItems;

class ConditionType extends EnumBase
{
    use EnumOptionTrait;
    
    const USER = "1";
    const ORGANIZATION = "2";
    const ROLE = "3";
    const SYSTEM = "4";
    const COLUMN = "9";

    public static function SYSTEM_TABLE_OPTIONS($form_priority_type)
    {
        $result = [];

        switch ($form_priority_type) {
            case ConditionType::USER:
                $model = getModelName(SystemTableName::USER)::get();
                foreach ($model as $m) {
                    $result[$m->id] = $m->getLabel();
                }
                break;
            case ConditionType::ORGANIZATION:
                $model = getModelName(SystemTableName::ORGANIZATION)::get();
                foreach ($model as $m) {
                    $result[$m->id] = $m->getLabel();
                }
                break;
            case ConditionType::ROLE:
                $model = RoleGroup::get();
                foreach ($model as $m) {
                    $result[$m->id] = $m->role_group_view_name;
                }
                break;
            default:
                return null;
        }
        return $result;
    }
    
    public function getConditionItem($custom_table, $target){
        switch($this){
            case ConditionType::USER:
                return new ChangeFieldItems\UserItem($custom_table, $target);
            case ConditionType::ORGANIZATION:
                return new ChangeFieldItems\OrganizationItem($custom_table, $target);
            case ConditionType::ROLE:
                return new ChangeFieldItems\RoleGroupItem($custom_table, $target);
        }
    }
}
