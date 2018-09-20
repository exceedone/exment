<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as Req;

/**
 * For login controller
 */
class AuthController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait;
    /**
     * Login page.
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function getLoginExment(Request $request)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view('exment::auth.login',$this->getLoginPageData());
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        $user = LoginUser::class;
        return $user::form(function (Form $form){
            $form->display('base_user.value.user_code', exmtrans('user.user_code'));
            $form->text('base_user.value.user_name', exmtrans('user.user_name'));
            $form->text('base_user.value.email', exmtrans('user.email'));
            $form->image('avatar', exmtrans('user.avatar'));
            $form->password('password', exmtrans('user.new_password'))->rules(get_password_rule(false))->help(exmtrans('user.help.change_only').exmtrans('user.help.password'));
            $form->password('password_confirmation', exmtrans('user.new_password_confirmation'));

            $form->setAction(admin_base_path('auth/setting'));
            $form->ignore(['password_confirmation']);

            $form->disableReset();
            $form->disableViewCheck();
            $form->tools(function (Form\Tools $tools){
                $tools->disableView();
                $tools->disableDelete();
            });

            $form->saving(function (Form $form) {
                if ($form->password && $form->model()->password != $form->password) {
                    $form->password = bcrypt($form->password);
                }
            });
            
            $form->saved(function ($form) {
                // saving user info
                DB::transaction(function () use($form) {
                    // login_user id
                    $user_id = $form->model()->base_user->id;
                    // save user name and email
                    $user = getModelName(Define::SYSTEM_TABLE_NAME_USER)::find($user_id);
                    $value = $user->value;
                    $req = Req::all();
                    $value['user_name'] = array_get($req, 'base_user.value.user_name');
                    $value['email'] = array_get($req, 'base_user.value.email');
                    $user->value = $value;
                    $user->save();
                });
                
                admin_toastr(trans('admin.update_succeeded'));
    
                return redirect(admin_base_path('auth/setting'));
            });
        });
    }
}
