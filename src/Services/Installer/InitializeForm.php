<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\EnvService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 *
 */
class InitializeForm
{
    use EnvTrait;
    use InitializeFormTrait;

    public function index()
    {
        $form = $this->getInitializeForm('initialize', true);
        $form->action(admin_url('initialize'));
        $form->disablePjax();
        $form->attribute(['class' => 'form-horizontal click_disabled_submit']);

        // ID and password --------------------------------------------------
        $form->exmheader(exmtrans('system.administrator'))->hr();
        $form->text('user_code', exmtrans('user.user_code'))->required()->help(exmtrans('common.help_code'));
        $form->text('user_name', exmtrans('user.user_name'))->required()->help(exmtrans('user.help.user_name'));
        $form->text('email', exmtrans('user.email'))->required()->help(exmtrans('user.help.email'));
        $form->password('password', exmtrans('user.password'))->required()->help(\Exment::get_password_help());
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
            $result = $this->postInitializeForm($request, 'initialize', true, true);
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
            $loginuser = new LoginUser();
            $loginuser->base_user_id = $user->getUserId();
            $loginuser->password = $request->get('password');
            $loginuser->saveOrFail();
            // add system role
            System::system_admin_users([$user->getUserId()]);

            // add system initialized flg.
            System::initialized(1);

            // write env
            try {
                EnvService::setEnv(['EXMENT_INITIALIZE' => 1]);
            }
            // if cannot write, nothing do
            catch (\Exception $ex) {
            } catch (\Throwable $ex) {
            }

            \DB::commit();
            admin_toastr(trans('admin.save_succeeded'));
            $this->guard()->login($loginuser);

            // set session for 2factor
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

            return redirect(admin_url('/'));
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }
    }

    protected function guard()
    {
        return Auth::guard('admin');
    }
}
