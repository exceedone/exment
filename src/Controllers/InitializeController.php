<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;

class InitializeController extends Controller
{
    use InitializeForm;
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request)
    {
        $form = $this->getInitializeForm(true);
        $form->action('initialize');
        $form->disablePjax();

        // ID and password --------------------------------------------------
        $form->header(exmtrans('system.administrator'))->hr();
        $form->text('user_code', exmtrans('user.user_code'))->help(exmtrans('common.help_code'));
        $form->text('user_name', exmtrans('user.user_name'))->help(exmtrans('user.help.user_name'));
        $form->text('email', exmtrans('user.email'))->help(exmtrans('user.help.email'));
        $form->password('password', exmtrans('user.password'))->help(exmtrans('user.help.password'));
        $form->password('password_confirmation', exmtrans('user.password_confirmation'));

        return view('exment::initialize.content', [
            'content'=> $form->render(),
            'header' => exmtrans('system.initialize_header'),
            'description' => exmtrans('system.initialize_description'),
            ]);
    }

    /**
     * submit
     * @param Request $request
     */
    public function post(Request $request)
    {
        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request, true);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }
            
            // add user table
            $user_modelname = getModelName(SystemTableName::USER);
            $user = new $user_modelname();
            $user->value = [
                'user_code' => $request->get('user_code'),
                'user_name' => $request->get('user_name'),
                'email' => $request->get('email'),
            ];
            $user->saveOrFail();

            // add login_user table
            $loginuser = new LoginUser;
            $loginuser->base_user_id = $user->id;
            $loginuser->password = bcrypt($request->get('password'));
            $loginuser->saveOrFail();

            // add system role
            DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->insert(
                [
                    'related_id' => $user->id,
                    'related_type' => SystemTableName::USER,
                    'morph_id' => null,
                    'morph_type' =>  RoleType::SYSTEM,
                    'role_id' => Role::where('role_type', RoleType::SYSTEM)->first()->id,
                ]
            );

            // add system initialized flg.
            System::initialized(1);
            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));
            $this->guard()->login($loginuser);
            return redirect(admin_base_path('/'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }
    }

    protected function guard()
    {
        return Auth::guard('admin');
    }
}
