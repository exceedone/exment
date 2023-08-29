<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\PartialCrudItems\ProviderBase;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;

/**
 * Organization item for User
 * @phpstan-consistent-constructor
 */
class UserBelongOrganizationItem extends ProviderBase
{
    protected $custom_table;
    protected $options;

    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
        $this->setOptions();

        if (!System::organization_available() || count($this->options) == 0) {
            return;
        }

        if (!CustomTable::getEloquent(SystemTableName::ORGANIZATION)->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return;
        }

        $defaults = [];
        if (isset($id)) {
            $custom_value = $this->custom_table->getValueModel($id);
            $defaults = $custom_value ? $custom_value->belong_organizations->pluck('id')->toArray() : [];
        }

        $form->listbox('belong_organizations', exmtrans("user.belong_organizations"))
            ->default($defaults)
            ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->help(exmtrans('common.bootstrap_duallistbox_container.help'))
            ->options($this->options);
        $form->ignore('belong_organizations');
    }

    /**
     * saved event
     */
    public function saved($form, $id)
    {
        $this->setOptions();

        if (!System::organization_available() || count($this->options) == 0) {
            return;
        }

        // get request value
        $request = request();
        if (!$request->has('belong_organizations')) {
            return;
        }

        $belong_organizations = collect($request->get('belong_organizations', []))->filter()->map(function ($belong_organization) use ($id) {
            return [
                'parent_id' => $belong_organization,
                'child_id' => $id,
            ];
        });

        // get relation name
        $relationName = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);

        \Schema::insertDelete($relationName, $belong_organizations, [
            'dbValueFilter' => function (&$model) use ($id) {
                $model->where('child_id', $id);
            },
            'dbDeleteFilter' => function (&$model, $dbValue) use ($id) {
                $model->where('child_id', $id)
                    ->where('parent_id', array_get((array)$dbValue, 'parent_id'));
            },
            'matchFilter' => function ($dbValue, $value) {
                return array_get((array)$dbValue, 'parent_id') == array_get($value, 'parent_id');
            },
        ]);
    }

    protected function setOptions()
    {
        $custom_table = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
        if (config('exment.sort_org_by_default_view', false)) {
            $custom_view = CustomView::getDefault($custom_table);
        } else {
            $custom_view = null;
        }
        $this->options = $custom_table->getSelectOptions([
            'notAjax' => true,
            'target_view' => $custom_view
        ]);
    }
}
