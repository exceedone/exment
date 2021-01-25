<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\ConditionItems;

/**
 * Conditiion Difinition.
 * 
 * If ConditionType is COLUMN:
 *     target_column_id is custom column's id, not use ConditionTypeDetail. 
 * If ConditionType is CONDITION:
 *     target_column_id is USER, ORGANIZATION, ROLE, FORM
 *
 * @method static ConditionTypeDetail USER()
 * @method static ConditionTypeDetail ORGANIZATION()
 * @method static ConditionTypeDetail ROLE()
 * @method static ConditionTypeDetail SYSTEM()
 * @method static ConditionTypeDetail FORM()
 * @method static ConditionTypeDetail COLUMN()
 */
class ConditionTypeDetail extends EnumBase
{
    const USER = "1";
    const ORGANIZATION = "2";
    const ROLE = "3";
    const SYSTEM = "4";
    const FORM = "5";
    const COLUMN = "9";

    public static function CONDITION_OPTIONS()
    {
        return [
            static::USER(),
            static::ORGANIZATION(),
            static::ROLE(),
        ];
    }

    public static function SYSTEM_TABLE_OPTIONS($form_priority_type)
    {
        $result = [];

        switch ($form_priority_type) {
            case ConditionTypeDetail::USER:
                $model = getModelName(SystemTableName::USER)::get();
                foreach ($model as $m) {
                    $result[$m->id] = $m->getLabel();
                }
                break;
            case ConditionTypeDetail::ORGANIZATION:
                $model = getModelName(SystemTableName::ORGANIZATION)::get();
                foreach ($model as $m) {
                    $result[$m->id] = $m->getLabel();
                }
                break;
            case ConditionTypeDetail::ROLE:
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
}
