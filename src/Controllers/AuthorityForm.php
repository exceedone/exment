<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Enums\AuthorityType;
use Encore\Admin\Form;

trait AuthorityForm
{
    /**
     * add authority to form.
     * @param mixed $form
     */
    protected function addAuthorityForm($form, $authority_type)
    {
        // if system doesn't use authority, return true
        if (!System::authority_available()) {
            return;
        }
        if($authority_type instanceof AuthorityType){
            $authority_type = $authority_type->toString();
        }

        // authority setting --------------------------------------------------
        $form->header(exmtrans('authority.header'))->hr();
        switch ($authority_type) {
            case AuthorityType::VALUE():
                $form->description(exmtrans(System::organization_available() ? 'authority.description_form.custom_value' : 'authority.description_form.custom_value_disableorg'));
                break;
                
            case AuthorityType::TABLE():
                $form->description(exmtrans(System::organization_available() ? 'authority.description_form.custom_table' : 'authority.description_form.custom_table_disableorg'));
            break;
            
            case AuthorityType::SYSTEM():
                $form->description(exmtrans(System::organization_available() ? 'authority.description_form.system' : 'authority.description_form.system_disableorg'));
                break;
            
            case AuthorityType::PLUGIN():
                $form->description(exmtrans(System::organization_available() ? 'authority.description_form.plugin' : 'authority.description_form.plugin_disableorg'));
                break;
            
        }

        // Add Authority --------------------------------------------------
        Authority::authorityLoop($authority_type, function ($authority, $related_type) use ($authority_type, $form) {
            switch ($related_type) {
                case Define::SYSTEM_TABLE_NAME_USER:
                $related_types = ['column_name' => 'user_name', 'view_name' => exmtrans('user.default_table_name'), 'suffix' => 'userable'];
                break;
            default:
                $related_types = ['column_name' => 'organization_name', 'view_name' => exmtrans('organization.default_table_name'), 'suffix' => 'organizationable'];
                break;
            }

            // declare pivotMultiSelect info
            $authority_name = getAuthorityName($authority, $related_type);
            $authority_view_name = "{$authority->authority_view_name}(".array_get($related_types, 'view_name').")";
            $pivots = ['authority_id' => $authority->id, 'related_type' => $related_type];
            
            if (isGetOptions($related_type)) {
                $form->pivotMultiSelect($authority_name, $authority_view_name)
                    ->options(function ($options) use ($authority_type, $related_type, $related_types) {
                        if(AuthorityType::VALUE()->match($authority_type)){
                            return getOptions($related_type, $options, $this->getCustomTable());
                        }
                        return getOptions($related_type, $options, null, true);
                    })
                    ->pivot($pivots)
                    ;
            } else {
                $form->pivotMultiSelect($authority_name, $authority_view_name)
                ->options(function ($options) use ($authority_type, $related_type, $related_types) {
                    if(AuthorityType::VALUE()->match($authority_type)){
                        return getOptions($related_type, $options, $this->getCustomTable());
                    }
                    return getOptions($related_type, $options, null, true);
                })
                ->ajax(getOptionAjaxUrl($related_type))
                ->pivot($pivots)
                ;
            }
        });
    }
}
