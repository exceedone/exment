<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;

class OperationValueType extends EnumBase
{
    public const EXECUTE_DATETIME = 'execute_datetime';
    public const LOGIN_USER = 'login_user';
    public const CREATED_USER = 'created_user';
    public const BERONG_ORGANIZATIONS = 'berong_organizations';


    public static function getOperationValueOptions($operation_update_type, $custom_column)
    {
        if ($operation_update_type != OperationUpdateType::SYSTEM) {
            return [];
        }

        if (ColumnType::isDateTime($custom_column->column_type)) {
            return [static::EXECUTE_DATETIME => exmtrans('custom_operation.operation_value_type_options.execute_datetime')];
        }
        if (isMatchString($custom_column->column_type, ColumnType::USER)) {
            return [static::LOGIN_USER => exmtrans('custom_operation.operation_value_type_options.login_user'),
                    static::CREATED_USER => exmtrans('custom_operation.operation_value_type_options.created_user')];
        }
        if (isMatchString($custom_column->column_type, ColumnType::ORGANIZATION)) {
            return [static::BERONG_ORGANIZATIONS => exmtrans('custom_operation.operation_value_type_options.berong_organizations')];
        }
    }

    /**
     * Get operation value. For execute operation.
     *
     * @param CustomColumn  $custom_column
     * @param mixed  $operation_update_type
     * @return mixed
     */
    public static function getOperationValue(CustomColumn $custom_column, $operation_update_type, $custom_value)
    {
        switch ($operation_update_type) {
            case static::EXECUTE_DATETIME:
                return \Carbon\Carbon::now();

            case static::LOGIN_USER:
                $login_user = \Exment::user();
                return $login_user ? $login_user->getUserId() : null;

            case static::CREATED_USER:
                if ($custom_value instanceof CustomValue) {
                    return $custom_value->created_user_id;
                }

                // no break
            case static::BERONG_ORGANIZATIONS:
                $login_user = \Exment::user();
                if (is_null($login_user)) {
                    return null;
                }

                // get joined user's id
                $ids = $login_user->getOrganizationIdsForQuery(JoinedOrgFilterType::ONLY_JOIN);
                // get enable select organizations
                $selectIds = $custom_column->column_item->getSelectOptions(null, null, ['notAjax' => true])->keys();

                // filter organizaions
                return collect($ids)->filter(function ($id) use ($selectIds) {
                    return $selectIds->contains($id);
                })->toArray();
        }
    }
}
