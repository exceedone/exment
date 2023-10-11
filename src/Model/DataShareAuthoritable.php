<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ShareTargetType;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Carbon\Carbon;

/**
 * @phpstan-consistent-constructor
 */
class DataShareAuthoritable extends ModelBase
{
    use Traits\DataShareTrait;

    /**
     * Set Data Share Authoritable after custom value save
     */
    public static function setDataAuthoritable($target_data)
    {
        $target_type = static::getTargetType($target_data);

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        self::firstOrCreate([
            'parent_id' => $target_data->id,
            'parent_type' => $target_type->toString(),
            'authoritable_type' => Permission::DATA_SHARE_EDIT,
            'authoritable_user_org_type' => SystemTableName::USER,
            'authoritable_target_id' => $user->getUserId(),
        ]);
    }

    /**
     * Get share target type
     *
     * @param $target_data
     * @return ShareTargetType
     */
    public static function getTargetType($target_data)
    {
        if ($target_data instanceof CustomView) {
            return ShareTargetType::VIEW();
        } else {
            return ShareTargetType::DASHBOARD();
        }
    }

    /**
     * Delete Data Share Authoritable after target data save
     *
     * @return void
     */
    public static function deleteDataAuthoritable($target_data)
    {
        $target_type = static::getTargetType($target_data);
        static::query()
            ->where('parent_id', $target_data->id)
            ->where('parent_type', $target_type->toString())
            ->delete();
    }

    /**
     * Get share form
     *
     * @return ModalForm
     */
    public static function getShareDialogForm($target_data, $tableKey = null)
    {
        $id = $target_data->id;

        $target_type = static::getTargetType($target_data);

        $target_name = exmtrans('role_group.share_target_options.'.$target_type->lowerkey());

        if (isset($tableKey)) {
            $url = admin_urls($target_type->lowerkey(), $tableKey, $id, 'sendShares');
        } else {
            $url = admin_urls($target_type->lowerkey(), $id, 'sendShares');
        }

        // create form fields
        $form = new ModalForm();
        $form->modalAttribute('id', 'data_share_modal');
        $form->modalHeader(exmtrans('common.shared'));
        $form->action($url);

        $form->descriptionHtml(exmtrans("role_group.data_share_description", $target_name))->setWidth(9, 2);

        // select target users
        $default = static::getUserOrgSelectDefault($target_type->toString(), $id, Permission::DATA_SHARE_EDIT);
        list($options, $ajax) = static::getUserOrgSelectOptions($target_data->custom_table, null, false, $default);

        // for validation options
        $validationOptions = null;

        $form->multipleSelect(Permission::DATA_SHARE_EDIT, exmtrans("role_group.role_type_option_value.data_share_edit.label"))
            ->options($options)
            ->validationOptions(function ($value) use (&$validationOptions, $target_data) {
                if (!is_null($validationOptions)) {
                    return $validationOptions;
                }
                list($validationOptions, $ajax) = static::getUserOrgSelectOptions($target_data->custom_table, null, false, null, true);
                return $validationOptions;
            })
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans("role_group.role_type_option_value.data_share_edit.help", $target_name))
            ->setWidth(9, 2);

        $default = static::getUserOrgSelectDefault($target_type->toString(), $id, Permission::DATA_SHARE_VIEW);
        list($options, $ajax) = static::getUserOrgSelectOptions($target_data->custom_table, null, false, $default);

        $form->multipleSelect(Permission::DATA_SHARE_VIEW, exmtrans("role_group.role_type_option_value.data_share_view.label"))
            ->options($options)
            ->validationOptions(function ($value) use (&$validationOptions, $target_data) {
                if (!is_null($validationOptions)) {
                    return $validationOptions;
                }
                list($validationOptions, $ajax) = static::getUserOrgSelectOptions($target_data->custom_table, null, false, null, true);
                return $validationOptions;
            })
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans("role_group.role_type_option_value.data_share_view.help", $target_name))
            ->setWidth(9, 2);

        return $form;
    }

    /**
     * get listbox options default
     *
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
     * @return \Symfony\Component\HttpFoundation\Response
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

        $target_type = static::getTargetType($target_data);
        $target_key = $target_type->toString();

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
                $user_organizations = collect($user_organizations)->filter()->map(function ($user_organization) use ($target_data, $target_key, $item) {
                    list($authoritable_user_org_type, $authoritable_target_id) = explode('_', $user_organization);
                    return [
                        'authoritable_type' => $item['name'],
                        'authoritable_user_org_type' => $authoritable_user_org_type,
                        'authoritable_target_id' => $authoritable_target_id,
                        'parent_type' => $target_key,
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

            return getAjaxResponse([
                'result'  => true,
                'toastr' => trans('admin.save_succeeded'),
            ]);
        } catch (\Exception $exception) {
            //TODO:error handling
            \DB::rollback();
            throw $exception;
        }
    }
}
