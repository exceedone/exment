<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
//use Encore\Admin\Controllers\HasResourceActions;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Role as RoleEnum;

class RoleController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("role.header"), exmtrans("role.header"), exmtrans("role.description"));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Role);
        $grid->column('role_type', exmtrans('role.role_type'))->display(function ($role_type) {
            return RoleType::getEnum($role_type)->transKey('role.role_type_options') ?? null;
        });
        $grid->column('role_name', exmtrans('role.role_name'));
        $grid->column('role_view_name', exmtrans('role.role_view_name'));
        
        $grid->model()->where('role_type', '<>', RoleType::PLUGIN);
        $grid->model()->orderBy('role_type')->orderBy('id');

        $grid->disableCreateButton();
        $grid->tools(function (Grid\Tools $tools) {
            // ctrate newbutton (list) --------------------------------------------------
            $base_uri = admin_url('role/create');
            $addNewBtn = '<div class="btn-group pull-right" style="margin-right: 5px">
                <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
                    <i class="fa fa-plus"></i>&nbsp;'.trans('admin.new') . '
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu" role="menu">';
            // loop for role types
            foreach (RoleType::transKeyArray("role.role_type_options") as $role_type => $label) {
                if($role_type == RoleType::PLUGIN){
                    continue;
                }
                $addNewBtn .= '<li><a href="'.$base_uri.'?role_type='.$role_type.'">'.$label.'</a></li>';
            }
            $addNewBtn .= '</ul></div>';
            $tools->append($addNewBtn);
        });
        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableView();
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new Role);
        // get role_type query or post value
        if (isset($id)) {
            $role_type = Role::find($id)->role_type;
        } else {
            $role_type = app('request')->get('role_type');
            if (!isset($role_type)) {
                $role_type = app('request')->query('role_type');
            }
        }

        $role_type_default = RoleType::getEnum($role_type)->transKey("role.role_type_options") ?? null;
        $form->hidden('role_type')->default($role_type);
        $form->display('role_type_default', exmtrans("role.role_type"))->default($role_type_default);

        if (!isset($id)) {
            $form->text('role_name', exmtrans('role.role_name'))
            ->required()
            ->rules("unique:".Role::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
            ->help(exmtrans('common.help_code'));
        } else {
            $form->display('role_name', exmtrans('role.role_name'));
        }

        $form->text('role_view_name', exmtrans('role.role_view_name'))->required();
        $form->textarea('description', exmtrans('role.description_field'))->rows(3);
        $form->switchbool('default_flg', exmtrans('role.default_flg'));

        // create permissons looping
        $form->embeds('permissions', exmtrans('role.permissions'), function ($form) use ($role_type) {
            // role define
            $enum = RoleType::getEnum($role_type);
            $role_type_key = strtolower($enum->getKey());
            $roles = RoleEnum::getRoleType($role_type);
            foreach ($roles as $role_define) {
                $transArray = exmtrans("role.role_type_option_$role_type_key.$role_define");
                $form->switchbool($role_define, array_get($transArray, 'label'))->help(array_get($transArray, 'help'));
            }
        });
        disableFormFooter($form);
        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });
        return $form;
    }
}
