<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Carbon\Carbon;

class DataShareAuthoritable extends ModelBase
{
    use Traits\DataShareTrait;

    /**
     * Set Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function setValueAuthoritable($target_data)
    {
        list($target_type, $target_key) = static::getParentType($target_data);

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        self::firstOrCreate([
            'parent_id' => $target_data->id,
            'parent_type' => $target_key,
            'authoritable_type' => Permission::DATA_SHARE_EDIT,
            'authoritable_user_org_type' => SystemTableName::USER,
            'authoritable_target_id' => $user->base_user_id,
        ]);
    }

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function getParentType($target_data)
    {
        if ($target_data instanceof CustomView) {
            return ['custom_view', '_custom_view'];
        } else {
            return ['dashboard', '_dashboard'];
        }
    }

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function deleteValueAuthoritable($target_data)
    {
        list(, $table_name) = static::getParentType($target_data);
        static::query()
            ->where('parent_id', $target_data->id)
            ->where('parent_type', $table_name)
            ->delete();
    }

    /**
     * Get share form
     *
     * @return void
     */
    public static function getShareDialogForm($target_data, $tableKey = null)
    {
        $id = $target_data->id;

        list($target_type, $target_key) = static::getParentType($target_data);

        if ($target_data instanceof CustomView) {
            $url = admin_urls('view', $tableKey, $id, 'sendShares');
        } else {
            $url = admin_urls('dashboard', $id, 'sendShares');
        }

        // create form fields
        $form = new ModalForm();
        $form->modalAttribute('id', 'data_share_modal');
        $form->modalHeader(exmtrans('common.shared'));
        $form->action($url);

        $form->description(exmtrans("role_group.{$target_type}_share_description"))->setWidth(9, 2);

        // select target users
        $default = static::getUserOrgSelectDefault($target_key, $id, Permission::DATA_SHARE_EDIT);
        list($options, $ajax) = static::getUserOrgSelectOptions($target_data->custom_table, null, false, $default);
        $form->multipleSelect(Permission::DATA_SHARE_EDIT, exmtrans("role_group.role_type_option_value.{$target_type}_edit.label"))
            ->options($options)
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans("role_group.role_type_option_value.{$target_type}_edit.help"))
            ->setWidth(9, 2);

        $default = static::getUserOrgSelectDefault($target_key, $id, Permission::DATA_SHARE_VIEW);
        list($options, $ajax) = static::getUserOrgSelectOptions($target_data->custom_table, null, false, $default);
        $form->multipleSelect(Permission::DATA_SHARE_VIEW, exmtrans("role_group.role_type_option_value.{$target_type}_view.label"))
            ->options($options)
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans("role_group.role_type_option_value.{$target_type}_view.help"))
            ->setWidth(9, 2);

        return $form;
    }

    /**
     * get listbox options default
     *
     * @param [type] $custom_value
     * @return void
     */
    protected static function getUserOrgSelectDefault($target_key, $id, $permission)
    {
        // get values
        $items = static::query()
            ->where('parent_id', $id)
            ->where('parent_type', $target_key)
            ->where('authoritable_type', $permission)
            ->get();
        
        $defaults = $items->map(function ($item, $key) {
            return array_get($item, 'authoritable_user_org_type') . '_' . array_get($item, 'authoritable_target_id');
        })->toArray();

        return $defaults;
    }

    /**
     * Set share form
     *
     * @return void
     */
    public static function saveShareDialogForm($target_data)
    {
        $custom_table = $target_data->custom_table;

        $request = request();
        
        // check permission
        // if (!$custom_table->hasPermissionEditData($id) || !$custom_table->hasPermission(Permission::CUSTOM_VALUE_SHARE)) {
        //     return getAjaxResponse([
        //         'result'  => false,
        //         'toastr' => trans('admin.deny'),
        //     ]);
        // }

        list($target_type, $target_key) = static::getParentType($target_data);

        \DB::beginTransaction();

        try {
            // get user and org
            $items = [
                ['name' => Permission::DATA_SHARE_EDIT],
                ['name' => Permission::DATA_SHARE_VIEW],
            ];

            $shares = [];
            foreach ($items as $item) {
                $user_organizations = $request->get($item['name'], []);
                $user_organizations = collect($user_organizations)->filter()->map(function ($user_organization) use ($target_data, $target_type, $item) {
                    list($authoritable_user_org_type, $authoritable_target_id) = explode('_', $user_organization);
                    return [
                        'authoritable_type' => $item['name'],
                        'authoritable_user_org_type' => $authoritable_user_org_type,
                        'authoritable_target_id' => $authoritable_target_id,
                        'parent_type' => "_{$target_type}",
                        'parent_id' => $target_data->id,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                });
                    
                $shares = array_merge($shares, \Schema::insertDelete(SystemTableName::DATA_SHARE_AUTHORITABLE, $user_organizations, [
                    'dbValueFilter' => function (&$model) use ($target_data, $target_key, $item) {
                        $model->where('parent_type', $target_key)
                            ->where('parent_id', $target_data->id)
                            ->where('authoritable_type', $item['name']);
                    },
                    'dbDeleteFilter' => function (&$model, $dbValue) use ($target_data, $target_key, $item) {
                        $model->where('parent_type', $target_key)
                            ->where('parent_id', $target_data->id)
                            ->where('authoritable_type', $item['name'])
                            ->where('authoritable_user_org_type', array_get((array)$dbValue, 'authoritable_user_org_type'))
                            ->where('authoritable_target_id', array_get((array)$dbValue, 'authoritable_target_id'));
                    },
                    'matchFilter' => function ($dbValue, $value) {
                        return array_get((array)$dbValue, 'authoritable_user_org_type') == array_get($value, 'authoritable_user_org_type')
                            && array_get((array)$dbValue, 'authoritable_target_id') == array_get($value, 'authoritable_target_id');
                    },
                ]));
            }
            \DB::commit();

            System::clearCache();

            // // send notify
            // $shares = collect($shares)->map(function ($share) {
            //     return CustomTable::getEloquent($share['authoritable_user_org_type'])->getValueModel($share['authoritable_target_id']);
            // });
            
            // // loop for $notifies
            // foreach ($custom_value->custom_table->notifies as $notify) {
            //     $notify->notifyCreateUpdateUser($custom_value, NotifySavedType::SHARE, ['targetUserOrgs' => $shares]);
            // }

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
}
