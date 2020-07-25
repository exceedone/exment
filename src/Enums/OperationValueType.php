<?php

namespace Exceedone\Exment\Enums;

class OperationValueType extends EnumBase
{
    public const EXECUTE_DATETIME = 'execute_datetime';
    public const LOGIN_USER = 'login_user';

    
    public static function getOperationValueOptions($operation_update_type, $custom_column)
    {
        if($operation_update_type != OperationUpdateType::SYSTEM){
            return [];
        }

        if(ColumnType::isDate($custom_column->column_type)){
            return [static::EXECUTE_DATETIME => exmtrans('custom_operation_data.operation_value_type_options.execute_datetime')];
        }
    }
}
