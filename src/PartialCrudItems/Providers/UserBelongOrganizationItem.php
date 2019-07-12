<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;

/**
 * Organization item for User
 */
class UserBelongOrganizationItem
{
    protected $custom_table;
    protected $options;
    
    public function __construct($custom_table)
    {
        $this->custom_table = $custom_table;
        $this->options = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getOptions();
    }

    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
        if(!System::organization_available() || count($this->options) == 0){
            return;
        }

        $defaults = [];
        if(isset($id)){
            $defaults = $this->custom_table->getValueModel($id)->belong_organizations->pluck('id')->toArray();
        }

        $form->listbox('belong_organizations', exmtrans("user.belong_organizations"))
            ->default($defaults)
            ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->help(exmtrans('common.bootstrap_duallistbox_container.help'))
            ->options($this->options);
        $form->ignore('belong_organizations');
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
        if(!System::organization_available() || count($this->options) == 0){
            return;
        }

        // get request value
        $request = request();
        if(!$request->has('belong_organizations')){
            return;
        }

        $belong_organizations = collect($request->get('belong_organizations', []))->filter()->map(function($belong_organization) use($id){
            return [
                'parent_id' => $belong_organization,
                'child_id' => $id,
            ];
        });

        // get relation name
        $relationName = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        
        \Schema::insertDelete($relationName, $belong_organizations, [
            'dbValueFilter' => function(&$model) use($id){
                $model->where('child_id', $id);
            },
            'dbDeleteFilter' => function(&$model) use($id){
                $model->where('child_id', $id);
            },
            'matchFilter' => function($dbValue, $value){
                return array_get((array)$dbValue, 'parent_id') == array_get($value, 'parent_id');
            },
        ]);
    }
    
    public static function getItem(...$args)
    {
        list($custom_table) = $args + [null];
        return new self($custom_table);
    }
}
