<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Role group item for User and organizaiton 
 */
class UserOrgRoleGroupItem
{
    protected $custom_table;
    
    public function __construct($custom_table)
    {
        $this->custom_table = $custom_table;
    }

    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
        if(!System::permission_available()){
            return;
        }

        $defaults = [];
        if(isset($id)){
            $defaults = $this->custom_table->getValueModel($id)->belong_role_groups()->pluck('id')->toArray();
        }

        $form->listbox('role_groups', exmtrans("role_group.header"))
            ->default($defaults)
            ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->help(exmtrans('common.bootstrap_duallistbox_container.help'))
            ->options(function($option){
                return RoleGroup::all()->pluck('role_group_view_name', 'id');
            });
        $form->ignore('role_groups');
    }

    /**
     * saving event
     */
    public function saving($form, $id = null)
    {
    }
    
    /**
     * saved event
     */
    public function saved($form, $id)
    {
        if(!System::permission_available()){
            return;
        }

        // get request value
        $request = request();
        if(!$request->has('role_groups')){
            return;
        }

        $role_groups = collect($request->get('role_groups', []))->filter()->map(function($role_group) use($id){
            return [
                'role_group_id' => $role_group,
                'role_group_user_org_type' => $this->custom_table->table_name,
                'role_group_target_id' => $id,
            ];
        });
        
        \Schema::insertDelete(SystemTableName::ROLE_GROUP_USER_ORGANIZATION, $role_groups, [
            'dbValueFilter' => function(&$model) use($id){
                $model->where('role_group_target_id', $id)
                    ->where('role_group_user_org_type', $this->custom_table->table_name);
            },
            'dbDeleteFilter' => function(&$model) use($id){
                $model->where('role_group_target_id', $id)
                    ->where('role_group_user_org_type', $this->custom_table->table_name);
            },
            'matchFilter' => function($dbValue, $value){
                return array_get((array)$dbValue, 'role_group_id') == array_get($value, 'role_group_id');
            },
        ]);
    }
    
    public static function getItem(...$args)
    {
        list($custom_table) = $args + [null];
        return new self($custom_table);
    }
}
