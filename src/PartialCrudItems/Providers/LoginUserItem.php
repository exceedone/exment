<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\PartialCrudItems\ProviderBase;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use \DB;

/**
 * Login User item
 */
class LoginUserItem extends ProviderBase
{
    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
        if(!\Exment::user()->hasPermission(Permission::LOGIN_USER)){
            return;
        }

        $classname = getModelName(SystemTableName::USER);
        $login_user = $this->getLoginUser($classname::find($id));
        $has_loginuser = !is_null($login_user);
        $showLoginInfo = useLoginProvider() && !boolval(config('exment.show_default_login_provider', true));

        if (!$showLoginInfo) {
            $form->exmheader(exmtrans('user.login'))->hr();
            $form->switchbool('use_loginuser', exmtrans('user.use_loginuser'))
                    ->help(exmtrans('user.help.use_loginuser'))
                    ->default($has_loginuser ? '1' : '0')
                    ->attribute(['data-filtertrigger' => true]);

            if ($has_loginuser) {
                $form->switchbool('reset_password', exmtrans('user.reset_password'))
                            ->default(!$has_loginuser)
                            ->help(exmtrans('user.help.reset_password'))
                            ->attribute(['data-filter' => json_encode(['key' => 'use_loginuser', 'value' => '1'])]);
            } else {
                $form->hidden('reset_password')->default("1");
            }

            $form->switchbool('create_password_auto', exmtrans('user.create_password_auto'))
                ->default('1')
                ->help(exmtrans('user.help.create_password_auto'))
                ->attribute(['data-filter' => json_encode([
                    ['key' => 'use_loginuser', 'value' => '1']
                    , ['key' => 'reset_password', 'value' => "1"]
                    ])]);

            $form->password('password', exmtrans('user.password'))->default('')
                    ->help(exmtrans('user.help.password'))
                    ->attribute(['data-filter' => json_encode([
                        ['key' => 'use_loginuser', 'value' => '1']
                        , ['key' => 'reset_password', 'value' => "1"]
                        , ['key' => 'create_password_auto', 'value' => '0']
                        ])]);

            $form->password('password_confirmation', exmtrans('user.password_confirmation'))->default('')
                ->attribute(['data-filter' => json_encode([
                    ['key' => 'use_loginuser', 'value' => '1']
                    , ['key' => 'reset_password', 'value' => "1"]
                    , ['key' => 'create_password_auto', 'value' => '0']
                    ])]);

            // "send_password"'s data-filter is whether $id is null or hasvalue
            $send_password_filter = [
                ['key' => 'use_loginuser', 'value' => '1'],
                ['key' => 'create_password_auto', 'value' => '0'],
            ];
            if (isset($id)) {
                $send_password_filter[] = ['key' => 'reset_password', 'value' => "1"];
            }
            $form->switchbool('send_password', exmtrans('user.send_password'))
                ->default(1)
                ->help(exmtrans('user.help.send_password'))
                ->attribute(['data-filter' => json_encode($send_password_filter)]);
        } else {
            
        }

        $form->ignore(['use_loginuser', 'reset_password', 'create_password_auto', 'password', 'password_confirmation', 'send_password']);
    }

    /**
     * saved event
     */
    public function saved($form, $id)
    {
        if(!\Exment::user()->hasPermission(Permission::LOGIN_USER)){
            return;
        }
        
        $data = request()->all();
        $user = getModelName(SystemTableName::USER)::findOrFail($id);

        try {
            // get login user
            $login_user = $this->getLoginUser($user);
            // if "$user" has "login_user" obj and unchecked "use_loginuser", delete login user object.
            if (!is_null($login_user) && !boolval(array_get($data, 'use_loginuser'))) {
                $login_user->delete();
                return;
            }

            // if "$user" doesn't have "login_user" obj and checked "use_loginuser", create login user object.
            $has_change = false;
            $is_newuser = false;
            $password = null;
            if (is_null($login_user) && boolval(array_get($data, 'use_loginuser'))) {
                $login_user = new LoginUser;
                $is_newuser = true;
                $login_user->base_user_id = $user->id;
                $has_change = true;
            }

            // if user select "reset_password" (or new create)
            if (boolval(array_get($data, 'reset_password'))) {
                // user select "create_password_auto"
                if (boolval(array_get($data, 'create_password_auto'))) {
                    $password = make_password();
                    $login_user->password = $password;
                    $has_change = true;
                } elseif (boolval(array_get($data, 'password'))) {
                    $rules = [
                    'password' => get_password_rule(true),
                    ];
                    $validation = \Validator::make($data, $rules);
                    if ($validation->fails()) {
                        return back()->withInput()->withErrors($validation);
                    }
                    $password = array_get($data, 'password');
                    $login_user->password = $password;
                    $has_change = true;
                } else {
                    return back()->withInput()->withErrors([
                        'create_password_auto' => exmtrans('user.message.required_password')]);
                }
            }

            if ($has_change) {
                // mailsend
                if (boolval(array_get($data, 'send_password')) || boolval(array_get($data, 'create_password_auto'))) {
                    try {
                        $login_user->sendPassword($password);
                    }
                    // throw mailsend Exception
                    catch (\Swift_TransportException $ex) {
                        admin_error(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
                        return back()->withInput();
                    }
                }
                $login_user->save();
            }
        } catch (\Swift_TransportException $ex) {
            admin_error('Error', exmtrans('error.mailsend_failed'));
            return back()->withInput();
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    
    protected function getLoginUser($user)
    {
        if(!isset($user)){
            return null;
        }

        $login_user = $user->login_users()->whereNull('login_provider')->first();
        return $login_user;
    }
}
