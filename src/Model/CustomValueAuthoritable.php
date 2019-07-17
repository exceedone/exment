<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;

class CustomValueAuthoritable extends ModelBase
{
    /**
     * Set Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function setValueAuthoritable($custom_value){
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        if (in_array($table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY())) {
            return;
        }

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        $model = new self;
        $model->parent_id = $custom_value->id;
        $model->parent_type = $table_name;
        $model->authoritable_type = Permission::CUSTOM_VALUE_EDIT;
        $model->authoritable_user_org_type = SystemTableName::USER;
        $model->authoritable_target_id = $user->base_user_id;
        $model->save();
    }

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function deleteValueAuthoritable($custom_value){
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        static::query()
        ->where('parent_id', $custom_value->id)
        ->where('parent_type', $table_name)
        ->delete();
    }
}
