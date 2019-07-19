<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Form\Widgets\ModalInnerForm;
use Carbon\Carbon;

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

        $form = new ModalInnerForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_share_modal');
        $form->modalHeader(exmtrans('common.share'));
        $form->action(admin_urls('data', $tableKey, $id, 'sendShares'));

        $form->description(exmtrans('role_group.share_description'))->setWidth(9, 2);

        // select target users
        $form->multipleSelect('custom_value_edit', exmtrans('role_group.role_type_option_value.custom_value_edit.label'))
            ->options(static::getUserOrgSelectOptions($custom_value->custom_table, Permission::AVAILABLE_EDIT_CUSTOM_VALUE))
            ->default(static::getUserOrgSelectDefault($custom_value, Permission::CUSTOM_VALUE_EDIT))
            ->help(exmtrans('role_group.role_type_option_value.custom_value_edit.help') . exmtrans('common.bootstrap_duallistbox_container.help'))
            ->setWidth(9, 2);

        $form->multipleSelect('custom_value_view', exmtrans('role_group.role_type_option_value.custom_value_view.label'))
            ->options(static::getUserOrgSelectOptions($custom_value->custom_table))
            ->default(static::getUserOrgSelectDefault($custom_value, Permission::CUSTOM_VALUE_VIEW))
            ->help(exmtrans('role_group.role_type_option_value.custom_value_view.help') . exmtrans('common.bootstrap_duallistbox_container.help'))
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
            foreach($items as $item){
                $user_organizations = $request->get($item['name'], []);
                $user_organizations = collect($user_organizations)->filter()->map(function($user_organization) use($custom_value, $item){
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
                    'dbValueFilter' => function(&$model) use($custom_value, $item){
                        $model->where('parent_type', $custom_value->custom_table->table_name)
                        ->where('parent_id', $custom_value->id)
                        ->where('authoritable_type', $item['name']);
                    },
                    'dbDeleteFilter' => function(&$model, $dbValue) use($id, $item, $custom_value){
                        $model->where('parent_type', $custom_value->custom_table->table_name)
                            ->where('parent_id', $custom_value->id)
                            ->where('authoritable_type', $item['name'])
                            ->where('authoritable_user_org_type', array_get((array)$dbValue, 'authoritable_user_org_type'))
                            ->where('authoritable_target_id', array_get((array)$dbValue, 'authoritable_target_id'));
                    },
                    'matchFilter' => function($dbValue, $value) use($id, $item){
                        return array_get((array)$dbValue, 'authoritable_user_org_type') == array_get($value, 'authoritable_user_org_type')
                            && array_get((array)$dbValue, 'authoritable_target_id') == array_get($value, 'authoritable_target_id');
                    },
                ]));
            }
            \DB::commit();

            // send notify
            $shares = collect($shares)->map(function($share){
                return CustomTable::getEloquent($share['authoritable_user_org_type'])->getValueModel($share['authoritable_target_id']);
            });
            
            // share
            $notifies = $custom_value->custom_table->notifies;

            // loop for $notifies
            foreach ($notifies as $notify) {
                $notify->notifySharedUser($custom_value, $shares);
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
    public static function getUserOrgSelectOptions($custom_table, $permission = null){
        // get options
        $users = CustomTable::getEloquent(SystemTableName::USER)->getSelectOptions(
            [
                'display_table' => $custom_table,
                'permission' => $permission,
            ]
        );
        $organizations = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getSelectOptions(
            [
                'display_table' => $custom_table,
                'permission' => $permission,
            ]
        );
        
        // get mapkey
        $users = $users->mapWithKeys(function($item, $key){
            return [SystemTableName::USER . '_' . $key => $item];
        });
        $organizations = $organizations->mapWithKeys(function($item, $key){
            return [SystemTableName::ORGANIZATION . '_' . $key => $item];
        });

        $options = array_merge($users->toArray(), $organizations->toArray());
        return $options;
    }

    /**
     * get listbox options default
     *
     * @param [type] $custom_value
     * @return void
     */
    protected static function getUserOrgSelectDefault($custom_value, $permission){
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        
        // get values
        $items = static::query()
            ->where('parent_id', $custom_value->id)
            ->where('parent_type', $table_name)
            ->where('authoritable_type',  $permission)
            ->get();
        
        $defaults = $items->map(function($item, $key){
            return array_get($item, 'authoritable_user_org_type') . '_' . array_get($item, 'authoritable_target_id');
        })->toArray();

        return $defaults;
    }
}
