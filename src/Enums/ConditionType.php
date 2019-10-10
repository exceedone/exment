<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\RoleGroup;

class ConditionType extends EnumBase
{
    use EnumOptionTrait;
    
    const USER = 1;
    const ORGANIZATION = 2;
    const ROLE = 3;
    const SYSTEM = 4;
    const COLUMN = 9;

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
    
    public static function CONDITION_OPTIONS()
    {
        return [
            static::USER => [
                ['id' => ViewColumnFilterOption::EQ, 'name' => 'eq'],
            ],
            static::ORGANIZATION => [
                ['id' => ViewColumnFilterOption::EQ, 'name' => 'eq'],
            ],
            static::ROLE => [
                ['id' => ViewColumnFilterOption::EQ, 'name' => 'eq'],
            ],
        ];
    }
}
