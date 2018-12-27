<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Form\Field\PivotMultiSelect;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Form;

trait RoleForm
{
    /**
     * add role to form.
     * @param mixed $form
     */
    protected function addRoleForm($form, $role_type)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return;
        }
        if($role_type instanceof RoleType){
            $role_type = $role_type->toString();
        }

        // role setting --------------------------------------------------
        $form->header(exmtrans('role.permission_header'))->hr();
        switch ($role_type) {
            case RoleType::VALUE():
                $form->description(exmtrans(System::organization_available() ? 'role.description_form.custom_value' : 'role.description_form.custom_value_disableorg'));
                break;
                
            case RoleType::TABLE():
                $form->description(exmtrans(System::organization_available() ? 'role.description_form.custom_table' : 'role.description_form.custom_table_disableorg'));
            break;
            
            case RoleType::SYSTEM():
                $form->description(exmtrans(System::organization_available() ? 'role.description_form.system' : 'role.description_form.system_disableorg'));
                break;
            
            case RoleType::PLUGIN():
                $form->description(exmtrans(System::organization_available() ? 'role.description_form.plugin' : 'role.description_form.plugin_disableorg'));
                break;
        }

        // Add Role --------------------------------------------------
        Role::roleLoop($role_type, function ($role, $related_type) use ($role_type, $form) {
            switch ($related_type) {
                case SystemTableName::USER:
                $related_types = ['column_name' => 'user_name', 'view_name' => exmtrans('user.default_table_name'), 'suffix' => 'userable'];
                break;
            default:
                $related_types = ['column_name' => 'organization_name', 'view_name' => exmtrans('organization.default_table_name'), 'suffix' => 'organizationable'];
                break;
            }

            // declare pivotMultiSelect info
            $role_name = $role->getRoleName($related_type);
            $role_view_name = "{$role->role_view_name}(".array_get($related_types, 'view_name').")";
            $pivots = ['role_id' => $role->id, 'related_type' => $related_type];
            $related_type_table = CustomTable::findByName($related_type);

            $field = new PivotMultiSelect($role_name, [$role_view_name]);
            $field->options(function ($options) use ($role_type, $related_type_table, $related_types) {
                if(RoleType::VALUE == $role_type){
                    return $related_type_table->getOptions($options, $this->custom_table);
                }
                return $related_type_table->getOptions($options, null, true);
            })
            ->pivot($pivots);
            if (!$related_type_table->isGetOptions()) {
                $field->ajax($related_type_table->getOptionAjaxUrl());
            }
            $form->pushField($field);
        });
    }
}
