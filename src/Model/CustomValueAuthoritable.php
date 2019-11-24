<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Model\CustomValue;
use Carbon\Carbon;

class CustomValueAuthoritable extends ModelBase
{
    /**
     * Set Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function setValueAuthoritable($custom_value)
    {
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        if (in_array($table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY())) {
            return;
        }

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        self::firstOrCreate([
            'parent_id' => $custom_value->id,
            'parent_type' => $table_name,
            'authoritable_type' => Permission::CUSTOM_VALUE_EDIT,
            'authoritable_user_org_type' => SystemTableName::USER,
            'authoritable_target_id' => $user->base_user_id,
        ]);


        /////// share organization
        if(System::custom_value_save_autoshare() != CustomValueAutoShare::USER_ORGANIZATION){
            return;
        }
        
        // get organizations. OK only_join users
        $belong_organizations = $user->base_user->belong_organizations;

        foreach($belong_organizations as $belong_organization){
            // check permission as organization.
            if(!static::hasPermissionAsOrganization($custom_table, $belong_organization)){
                continue;
            }

            self::firstOrCreate([
                'parent_id' => $custom_value->id,
                'parent_type' => $table_name,
                'authoritable_type' => Permission::CUSTOM_VALUE_EDIT,
                'authoritable_user_org_type' => SystemTableName::ORGANIZATION,
                'authoritable_target_id' => $belong_organization->id,
            ]);
        }
    }

    /**
     * Set Authoritable By User and Org Array
     *
     * @return void
     */
    public static function setAuthoritableByUserOrgArray($custom_value, $arrays)
    {
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        if (in_array($table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY())) {
            return;
        }

        foreach($arrays as $array){
            if($array instanceof CustomValue){
                $related_id = array_get($array, 'id');
                $related_type = $array->custom_table->table_name;
            }else{
                $related_id = array_get($array, 'related_id');
                $related_type = array_get($array, 'related_type');    
            }

            if(\is_nullorempty($related_id) || \is_nullorempty($related_type)){
                continue;
            }

            self::firstOrCreate([
                'parent_id' => $custom_value->id,
                'parent_type' => $table_name,
                'authoritable_type' => Permission::CUSTOM_VALUE_VIEW,
                'authoritable_user_org_type' => $related_type,
                'authoritable_target_id' => $related_id,
            ]);
        }
    }

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function deleteValueAuthoritable($custom_value)
    {
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        static::query()
            ->where('parent_id', $custom_value->id)
            ->where('parent_type', $table_name)
            ->delete();
    }

    /**
     * Get share form
     *
     * @return void
     */
    public static function getShareDialogForm($custom_value)
    {
        // create form fields
        $tableKey = $custom_value->custom_table->table_name;
        $id = $custom_value->id;

        $form = new ModalForm();
        $form->modalAttribute('id', 'data_share_modal');
        $form->modalHeader(exmtrans('common.shared'));
        $form->action(admin_urls('data', $tableKey, $id, 'sendShares'));

        $form->description(exmtrans('role_group.share_description'))->setWidth(9, 2);

        // select target users
        $default = static::getUserOrgSelectDefault($custom_value, Permission::CUSTOM_VALUE_EDIT);
        list($options, $ajax) = static::getUserOrgSelectOptions($custom_value->custom_table, null, false, $default);
        $form->multipleSelect('custom_value_edit', exmtrans('role_group.role_type_option_value.custom_value_edit.label'))
            ->options($options)
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans('role_group.role_type_option_value.custom_value_edit.help'))
            ->setWidth(9, 2);

        $default = static::getUserOrgSelectDefault($custom_value, Permission::CUSTOM_VALUE_VIEW);
        list($options, $ajax) = static::getUserOrgSelectOptions($custom_value->custom_table, null, false, $default);
        $form->multipleSelect('custom_value_view', exmtrans('role_group.role_type_option_value.custom_value_view.label'))
            ->options($options)
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans('role_group.role_type_option_value.custom_value_view.help'))
            ->setWidth(9, 2);

        return $form;
    }

    /**
     * Set share form
     *
     * @return void
     */
    public static function saveShareDialogForm($custom_value)
    {
        $custom_table = $custom_value->custom_table;

        $request = request();
        // create form fields
        $tableKey = $custom_table->table_name;
        $id = $custom_value->id;
        
        // check permission
        if (!$custom_table->hasPermissionEditData($id) || !$custom_table->hasPermission(Permission::CUSTOM_VALUE_SHARE)) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => trans('admin.deny'),
            ]);
        }

        \DB::beginTransaction();

        try {
            // get user and org
            $items = [
                ['name' => 'custom_value_edit'],
                ['name' => 'custom_value_view'],
            ];

            $shares = [];
            foreach ($items as $item) {
                $user_organizations = $request->get($item['name'], []);
                $user_organizations = collect($user_organizations)->filter()->map(function ($user_organization) use ($custom_value, $item) {
                    list($authoritable_user_org_type, $authoritable_target_id) = explode('_', $user_organization);
                    return [
                        'authoritable_type' => $item['name'],
                        'authoritable_user_org_type' => $authoritable_user_org_type,
                        'authoritable_target_id' => $authoritable_target_id,
                        'parent_type' => $custom_value->custom_table->table_name,
                        'parent_id' => $custom_value->id,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                });
                    
                $shares = array_merge($shares, \Schema::insertDelete(SystemTableName::CUSTOM_VALUE_AUTHORITABLE, $user_organizations, [
                    'dbValueFilter' => function (&$model) use ($custom_value, $item) {
                        $model->where('parent_type', $custom_value->custom_table->table_name)
                        ->where('parent_id', $custom_value->id)
                        ->where('authoritable_type', $item['name']);
                    },
                    'dbDeleteFilter' => function (&$model, $dbValue) use ($id, $item, $custom_value) {
                        $model->where('parent_type', $custom_value->custom_table->table_name)
                            ->where('parent_id', $custom_value->id)
                            ->where('authoritable_type', $item['name'])
                            ->where('authoritable_user_org_type', array_get((array)$dbValue, 'authoritable_user_org_type'))
                            ->where('authoritable_target_id', array_get((array)$dbValue, 'authoritable_target_id'));
                    },
                    'matchFilter' => function ($dbValue, $value) use ($id, $item) {
                        return array_get((array)$dbValue, 'authoritable_user_org_type') == array_get($value, 'authoritable_user_org_type')
                            && array_get((array)$dbValue, 'authoritable_target_id') == array_get($value, 'authoritable_target_id');
                    },
                ]));
            }
            \DB::commit();

            // send notify
            $shares = collect($shares)->map(function ($share) {
                return CustomTable::getEloquent($share['authoritable_user_org_type'])->getValueModel($share['authoritable_target_id']);
            });
            
            // loop for $notifies
            foreach ($custom_value->custom_table->notifies as $notify) {
                $notify->notifyCreateUpdateUser($custom_value, NotifySavedType::SHARE, ['targetUserOrgs' => $shares]);
            }

            return getAjaxResponse([
                'result'  => true,
                'toastr' => trans('admin.save_succeeded'),
            ]);
        } catch (Exception $exception) {
            //TODO:error handling
            \DB::rollback();
            throw $exception;
        }
    }

    /**
     * get listbox options contains user and org
     *
     * @param [type] $custom_table
     * @return void
     */
    public static function getUserOrgSelectOptions($custom_table, $permission = null, $ignoreLoginUser = false, $default = null)
    {
        $options = collect();
        $ajax = null;

        $keys = [SystemTableName::USER];
        if (System::organization_available()) {
            $keys[] = SystemTableName::ORGANIZATION;
        }

        foreach ($keys as $key) {
            list($optionItem, $ajaxItem) = CustomTable::getEloquent($key)->getSelectOptionsAndAjaxUrl([
                'display_table' => $custom_table,
                'selected_value' => str_replace("{$key}_", "", $default),
                'permission' => $permission,
            ]);

            if ($ignoreLoginUser && $key == SystemTableName::USER) {
                $user_id = \Exment::user()->base_user_id;
                $optionItem = $optionItem->filter(function ($user, $id) use ($user_id) {
                    return $id != $user_id;
                });
            }
                
            $options = $options->merge(collect($optionItem)->mapWithKeys(function ($i, $k) use ($key) {
                return [$key . '_' . $k => $i];
            }), $options);
         
            // add ajax
            if (isset($ajaxItem)) {
                $ajax = admin_url('webapi/user_organization/select');
            }
        }

        return [$options->toArray(), $ajax];
    }

    /**
     * get listbox options default
     *
     * @param [type] $custom_value
     * @return void
     */
    protected static function getUserOrgSelectDefault($custom_value, $permission)
    {
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        
        // get values
        $items = static::query()
            ->where('parent_id', $custom_value->id)
            ->where('parent_type', $table_name)
            ->where('authoritable_type', $permission)
            ->get();
        
        $defaults = $items->map(function ($item, $key) {
            return array_get($item, 'authoritable_user_org_type') . '_' . array_get($item, 'authoritable_target_id');
        })->toArray();

        return $defaults;
    }

    /**
     * Whether target organization has edit permission
     *
     * @param CustomTable $custom_table
     * @param CustomValue $organization
     * @return boolean
     */
    protected static function hasPermissionAsOrganization($custom_table, $organization){
        if (boolval($custom_table->getOption('all_user_editable_flg'))) {
            return true;
        }

        // check role group as org. if not has, conitnue            
        if(!\is_nullorempty(RoleGroup::getHasPermissionRoleGroup(null, $organization->id))){
            return true;
        }

        return false;
    }
}
