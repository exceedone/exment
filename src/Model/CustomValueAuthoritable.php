<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\SharePermission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ShareTrigger;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * @phpstan-consistent-constructor
 * @property mixed $authoritable_user_org_type
 * @property mixed $authoritable_target_id
 */
class CustomValueAuthoritable extends ModelBase
{
    use Traits\DataShareTrait;

    public function getAuthoritableUserOrgAttribute()
    {
        return CustomTable::getEloquent($this->authoritable_user_org_type)->getValueModel($this->authoritable_target_id);
    }

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
            'authoritable_target_id' => $user->getUserId(),
        ]);


        /////// share organization
        $save_autoshare = System::custom_value_save_autoshare() ?? CustomValueAutoShare::USER_ONLY;
        if ($save_autoshare == CustomValueAutoShare::USER_ONLY) {
            return;
        }

        // get organizations. OK only_join users
        getModelName(SystemTableName::ORGANIZATION);
        $belong_organizations = $user->base_user->belong_organizations;

        foreach ($belong_organizations as $belong_organization) {
            // check permission as organization.
            if (!static::hasPermissionAsOrganization($custom_table, $belong_organization)) {
                continue;
            }

            self::firstOrCreate([
                'parent_id' => $custom_value->id,
                'parent_type' => $table_name,
                'authoritable_type' => $save_autoshare == CustomValueAutoShare::USER_ORGANIZATION ? Permission::CUSTOM_VALUE_EDIT : Permission::CUSTOM_VALUE_VIEW,
                'authoritable_user_org_type' => SystemTableName::ORGANIZATION,
                'authoritable_target_id' => $belong_organization->id,
            ]);
        }
    }

    /**
     * Set Custom Value Authoritable after custom value save
     *
     * @param CustomValue $custom_value
     * @param mixed $share_trigger_type
     * @return void
     */
    public static function setValueAuthoritableEx(CustomValue $custom_value, $share_trigger_type)
    {
        $custom_table = $custom_value->custom_table;
        if (is_nullorempty($custom_table->share_settings)) {
            return;
        }

        // $sync is true, delete items if not has array.
        // Only execute $share_trigger_type is EDIT and contains array's $share_trigger_type is edit
        $sync = false;

        // create share target user or orgs
        $total_user_organizations = [];

        // get before saved user_organizations
        $beforesaved_user_organizations = static::getListsOnCustomValue($custom_value);

        // set values
        foreach ($custom_table->share_settings as $share_setting) {
            foreach (stringToArray(array_get($share_setting, 'share_trigger_type')) as $t) {
                $share_permission = array_get($share_setting, 'share_permission');
                $share_column = array_get($share_setting, 'share_column');
                $target_ids = array_get($custom_value->value, $share_column->column_name);
                $user_organizations =
                    collect(stringToArray($target_ids))->map(function ($target_id) use ($share_column) {
                        return [
                            'related_id' => $target_id,
                            'related_type' => $share_column->column_type,
                        ];
                    })->toArray();

                // Append total_user_organizations. Even if not match share_trigger_type, append array.
                $total_user_organizations = array_merge($user_organizations, $total_user_organizations);

                // if setting "share_setting_sync" is true, and this execute triggers edit, sync is true.
                if (boolval($custom_table->getOption('share_setting_sync')) && $share_trigger_type == ShareTrigger::UPDATE && $t == ShareTrigger::UPDATE) {
                    $sync = true;
                }

                // not match trigger type, continue
                if ($share_trigger_type != $t) {
                    continue;
                }

                // set Custom Value Authoritable
                self::setAuthoritableByUserOrgArray($custom_value, $user_organizations, $share_permission == SharePermission::EDIT);
            }
        }

        // if sync, not contains
        if ($sync) {
            $belong_orgs = \Exment::user()->belong_organizations;
            $delete_user_organizations = $beforesaved_user_organizations->filter(function ($beforesaved_user_organization) use ($belong_orgs, $total_user_organizations) {
                // skip self user
                if (array_get($beforesaved_user_organization, 'authoritable_target_id') == \Exment::getUserId()
                    && array_get($beforesaved_user_organization, 'authoritable_user_org_type') == SystemTableName::USER) {
                    return false;
                }

                // skip self organizaions
                if ($belong_orgs->contains(function ($belong_org) use ($beforesaved_user_organization) {
                    return array_get($beforesaved_user_organization, 'authoritable_target_id') == $belong_org->id
                    && array_get($beforesaved_user_organization, 'authoritable_user_org_type') == SystemTableName::ORGANIZATION;
                })) {
                    return false;
                }

                // get not contains "$total_user_organizations" (This method's saved user)
                return !collect($total_user_organizations)->contains(function ($total_user_organization) use ($beforesaved_user_organization) {
                    return array_get($beforesaved_user_organization, 'authoritable_target_id') == array_get($total_user_organization, 'related_id')
                        && array_get($beforesaved_user_organization, 'authoritable_user_org_type') == array_get($total_user_organization, 'related_type');
                });
            })->map(function ($delete_user_organization) {
                return [array_get($delete_user_organization, 'authoritable_target_id'), array_get($delete_user_organization, 'authoritable_user_org_type')];
            });

            if (count($delete_user_organizations) > 0) {
                static::where([
                    'parent_id' => $custom_value->id,
                    'parent_type' => $custom_table->table_name,
                ])
                ->whereInMultiple(['authoritable_target_id', 'authoritable_user_org_type'], $delete_user_organizations->toArray())
                ->delete();
            }
        }
    }

    /**
     * Set Authoritable By User and Org Array
     *
     * @param CustomValue $custom_value
     * @param array $arrays saved target user or organization
     * @param bool $is_edit is true, as edit permission
     * @param bool $sync is true, delete items if not has array
     */
    public static function setAuthoritableByUserOrgArray($custom_value, $arrays, $is_edit = false, $sync = false)
    {
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;
        if (in_array($table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY())) {
            return;
        }

        foreach ($arrays as $array) {
            if ($array instanceof CustomValue) {
                $related_id = array_get($array, 'id');
                $related_type = $array->custom_table->table_name;
            } else {
                $related_id = array_get($array, 'related_id');
                $related_type = array_get($array, 'related_type');
            }

            if (\is_nullorempty($related_id) || \is_nullorempty($related_type)) {
                continue;
            }

            // if not has permission for accessible, continue;
            if (!static::hasPermssionAccessible($custom_table, $related_id, $related_type)) {
                continue;
            }

            $model = static::firstOrNew([
                'parent_id' => $custom_value->id,
                'parent_type' => $table_name,
                'authoritable_type' => $is_edit ? Permission::CUSTOM_VALUE_EDIT : Permission::CUSTOM_VALUE_VIEW,
                'authoritable_user_org_type' => $related_type,
                'authoritable_target_id' => $related_id,
            ]);

            if (!isset($model->id)) {
                $model->save();
                static::notifyUser($custom_value, collect([$model->authoritable_user_org]));
            }
        }
    }

    /**
     * Check $related_id has permission for access $custom_table
     *
     * @param CustomTable $custom_table
     * @param string|int $related_id target user or org id
     * @param string $related_type
     * @return boolean
     */
    protected static function hasPermssionAccessible($custom_table, $related_id, $related_type)
    {
        $accessibleIds = ($related_type == ColumnType::ORGANIZATION ? $custom_table->getAccessibleOrganizationIds() : $custom_table->getAccessibleUserIds());

        return $accessibleIds->contains($related_id);
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
     * @return ModalForm
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

        $form->descriptionHtml(exmtrans('role_group.share_description'))->setWidth(9, 2);

        // select target users
        $default = static::getUserOrgSelectDefault($custom_value, Permission::CUSTOM_VALUE_EDIT);
        list($options, $ajax) = static::getUserOrgSelectOptions($custom_value->custom_table, null, false, $default);

        // for validation options
        $validationOptions = null;

        $form->multipleSelect('custom_value_edit', exmtrans('role_group.role_type_option_value.custom_value_edit.label'))
            ->options($options)
            ->validationOptions(function ($value) use (&$validationOptions, $custom_value) {
                if (!is_null($validationOptions)) {
                    return $validationOptions;
                }
                list($validationOptions, $ajax) = static::getUserOrgSelectOptions($custom_value->custom_table, null, false, null, true);
                return $validationOptions;
            })
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans('role_group.role_type_option_value.custom_value_edit.help'))
            ->setWidth(9, 2);

        $default = static::getUserOrgSelectDefault($custom_value, Permission::CUSTOM_VALUE_VIEW);
        list($options, $ajax) = static::getUserOrgSelectOptions($custom_value->custom_table, null, false, $default);
        $form->multipleSelect('custom_value_view', exmtrans('role_group.role_type_option_value.custom_value_view.label'))
            ->options($options)
            ->validationOptions(function ($value) use (&$validationOptions, $custom_value) {
                if (!is_null($validationOptions)) {
                    return $validationOptions;
                }
                list($validationOptions, $ajax) = static::getUserOrgSelectOptions($custom_value->custom_table, null, false, null, true);
                return $validationOptions;
            })
            ->ajax($ajax)
            ->default($default)
            ->help(exmtrans('role_group.role_type_option_value.custom_value_view.help'))
            ->setWidth(9, 2);

        return $form;
    }

    /**
     * Set share form
     *
     * @param $custom_value
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
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

        // validation
        $form = static::getShareDialogForm($custom_value);
        if (($response = $form->validateRedirect($request)) instanceof \Illuminate\Http\RedirectResponse) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => trans('admin.validation.not_in_option'),
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
                    'dbDeleteFilter' => function (&$model, $dbValue) use ($item, $custom_value) {
                        $model->where('parent_type', $custom_value->custom_table->table_name)
                            ->where('parent_id', $custom_value->id)
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

            // send notify
            $shares = collect($shares)->map(function ($share) {
                return CustomTable::getEloquent($share['authoritable_user_org_type'])->getValueModel($share['authoritable_target_id']);
            });

            static::notifyUser($custom_value, $shares);

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


    /**
     * Get listbox options contains user and org
     *
     * @param CustomTable|null $custom_table Target display table
     * @param array|null $permission
     * @param boolean $ignoreLoginUser Whether ignore login user
     * @param array|null $default
     * @param boolean $all if true, get all items. For checking value
     * @return array
     */
    public static function getUserOrgSelectOptions($custom_table, $permission = null, $ignoreLoginUser = false, $default = null, $all = false)
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
                'selected_value' => str_replace_ex("{$key}_", "", $default),
                'permission' => $permission,
                'notAjax' => $all,
            ]);

            if ($ignoreLoginUser && $key == SystemTableName::USER) {
                $user_id = \Exment::getUserId();
                $optionItem = $optionItem->filter(function ($user, $id) use ($user_id) {
                    return $id != $user_id;
                });
            }

            $options = $options->merge(collect($optionItem)->mapWithKeys(function ($i, $k) use ($key) {
                return [$key . '_' . $k => $i];
            }));

            // add ajax
            if (isset($ajaxItem)) {
                $ajax = admin_urls_query('webapi/user_organization/select', ['display_table_id' => ($custom_table ? $custom_table->id : null)]);
            }
        }

        return [$options->toArray(), $ajax];
    }

    /**
     * get listbox options default
     *
     * @param CustomValue $custom_value
     * @param string $permission
     * @return array user and organization default options
     */
    protected static function getUserOrgSelectDefault(CustomValue $custom_value, $permission)
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
    protected static function hasPermissionAsOrganization($custom_table, $organization)
    {
        if (boolval($custom_table->getOption('all_user_editable_flg'))) {
            return true;
        }

        // check role group as org. if not has, conitnue
        if (!\is_nullorempty(RoleGroup::getHasPermissionRoleGroup(null, $organization->id, true))) {
            return true;
        }

        return false;
    }


    public static function getListsOnCustomValue(CustomValue $custom_value)
    {
        return static::where(['parent_id' => $custom_value->id, 'parent_type' => $custom_value->custom_table->table_name])->get();
    }

    /**
     * Notify target user.
     *
     * @param CustomValue $custom_value shared target custom_value.
     * @param Collection $shareTargets user and organization notify targets collection
     * @return void
     */
    protected static function notifyUser($custom_value, $shareTargets)
    {
        foreach ($custom_value->custom_table->notifies as $notify) {
            $notify->notifyCreateUpdateUser($custom_value, NotifySavedType::SHARE, ['targetUserOrgs' => $shareTargets]);
        }
    }
}
