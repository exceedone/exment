<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;

trait DataShareTrait
{

    /**
     * get listbox options contains user and org
     *
     * @param CustomTable $custom_table
     * @param ?array $permission
     * @param bool $ignoreLoginUser if true, ignore login user id from options
     * @param ?string $default default setting
     * @param bool $all if true, get value for all
     * @return array $options : Select Options, $ajax : ajax url
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
                'selected_value' => str_replace("{$key}_", "", $default),
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
}
