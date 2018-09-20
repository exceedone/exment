<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Authority;

class AuthorityController extends AdminControllerBase
{
    use ModelForm;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("authority.header"), exmtrans("authority.header"), exmtrans("authority.description"));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Admin::grid(Authority::class, function (Grid $grid) {
            $grid->column('authority_type', exmtrans('authority.authority_type'))->display(function ($authority_type) {
                return exmtrans('authority.authority_type_options.'.$authority_type);
            });
            $grid->column('authority_name', exmtrans('authority.authority_name'));
            $grid->column('authority_view_name', exmtrans('authority.authority_view_name'));
            // $grid->column('default_flg', exmtrans('authority.default_flg'))->display(function($default_flg){
            //     return boolval($default_flg) ? exmtrans('authority.default_flg_true') : exmtrans('authority.default_flg_false');
            // });
            $grid->model()->orderBy('authority_type')->orderBy('id');

            $grid->disableCreateButton();
            $grid->tools(function (Grid\Tools $tools) {
                // ctrate newbutton (list) --------------------------------------------------
                $base_uri = admin_base_path('authority/create');
                $addNewBtn = '<div class="btn-group pull-right">
                    <a class="btn btn-sm btn-success"><i class="fa fa-save"></i>&nbsp;'.trans('admin.new').'</a>
                    <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
                        <span class="caret"></span>
                        <span class="sr-only">Toggle Dropdown</span>
                    </button>
                    <ul class="dropdown-menu" role="menu">';
                // loop for authority types
                foreach (getTransArray(Define::AUTHORITY_TYPES, "authority.authority_type_options") as $authority_type => $label) {
                    $addNewBtn .= '<li><a href="'.$base_uri.'?authority_type='.$authority_type.'">'.$label.'</a></li>';
                }
                $addNewBtn .= '</ul></div>';
                $tools->append($addNewBtn);
            });
            $grid->actions(function ($actions) {
                $actions->disableView();
            });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return Admin::form(Authority::class, function (Form $form) use ($id) {
            // get authority_type query or post value
            if(isset($id)){
                $authority_type = Authority::find($id)->authority_type;    
            }else{
                $authority_type = \Illuminate\Support\Facades\Request::get('authority_type');
                if (!isset($authority_type)) {
                    $authority_type = \Illuminate\Support\Facades\Request::query('authority_type');
                }
            }
            $form->hidden('authority_type')->default($authority_type);
            $form->display('authority_type', exmtrans("authority.authority_type"))->default(exmtrans("authority.authority_type_options.".$authority_type));

            if (!isset($id)) {
                $form->text('authority_name', exmtrans('authority.authority_name'))->rules("required|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
                ->help(exmtrans('common.help_code'));
            } else {
                $form->display('authority_name', exmtrans('authority.authority_name'));
            }

            $form->text('authority_view_name', exmtrans('authority.authority_view_name'))->rules("required");
            $form->textarea('description', exmtrans('authority.description_field'))->rows(3);
            $form->switchbool('default_flg', exmtrans('authority.default_flg'));

            // create permissons looping
            $form->header(exmtrans('authority.permissions'))->hr();
            $form->embeds('permissions', exmtrans('authority.permissions'), function ($form) use ($authority_type) {
                // authority define
                foreach (Define::AUTHORITIES[$authority_type] as $authority_define) {
                    $transArray = exmtrans("authority.authority_type_option_$authority_type.$authority_define");
                    $form->switchbool($authority_define, array_get($transArray, 'label'))->help(array_get($transArray, 'help'));
                }
            });

            $form->disableReset();
            $form->disableViewCheck();
            $form->tools(function (Form\Tools $tools){
                $tools->disableView();
            });
        });
    }
}
