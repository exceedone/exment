<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemRoleType;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Tools;
use Encore\Admin\Layout\Content;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Auth\Permission as Checker;

class RoleGroupController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("role_group.header"), exmtrans("role_group.header"), exmtrans("role_group.description"), 'fa-user-secret');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new RoleGroup);
        $grid->column('role_group_name', exmtrans('role_group.role_group_name'));
        $grid->column('role_group_view_name', exmtrans('role_group.role_group_view_name'));
        $grid->column('role_group_users', exmtrans('role_group.users_count'))->displayEscape(function ($counts) {
            return is_null($counts) ? null : count($counts);
        });

        if (System::organization_available()) {
            $grid->column('role_group_organizations', exmtrans('role_group.organizations_count'))->displayEscape(function ($counts) {
                return is_null($counts) ? null : count($counts);
            });
        }
        
        // check has ROLE_GROUP_ALL
        $hasCreatePermission = \Exment::user()->hasPermission(Permission::ROLE_GROUP_ALL);
        if (!$hasCreatePermission) {
            $grid->disableCreateButton();
        }
        
        $grid->tools(function (Grid\Tools $tools) use ($hasCreatePermission) {
            if (!$hasCreatePermission) {
                $tools->disableBatchActions();
            }
            $tools->prepend(new Tools\SystemChangePageMenu());
        });

        $grid->disableExport();
        $grid->actions(function ($actions) use ($hasCreatePermission) {
            $actions->disableView();
            $actions->disableEdit();
            if (!$hasCreatePermission) {
                $actions->disableDelete();
            }

            $linker = (new Linker)
                ->url(admin_urls('role_group', $actions->row->id, 'edit?form_type=2'))
                ->icon('fa-users')
                ->tooltip(exmtrans('role_group.user_organization_setting'));
            $actions->prepend($linker);

            $linker = (new Linker)
                ->url(admin_urls('role_group', $actions->row->id, 'edit'))
                ->icon('fa-user-secret')
                ->linkattributes(['class' => 'rowclick'])
                ->tooltip(exmtrans('role_group.permission_setting'));
            $actions->prepend($linker);
        });
        return $grid;
    }

    /**
     * Create interface.
     *
     * @param Content $content
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        $isRolePermissionPage = $request->get('form_type') != 2;
        $form = $isRolePermissionPage ? $this->form() : $this->formUserOrganization();
        $box = new Box(trans('admin.create'), $form);
        $this->appendTools($box, null, $isRolePermissionPage);
        return $this->AdminContent($content)->body($box);
    }

    /**
     * Edit interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function edit(Request $request, Content $content, $id)
    {
        $isRolePermissionPage = $request->get('form_type') != 2;
        $form = $isRolePermissionPage ? $this->form($id) : $this->formUserOrganization($id);
        $box = new Box(trans('admin.edit'), $form->edit($id));
        $this->appendTools($box, $id, $isRolePermissionPage);
        return $this->AdminContent($content)->body($box);
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $model = isset($id) ? RoleGroup::with(['role_group_permissions'])->findOrFail($id) : new RoleGroup;
        $form = new Form($model->toArray());
        $form->disableReset();
        $form->action(admin_urls('role_group', $id));
        $form->method(isset($id) ? 'put' : 'post');

        $form->progressTracker()->options($this->getProgressInfo(true, $id));

        $enable = $this->hasPermission_Permission();

        if (!isset($id)) {
            $form->text('role_group_name', exmtrans('role_group.role_group_name'))
            ->required()
            ->disable(!$enable)
            ->rules("max:30|unique:".RoleGroup::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
            ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
            ;
        } else {
            $form->display('role_group_name', exmtrans('role_group.role_group_name'));
        }

        $form->text('role_group_view_name', exmtrans('role_group.role_group_view_name'))
            ->required()
            ->disable(!$enable)
            ->rules("max:40");
        
        $form->textarea('description', exmtrans("custom_table.field_description"))
            ->disable(!$enable)
            ->rows(3);
            
        $form->exmheader(exmtrans('role_group.role_type_options.' . RoleGroupType::SYSTEM()->lowerKey()) . exmtrans('role_group.permission_setting'))->hr();

        $form->descriptionHtml(exmtrans('role_group.description_system_admin'));


        // System --------------------------------------------------------
        $values = $model->role_group_permissions->first(function ($role_group_permission) {
            return $role_group_permission->role_group_permission_type == RoleType::SYSTEM && $role_group_permission->role_group_target_id == SystemRoleType::SYSTEM;
        })->permissions ?? [];

        $items = [[
            'label' => exmtrans('role_group.role_group_system.system'),
            'values' => $values,
            'name' => "system_permission[system][permissions]",
        ]];
        
        $form->checkboxTable("system_permission_permissions", "")
            ->options(RoleGroupType::SYSTEM()->getRoleGroupOptions())
            ->disable(!$enable)
            ->checkWidth(120)
            ->headerHelp(RoleGroupType::SYSTEM()->getRoleGroupHelps())
            ->items($items);

        $form->hidden("system_permission[system][id]")
            ->default(SystemRoleType::SYSTEM);

        
        // Role --------------------------------------------------------
        $values = $model->role_group_permissions->first(function ($role_group_permission) {
            return $role_group_permission->role_group_permission_type == RoleType::SYSTEM && $role_group_permission->role_group_target_id == SystemRoleType::ROLE_GROUP;
        })->permissions ?? [];

        $items = [[
            'label' => exmtrans('role_group.role_group_system.role_group'),
            'values' => $values,
            'name' => "system_permission[role_groups][permissions]",
        ]];
        
        $form->checkboxTable("role_permission_permissions", "")
            ->options(RoleGroupType::ROLE_GROUP()->getRoleGroupOptions())
            ->disable(!$enable)
            ->checkWidth(120)
            ->headerHelp(RoleGroupType::ROLE_GROUP()->getRoleGroupHelps())
            ->items($items);

        $form->hidden("system_permission[role_groups][id]")
            ->default(SystemRoleType::ROLE_GROUP);



        // Plugin --------------------------------------------------------
        $plugins = Plugin::allRecords();
        if (!is_nullorempty($plugins)) {
            $form->exmheader(exmtrans('role_group.role_type_options.plugin') . exmtrans('role_group.permission_setting'))->hr();

            $items = [];
            foreach ($plugins as $plugin) {
                $values = $model->role_group_permissions->first(function ($role_group_permission) use ($plugin) {
                    return $role_group_permission->role_group_permission_type == RoleType::PLUGIN && $role_group_permission->role_group_target_id == $plugin->id;
                })->permissions ?? [];

                // check disabled
                $enabledPluginAccess = collect($plugin->plugin_types)->contains(function ($plugin_type) {
                    return in_array($plugin_type, PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE());
                });
                $items[] = [
                    'label' => $plugin->plugin_view_name,
                    'values' => $values,
                    'name' => "plugin_permission[$plugin->plugin_name][permissions]",
                    'disables' => !$enabledPluginAccess ? [PERMISSION::PLUGIN_ACCESS] : [],
                ];

                $form->hidden("plugin_permission[$plugin->plugin_name][id]")
                    ->default($plugin->id);
            }
            
            $form->checkboxTable("plugin_permission_permissions", "")
                ->options(RoleGroupType::PLUGIN()->getRoleGroupOptions())
                ->disable(!$enable)
                ->checkWidth(150)
                ->headerHelp(RoleGroupType::PLUGIN()->getRoleGroupHelps())
                ->items($items);
        }



        // Master --------------------------------------------------------
        $form->exmheader(exmtrans('role_group.role_type_options.' . RoleGroupType::MASTER()->lowerKey()) . exmtrans('role_group.permission_setting'))->hr();

        $items = [];
        foreach (CustomTable::filterList(null, ['checkPermission' => false, 'filter' => function ($model) {
            $model->whereIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER());
            return $model;
        }]) as $table) {
            $values = $model->role_group_permissions->first(function ($role_group_permission) use ($table) {
                return $role_group_permission->role_group_permission_type == RoleType::TABLE && $role_group_permission->role_group_target_id == $table->id;
            })->permissions ?? [];

            $items[] = [
                'label' => $table->table_view_name,
                'values' => $values,
                'name' => "master_permission[$table->table_name][permissions]",
            ];

            $form->hidden("master_permission[$table->table_name][id]")
                ->default($table->id);
        }
        
        $form->checkboxTable("master_permission_permissions", "")
            ->options(RoleGroupType::MASTER()->getRoleGroupOptions())
            ->disable(!$enable)
            ->checkWidth(100)
            ->headerHelp(RoleGroupType::MASTER()->getRoleGroupHelps())
            ->items($items);




        // Table --------------------------------------------------------
        $form->exmheader(exmtrans('role_group.role_type_options.' . RoleGroupType::TABLE()->lowerKey()) . exmtrans('role_group.permission_setting'))->hr();

        $items = [];
        foreach (CustomTable::filterList(null, ['checkPermission' => false, 'filter' => function ($model) {
            $model->whereNotIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER());
            return $model;
        }]) as $table) {
            $values = $model->role_group_permissions->first(function ($role_group_permission) use ($table) {
                return $role_group_permission->role_group_permission_type == RoleType::TABLE && $role_group_permission->role_group_target_id == $table->id;
            })->permissions ?? [];

            $items[] = [
                'label' => $table->table_view_name,
                'values' => $values,
                'name' => "table_permission[$table->table_name][permissions]",
            ];

            $form->hidden("table_permission[$table->table_name][id]")
                ->default($table->id);
        }
        $form->checkboxTable("table_permission_permissions", "")
            ->options(RoleGroupType::TABLE()->getRoleGroupOptions())
            ->disable(!$enable)
            ->checkWidth(100)
            ->headerHelp(RoleGroupType::TABLE()->getRoleGroupHelps())
            ->items($items);

        if (!$enable) {
            $form->disableSubmit();
        }

        return $form;
    }

    /**
     * Make a form builder for User Organization.
     *
     * @return Form
     */
    protected function formUserOrganization($id = null)
    {
        if (!$this->hasPermission_UserOrganization()) {
            Checker::error();
            return false;
        }

        $model = RoleGroup::with(['role_group_users', 'role_group_organizations'])->findOrFail($id);
        $form = new Form($model->toArray());
        $form->disableReset();
        $form->action(admin_urls('role_group', $id . '?form_type=2'));
        $form->method('put');
        
        $form->progressTracker()->options($this->getProgressInfo(false, $id));

        $form->display('role_group_name', exmtrans('role_group.role_group_name'));
        $form->display('role_group_view_name', exmtrans('role_group.role_group_view_name'));

        // get options
        $default = $model->role_group_user_organizations->map(function ($item) {
            return array_get($item, 'role_group_user_org_type') . '_' . array_get($item, 'role_group_target_id');
        })->toArray();

        list($options, $ajax) = CustomValueAuthoritable::getUserOrgSelectOptions(null, null, false, $default);
        
        if (!is_nullorempty($ajax)) {
            $form->multipleSelect('role_group_item', exmtrans('role_group.user_organization_setting'))
                ->options($options)
                ->ajax($ajax)
                ->default($default);
        } else {
            $form->listbox('role_group_item', exmtrans('role_group.user_organization_setting'))
                ->options($options)
                ->default($default)
                ->help(exmtrans('common.bootstrap_duallistbox_container.help'))
                ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')]);
            ;
        }

        return $form;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        return request()->get('form_type') == 2 ? $this->saveUserOrganization($id) : $this->saveRolePermission($id);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return mixed
     */
    public function store()
    {
        return $this->saveRolePermission();
    }

    protected function saveRolePermission($id = null)
    {
        if (!$this->hasPermission_Permission()) {
            Checker::error();
            return false;
        }

        $request = request();

        // validation
        $rules = [
            'role_group_name' => [
                isset($id) ? 'nullable' : 'required',
                Rule::unique('role_groups')->ignore($id),
                'max:64',
                'regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/'
            ],
            'role_group_view_name' => 'required|max:64',
        ];

        $validation = \Validator::make($request->all(), $rules);

        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }

        \DB::beginTransaction();

        try {
            $role_group = isset($id) ? RoleGroup::findOrFail($id) : new RoleGroup;
            if (!isset($id)) {
                $role_group->role_group_name = $request->get('role_group_name');
            }
            $role_group->role_group_view_name = $request->get('role_group_view_name');
            $role_group->description = $request->get('description');
            $role_group->save();

            $items = [
                ['name' => 'system_permission', 'role_group_permission_type' => RoleType::SYSTEM],
                ['name' => 'plugin_permission', 'role_group_permission_type' => RoleType::PLUGIN],
                ['name' => 'master_permission', 'role_group_permission_type' => RoleType::TABLE],
                ['name' => 'table_permission', 'role_group_permission_type' => RoleType::TABLE],
            ];

            $relations = [];
            foreach ($items as $item) {
                $requestItems = $request->get($item['name']);
                if (is_nullorempty($requestItems)) {
                    continue;
                }

                foreach ($requestItems as $requestItem) {
                    $relation = [
                        'role_group_id' => $role_group->id,
                        'role_group_permission_type' => $item['role_group_permission_type'],
                        'role_group_target_id' => array_get($requestItem, 'id'),
                    ];
    
                    $role_group_permission = RoleGroupPermission::firstOrNew($relation);
                    $role_group_permission->permissions = array_filter(array_get($requestItem, 'permissions', []));
                    $role_group_permission->save();
                }
            }

            \DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('role_group'));
        } catch (\Exception $exception) {
            //TODO:error handling
            \DB::rollback();
            throw $exception;
        }
    }

    protected function saveUserOrganization($id = null)
    {
        if (!$this->hasPermission_UserOrganization()) {
            Checker::error();
            return false;
        }

        $request = request();

        \DB::beginTransaction();

        try {
                
            // get user and org
            $item = ['name' => 'role_group_item', 'role_group_user_org_type' => SystemTableName::ORGANIZATION];

            $role_group = $request->get($item['name'], []);
            $role_group = collect($role_group)->filter()->map(function ($role_group_target) use ($id, &$item) {
                list($role_group_user_org_type, $role_group_target_id) = explode('_', $role_group_target);
                $item['role_group_user_org_type'] = $role_group_user_org_type;
                $item['role_group_target_id'] = $role_group_target_id;
                
                return [
                    'role_group_id' => $id,
                    'role_group_user_org_type' => $role_group_user_org_type,
                    'role_group_target_id' => $role_group_target_id,
                ];
            });
                
            \Schema::insertDelete(SystemTableName::ROLE_GROUP_USER_ORGANIZATION, $role_group, [
                'dbValueFilter' => function (&$model) use ($id) {
                    $model->where('role_group_id', $id);
                },
                'dbDeleteFilter' => function (&$model, $dbValue) use ($id) {
                    $model->where('role_group_id', $id)
                        ->where('role_group_target_id', array_get((array)$dbValue, 'role_group_target_id'))
                        ->where('role_group_user_org_type', array_get((array)$dbValue, 'role_group_user_org_type'));
                },
                'matchFilter' => function ($dbValue, $value) {
                    return array_get((array)$dbValue, 'role_group_target_id') == array_get($value, 'role_group_target_id')
                        && array_get((array)$dbValue, 'role_group_user_org_type') == array_get($value, 'role_group_user_org_type');
                },
            ]);

            \DB::commit();

            System::clearCache();
            
            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('role_group'));
        } catch (\Exception $exception) {
            //TODO:error handling
            \DB::rollback();
            throw $exception;
        }
    }

    /**
     * get Progress Info
     *
     * @param [type] $id
     * @param [type] $is_action
     * @return array
     */
    protected function getProgressInfo($isSelectTarget, $id = null)
    {
        $steps[] = [
            'active' => $isSelectTarget,
            'complete' => false,
            'url' => isset($id) ? admin_urls('role_group', $id, 'edit') : null,
            'description' => exmtrans('role_group.permission_setting')
        ];
        
        $steps[] = [
            'active' => !$isSelectTarget,
            'complete' => false,
            'url' => isset($id) && $this->hasPermission_UserOrganization() ? admin_urls('role_group', $id, 'edit?form_type=2') : null,
            'description' => exmtrans('role_group.user_organization_setting')
        ];
        return $steps;
    }

    protected function validateForm($isRolePermissionPage = true)
    {
        if ($isRolePermissionPage) {
            if (!\Exment::user()->hasPermission(Permission::ROLE_GROUP_PERMISSION)) {
                Checker::error();
                return false;
            }
        } else {
            if (!\Exment::user()->hasPermission(Permission::ROLE_GROUP_USER_ORGANIZATION)) {
                Checker::error();
                return false;
            }
        }

        return true;
    }

    /**
     * Add tools button
     *
     * @param [type] $box
     * @param [type] $id
     * @param boolean $isRolePermissionPage
     * @return void
     */
    protected function appendTools($box, $id = null, $isRolePermissionPage = true)
    {
        $box->tools(view('exment::tools.button', [
            'href' => admin_urls('role_group'),
            'label' => trans('admin.list'),
            'icon' => 'fa-list',
            'btn_class' => 'btn-default',
        ]));
        
        $box->tools(new Tools\SystemChangePageMenu());

        if (!isset($id)) {
            return;
        }

        if ($isRolePermissionPage) {
            if ($this->hasPermission_UserOrganization()) {
                $box->tools(view('exment::tools.button', [
                    'href' => admin_urls('role_group', $id, 'edit?form_type=2'),
                    'label' => exmtrans('role_group.user_organization_setting'),
                    'icon' => 'fa-users',
                    'btn_class' => 'btn-default',
                ]));
            }
        } else {
            $box->tools(view('exment::tools.button', [
                'href' => admin_urls('role_group', $id, 'edit'),
                'label' => exmtrans('role_group.permission_setting'),
                'icon' => 'fa-user-secret',
                'btn_class' => 'btn-default',
            ]));
        }
    }

    protected function widgetDestroy($id)
    {
        try {
            collect(explode(',', $id))->filter()->each(function ($id) {
                $model = RoleGroup::findOrFail($id);
                $model->delete();
            });

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    protected function hasPermission_Permission()
    {
        return \Exment::user()->hasPermission([Permission::ROLE_GROUP_ALL, Permission::ROLE_GROUP_PERMISSION]);
    }
    protected function hasPermission_UserOrganization()
    {
        return \Exment::user()->hasPermission([Permission::ROLE_GROUP_ALL, Permission::ROLE_GROUP_USER_ORGANIZATION]);
    }
}
