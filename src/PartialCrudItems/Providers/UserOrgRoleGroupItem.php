<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\PartialCrudItems\ProviderBase;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;

/**
 * Role group item for User and organizaiton
 * @phpstan-consistent-constructor
 */
class UserOrgRoleGroupItem extends ProviderBase
{
    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
        if (!System::permission_available()) {
            return;
        }

        if (!\Exment::user()->hasPermission([Permission::ROLE_GROUP_ALL, Permission::ROLE_GROUP_USER_ORGANIZATION])) {
            return;
        }

        $defaults = [];
        if (isset($id)) {
            $custom_value = $this->custom_table->getValueModel($id);
            $defaults = $custom_value ? $custom_value->belong_role_groups()->pluck('id')->toArray() : [];
        }

        $form->listbox('role_groups', exmtrans("role_group.header"))
            ->default($defaults)
            ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->help(exmtrans('common.bootstrap_duallistbox_container.help'))
            ->options(function ($option) {
                return RoleGroup::all()->pluck('role_group_view_name', 'id');
            });
        $form->ignore('role_groups');
    }

    /**
     * saved event
     */
    public function saved($form, $id)
    {
        if (!System::permission_available()) {
            return;
        }

        if (!\Exment::user()->hasPermission([Permission::ROLE_GROUP_ALL, Permission::ROLE_GROUP_USER_ORGANIZATION])) {
            return;
        }

        // get request value
        $request = request();
        if (!$request->has('role_groups')) {
            return;
        }

        $role_groups = collect($request->get('role_groups', []))->filter()->map(function ($role_group) use ($id) {
            return [
                'role_group_id' => $role_group,
                'role_group_user_org_type' => $this->custom_table->table_name,
                'role_group_target_id' => $id,
            ];
        });

        \Schema::insertDelete(SystemTableName::ROLE_GROUP_USER_ORGANIZATION, $role_groups, [
            'dbValueFilter' => function (&$model) use ($id) {
                $model->where('role_group_target_id', $id)
                    ->where('role_group_user_org_type', $this->custom_table->table_name);
            },
            'dbDeleteFilter' => function (&$model, $dbValue) use ($id) {
                $model->where('role_group_target_id', $id)
                    ->where('role_group_user_org_type', $this->custom_table->table_name)
                    ->where('role_group_id', array_get((array)$dbValue, 'role_group_id'));
            },
            'matchFilter' => function ($dbValue, $value) {
                return array_get((array)$dbValue, 'role_group_id') == array_get($value, 'role_group_id');
            },
        ]);

        System::clearCache();
    }
}
