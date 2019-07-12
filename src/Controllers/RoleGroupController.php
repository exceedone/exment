<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\Role as RoleEnum;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;

class RoleGroupController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("role.header"), exmtrans("role.header"), exmtrans("role.description"), 'fa-user-secret');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new RoleGroup);
        $grid->column('role_group_name', exmtrans('role.role_name'));
        $grid->column('role_group_view_name', exmtrans('role.role_view_name'));
        
        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableView();
        });
        return $grid;
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
        $box = new Box(trans('admin.edit'), $this->form($id)->edit($id));
        return $this->AdminContent($content)->body($box);
    }

    /**
     * Create interface.
     *
     * @param Content $content
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        $box = new Box(trans('admin.create'), $this->form());
        return $this->AdminContent($content)->body($box);
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new RoleGroup);
        $form->disableReset();
        $form->action(admin_urls('role-group', $id));

        $form->progressTracker()->options($this->getProgressInfo(true));

        $form->text('role_group_name')->required();
        $form->text('role_group_view_name')->required();

        $permissions = Permission::getSystemRolePermissions();
        $permissionLabels = collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role.role_type_option_system.$permission.label")];
        });
        $form->exmheader('システム権限')->hr();
        $form->multipleSelect('system_permission', 'システム権限')
            ->options($permissionLabels)
            ->config('allowClear', false);
        ;

        // get permission options for master
        $permissions = Permission::getMasterRolePermissions();
        $permissionLabels = collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role.role_type_option_table.$permission.label")];
        });
        $form->exmheader('マスター権限')->hr();
        foreach(CustomTable::filterList(null, ['filter' => function($model){
            $model->whereIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER());
            return $model;
        }]) as $table){
            $form->multipleSelect('master_permission_' . $table->table_name, $table->table_view_name)
                ->options($permissionLabels)
                ->config('allowClear', false);
        }

        // get permission options for table
        $permissions = Permission::getTableRolePermissions();
        $permissionLabels = collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role.role_type_option_table.$permission.label")];
        });
        $form->exmheader('テーブル権限')->hr();
        foreach(CustomTable::filterList(null, ['filter' => function($model){
            $model->whereNotIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER());
            return $model;
        }]) as $table){
            $form->multipleSelect('table_permission_' . $table->table_name, $table->table_view_name)->options($permissionLabels)
                ->config('allowClear', false);
        }

        return $form;
    }

    /**
     * get Progress Info
     *
     * @param [type] $id
     * @param [type] $is_action
     * @return void
     */
    protected function getProgressInfo($isSelectTarget)
    {
        $steps[] = [
            'active' => $isSelectTarget,
            'complete' => false,
            'url' => null,
            'description' => '権限設定'
        ];
        $steps[] = [
            'active' => !$isSelectTarget,
            'complete' => false,
            'url' => null,
            'description' => '所属ユーザー・組織選択'
        ];
        return $steps;
    }
}
