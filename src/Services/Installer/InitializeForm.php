<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 *
 */
class InitializeForm
{
    use EnvTrait, InitializeFormTrait;

    public function index()
    {
        $form = $this->getInitializeForm('initialize', true);
        $form->action(admin_url('initialize'));
        $form->disablePjax();
        // ID and password --------------------------------------------------
        $form->exmheader(exmtrans('system.administrator'))->hr();
        $form->text('user_code', exmtrans('user.user_code'))->required()->help(exmtrans('common.help_code'));
        $form->text('user_name', exmtrans('user.user_name'))->required()->help(exmtrans('user.help.user_name'));
        $form->text('email', exmtrans('user.email'))->required()->help(exmtrans('user.help.email'));
        $form->password('password', exmtrans('user.password'))->required()->help(exmtrans('user.help.password'));
        $form->password('password_confirmation', exmtrans('user.password_confirmation'))->required();
        return view('exment::initialize.content', [
            'content'=> $form->render(),
            'header' => exmtrans('system.initialize_header'),
            'description' => exmtrans('system.initialize_description'),
        ]);
    }
    
    public function post()
    {
        $request = request();
        \DB::beginTransaction();
        
        try {
            $result = $this->postInitializeForm($request, 'initialize', true);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }
            
            // add user table
            $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
            $user->value = [
                'user_code' => $request->get('user_code'),
                'user_name' => $request->get('user_name'),
                'email' => $request->get('email'),
            ];
            $user->saveOrFail();
            // add login_user table
            $loginuser = new LoginUser;
            $loginuser->base_user_id = $user->id;
            $loginuser->password = $request->get('password');
            $loginuser->saveOrFail();
            // add system role
            \DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->insert(
                [
                    'related_id' => $user->id,
                    'related_type' => SystemTableName::USER,
                    'morph_id' => null,
                    'morph_type' =>  RoleType::SYSTEM()->lowerKey(),
                    'role_id' => Role::where('role_type', RoleType::SYSTEM)->first()->id,
                ]
            );
            // add system initialized flg.
            System::initialized(1);
            \DB::commit();
            admin_toastr(trans('admin.save_succeeded'));
            $this->guard()->login($loginuser);

            // set session for 2factor
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

            return redirect(admin_url('/'));
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
