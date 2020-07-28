<?php

namespace Exceedone\Exment\Enums;

class OperationValueType extends EnumBase
{
    public const EXECUTE_DATETIME = 'execute_datetime';
    public const LOGIN_USER = 'login_user';

    
    public static function getOperationValueOptions($operation_update_type, $custom_column)
    {
        if ($operation_update_type != OperationUpdateType::SYSTEM) {
            return [];
        }

        if (ColumnType::isDateTime($custom_column->column_type)) {
            return [static::EXECUTE_DATETIME => exmtrans('custom_operation.operation_value_type_options.execute_datetime')];
        }
        if (isMatchString($custom_column->column_type, ColumnType::USER)) {
            return [static::LOGIN_USER => exmtrans('custom_operation.operation_value_type_options.login_user')];
        }
    }

    public static function getOperationValue($operation_update_type)
    {
        switch ($operation_update_type) {
            case static::EXECUTE_DATETIME:
                return \Carbon\Carbon::now();
                
            case static::LOGIN_USER:
                $login_user = \Exment::user();
                return $login_user ? $login_user->getUserId() : null;
        }
    }
}
