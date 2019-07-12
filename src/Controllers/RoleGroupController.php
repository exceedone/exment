<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\Role as RoleEnum;
use Encore\Admin\Layout\Content;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Box;

class RoleGroupController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
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
        $grid->column('role_group_users')->display(function ($counts) {
            return count($counts);
        });
        $grid->column('role_group_organizations')->display(function ($counts) {
            return count($counts);
        });

        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableView();
            $actions->disableEdit();

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

        if (!isset($id)) {
            $form->text('role_group_name', exmtrans('role_group.role_group_name'))
            ->required()
            ->rules("max:30|unique:".RoleGroup::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
            ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
            ;
        } else {
            $form->display('role_group_name', exmtrans('role_group.role_group_name'));
        }

        $form->text('role_group_view_name', exmtrans('role_group.role_group_view_name'))
            ->required()
            ->rules("max:40");

        $permissions = Permission::getSystemRolePermissions();
        $permissionLabels = collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role_group.role_type_option_system.$permission.label")];
        });
        $form->exmheader('システム権限')->hr();
        $form->multipleSelect('system_permission[system][permissions]', 'システム権限')
            ->options($permissionLabels)
            ->default($model->role_group_permissions->first(function($role_group_permission){
                return $role_group_permission->role_group_permission_type == RoleType::SYSTEM;
            })->permissions ?? null)
            ->config('allowClear', false);
        ;

        // get permission options for master
        $permissions = Permission::getMasterRolePermissions();
        $permissionLabels = collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role_group.role_type_option_table.$permission.label")];
        });
        $form->exmheader('マスター権限')->hr();
        foreach(CustomTable::filterList(null, ['filter' => function($model){
            $model->whereIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER());
            return $model;
        }]) as $table){
            $form->hidden("master_permission[$table->table_name][id]")
                ->default($table->id);
            $form->multipleSelect("master_permission[$table->table_name][permissions]", $table->table_view_name)
                ->options($permissionLabels)
                ->default($model->role_group_permissions->first(function($role_group_permission) use($table){
                    return $role_group_permission->role_group_permission_type == RoleType::TABLE && $role_group_permission->role_group_target_id == $table->id;
                })->permissions ?? null)
                ->config('allowClear', false);
        }

        // get permission options for table
        $permissions = Permission::getTableRolePermissions();
        $permissionLabels = collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role_group.role_type_option_table.$permission.label")];
        });
        $form->exmheader('テーブル権限')->hr();
        foreach(CustomTable::filterList(null, ['filter' => function($model){
            $model->whereNotIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER());
            return $model;
        }]) as $table){
            $form->hidden("table_permission[$table->table_name][id]")
                ->default($table->id);
            $form->multipleSelect("table_permission[$table->table_name][permissions]", $table->table_view_name)
                ->options($permissionLabels)
                ->default($model->role_group_permissions->first(function($role_group_permission) use($table){
                    return $role_group_permission->role_group_permission_type == RoleType::TABLE && $role_group_permission->role_group_target_id == $table->id;
                })->permissions ?? null)
                ->config('allowClear', false);
        }

        return $form;
    }

    /**
     * Make a form builder for User Organization.
     *
     * @return Form
     */
    protected function formUserOrganization($id)
    {
        $model = RoleGroup::with(['role_group_users', 'role_group_organizations'])->findOrFail($id);
        $form = new Form($model->toArray());
        $form->disableReset();
        $form->action(admin_urls('role_group', $id . '?form_type=2'));
        $form->method('put');

        $form->progressTracker()->options($this->getProgressInfo(false, $id));

        $form->listbox('role_group_users_item', 'ユーザー')
            ->options(function($option){
                return CustomTable::getEloquent(SystemTableName::USER)->getOptions($option);
            })
            ->default($model->role_group_users->pluck('id')->toArray())
            ->settings(['nonSelectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.selectedListLabel')]);
        ;

        $form->listbox('role_group_organizations_item', '組織')
            ->options(function($option){
                return CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getOptions($option);
            })
            ->default($model->role_group_organizations->pluck('id')->toArray())
            ->settings(['nonSelectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.selectedListLabel')]);
        ;

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
        $request = request();

        // validation
        $rules = [
            'role_group_name' => isset($id) ? 'nullable' : 'required' . '|max:64|regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/',
            'role_group_view_name' => 'required|max:64',
        ];

        $validation = \Validator::make($request->all(), $rules);

        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }

        \DB::beginTransaction();

        try {
            $role_group = isset($id) ? RoleGroup::findOrFail($id) : new RoleGroup;
            if(!isset($id)){
                $role_group->role_group_name = $request->get('role_group_name');
            }
            $role_group->role_group_view_name = $request->get('role_group_view_name');
            $role_group->save();

            $items = [
                ['name' => 'system_permission', 'role_group_permission_type' => RoleType::SYSTEM],
                ['name' => 'master_permission', 'role_group_permission_type' => RoleType::TABLE],
                ['name' => 'table_permission', 'role_group_permission_type' => RoleType::TABLE],
            ];

            $relations = [];
            foreach($items as $item){
                $requestItems = $request->get($item['name']);

                foreach($requestItems as $requestItem){
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
        } catch (Exception $exception) {
            //TODO:error handling
            \DB::rollback();
            throw $exception;
        }
    }

    protected function saveUserOrganization($id = null)
    {
        $request = request();

        \DB::beginTransaction();

        try {
                
            // get user and org
            $items = [
                ['name' => 'role_group_users_item', 'role_group_user_org_type' => SystemTableName::USER],
                ['name' => 'role_group_organizations_item', 'role_group_user_org_type' => SystemTableName::ORGANIZATION],
            ];

            foreach($items as $item){
                $role_group = $request->get($item['name'], []);
                $role_group = collect($role_group)->filter()->map(function($role_group_target) use($id, $item){
                    return [
                        'role_group_id' => $id,
                        'role_group_user_org_type' => $item['role_group_user_org_type'],
                        'role_group_target_id' => $role_group_target,
                    ];
                });
                    
                \Schema::insertDelete(SystemTableName::ROLE_GROUP_USER_ORGANIZATION, $role_group, [
                    'dbValueFilter' => function(&$model) use($id, $item){
                        $model->where('role_group_id', $id)
                            ->where('role_group_user_org_type', $item['role_group_user_org_type']);
                    },
                    'dbDeleteFilter' => function(&$model) use($id, $item){
                        $model->where('role_group_id', $id)
                            ->where('role_group_user_org_type', $item['role_group_user_org_type']);
                    },
                    'matchFilter' => function($dbValue, $value) use($id, $item){
                        return true;
                    },
                ]);

            }
            \DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('role_group'));
        } catch (Exception $exception) {
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
     * @return void
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
            'url' => isset($id) ? admin_urls('role_group', $id, 'edit?form_type=2') : null,
            'description' => exmtrans('role_group.user_organization_setting')
        ];
        return $steps;
    }

    protected function appendTools($box, $id = null, $isRolePermissionPage = true){
        $box->tools(view('exment::tools.button', [
            'href' => admin_urls('role_group'),
            'label' => trans('admin.list'),
            'icon' => 'fa-list',
            'btn_class' => 'btn-default',
        ]));
        
        if(isset($id)){
            if($isRolePermissionPage){
                $box->tools(view('exment::tools.button', [
                    'href' => admin_urls('role_group', $id, 'edit?form_type=2'),
                    'label' => exmtrans('role_group.user_organization_setting'),
                    'icon' => 'fa-users',
                    'btn_class' => 'btn-default',
                ]));
            }else{
                $box->tools(view('exment::tools.button', [
                    'href' => admin_urls('role_group', $id, 'edit'),
                    'label' => exmtrans('role_group.permission_setting'),
                    'icon' => 'fa-user-secret',
                    'btn_class' => 'btn-default',
                ]));
            }
        }

    }
}
