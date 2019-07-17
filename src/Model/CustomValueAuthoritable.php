<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Widgets\ModalInnerForm;

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
        
        // // get target users
        // $users = $this->notify->getNotifyTargetUsers($this->custom_value);

        // // if only one data, get form for detail
        // if (count($users) == 1) {
        //     return $this->getSendForm($users);
        // }
        
        // create form fields
        $tableKey = $custom_value->custom_table->table_name;
        $id = $custom_value->id;

        $form = new ModalInnerForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_share_modal');
        $form->modalHeader(exmtrans('common.share'));
        $form->action(admin_urls('data', $tableKey, $id, 'sendTargetUsers'));

        // $options = [];
        // foreach ($users as $user) {
        //     $options[$user->notifyKey()] = $user->getLabel();
        // }

        $options = static::getUserOrgSelectOptions($custom_value->custom_table);
        // // select target users
        $form->listbox('custom_value_edit', exmtrans('role_group.role_type_option_value.custom_value_edit.label'))
            ->options($options)
            ->settings(['selectorMinimalHeight' => 80, 'nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->help(exmtrans('role_group.role_type_option_value.custom_value_edit.help') . exmtrans('common.bootstrap_duallistbox_container.help'))
            ->setWidth(9, 2);

        $form->listbox('custom_value_view', exmtrans('role_group.role_type_option_value.custom_value_view.label'))
            ->options($options)
            ->settings(['selectorMinimalHeight' => 80, 'nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->help(exmtrans('role_group.role_type_option_value.custom_value_view.help') . exmtrans('common.bootstrap_duallistbox_container.help'))
            ->setWidth(9, 2);
        // $form->hidden('mail_template_id')->default($this->targetid);

        return $form;
    }

    /**
     * get listbox options contains user and org
     *
     * @param [type] $custom_table
     * @return void
     */
    protected static function getUserOrgSelectOptions($custom_table){
        // get options
        $users = CustomTable::getEloquent(SystemTableName::USER)->getOptions(null, $custom_table);
        $organizations = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getOptions(null, $custom_table);
        
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
}
