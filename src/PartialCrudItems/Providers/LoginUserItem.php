<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\PartialCrudItems\ProviderBase;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Services\Login\LoginService;
use Illuminate\Http\RedirectResponse;

/**
 * Login User item
 * @phpstan-consistent-constructor
 */
class LoginUserItem extends ProviderBase
{
    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
        if (!\Exment::user()->hasPermission(Permission::LOGIN_USER)) {
            return;
        }

        if (!LoginSetting::isUseDefaultLoginForm()) {
            return;
        }

        $classname = getModelName(SystemTableName::USER);
        $login_user = $this->getLoginUser($id);
        $has_loginuser = !is_null($login_user);

        $form->exmheader(exmtrans('user.login'))->hr();
        $form->switchbool('use_loginuser', exmtrans('user.use_loginuser'))
                ->help(exmtrans('user.help.use_loginuser'))
                ->default($has_loginuser ? '1' : '0')
                ->attribute(['data-filtertrigger' => true]);

        if ($has_loginuser) {
            $form->switchbool('reset_password', exmtrans('user.reset_password'))
                /** @phpstan-ignore-next-line Negated boolean expression is always false. */
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
                ->help(\Exment::get_password_help())
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

        if (!System::first_change_password()) {
            $form->switchbool('password_reset_flg', exmtrans('user.password_reset_flg'))
                ->default('0')
                ->help(exmtrans('user.help.password_reset_flg'))
                ->attribute(['data-filter' => json_encode([
                    ['key' => 'use_loginuser', 'value' => '1']
                    , ['key' => 'reset_password', 'value' => "1"]
                    ])]);
        }

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

        $form->ignore(['use_loginuser', 'reset_password', 'create_password_auto', 'password_reset_flg', 'password', 'password_confirmation', 'send_password']);
    }

    /**
     * saving event
     */
    public function saving($form, $id = null)
    {
        if (!\Exment::user()->hasPermission(Permission::LOGIN_USER)) {
            return;
        }

        if (!LoginSetting::isUseDefaultLoginForm()) {
            return;
        }

        $data = request()->all();
        $info = $this->getLoginUserInfo($data, $id);
        if ($info instanceof \Symfony\Component\HttpFoundation\Response) {
            return $info;
        }

        return true;
    }

    /**
     * saved event
     */
    public function saved($form, $id)
    {
        if (!\Exment::user()->hasPermission(Permission::LOGIN_USER)) {
            return;
        }

        if (!LoginSetting::isUseDefaultLoginForm()) {
            return;
        }

        $data = request()->all();
        $info = $this->getLoginUserInfo($data, $id);
        if ($info instanceof \Symfony\Component\HttpFoundation\Response) {
            return $info;
        }

        // return [$login_user, $has_change, $send_password, boolval(array_get($data, 'password_reset_flg'))];
        list($login_user, $password, $has_change, $send_password, $password_reset_flg) = $info;

        try {
            if ($has_change) {
                LoginService::resetPassword($login_user, [
                    'password' => $password,
                    'send_password' => $send_password,
                    'password_reset_flg' => $password_reset_flg,
                ]);
            }
        } catch (\Exception $ex) {
            throw $ex;
        }
    }


    /**
     * Get login user info.
     *
     * @param array $data
     * @param null|string $id
     * @return array|\Illuminate\Http\Response|RedirectResponse|void  if error, return redirect. if success, array.
     */
    protected function getLoginUserInfo($data, $id)
    {
        $user = getModelName(SystemTableName::USER)::find($id);

        // get login user
        $login_user = $this->getLoginUser($id);
        // if "$user" has "login_user" obj and unchecked "use_loginuser", delete login user object.
        if (!boolval(array_get($data, 'use_loginuser'))) {
            if (!is_null($login_user)) {
                $login_user->delete();
            }
            return;
        }

        // if "$user" doesn't have "login_user" obj and checked "use_loginuser", create login user object.
        $has_change = false;
        $is_newuser = false;
        $password = null;
        /** @phpstan-ignore-next-line Right side of && is always true. Maybe boolval is unessasary. */
        if (is_null($login_user) && boolval(array_get($data, 'use_loginuser'))) {
            $login_user = new LoginUser();
            $is_newuser = true;
            $login_user->base_user_id = $user ? $user->getUserId() : null;
            $has_change = true;
        }

        // if user select "reset_password" (or new create)
        if (boolval(array_get($data, 'reset_password'))) {
            // user select "create_password_auto"
            if (boolval(array_get($data, 'create_password_auto'))) {
                $password = make_password();
                $has_change = true;
            } elseif (boolval(array_get($data, 'password'))) {
                $rules = [
                'password' => get_password_rule(true, $login_user),
                ];
                $validation = \Validator::make($data, $rules);
                if ($validation->fails()) {
                    return back()->withInput()->withErrors($validation);
                }
                $password = array_get($data, 'password');
                $has_change = true;
            } else {
                return back()->withInput()->withErrors([
                    'create_password_auto' => exmtrans('user.message.required_password')]);
            }
        }

        $send_password = boolval(array_get($data, 'send_password')) || boolval(array_get($data, 'create_password_auto'));
        return [$login_user, $password, $has_change, $send_password, boolval(array_get($data, 'password_reset_flg'))];
    }

    /**
     * set user grid's actions
     */
    public function setGridRowAction(&$actions)
    {
        $this->setEditDelete($actions, $actions->row);
    }

    /**
     * set user form's tools
     */
    public function setAdminFormTools(&$tools, $id = null)
    {
        $this->setEditDelete($tools, $id);
    }

    /**
     * set user show form's tool
     */
    public function setAdminShowTools(&$tools, $id = null)
    {
        $this->setEditDelete($tools, $id);
    }

    protected function setEditDelete($tools, $custom_value)
    {
        if (is_numeric($custom_value)) {
            $custom_value = getModelName(SystemTableName::USER)::find($custom_value);
        }

        if (!isset($custom_value)) {
            return;
        }

        // only administrator can delete and edit administrator record
        if (!\Exment::user()->isAdministrator() && $custom_value->isAdministrator()) {
            $tools->disableDelete();
            $tools->disableEdit();
        }
        // cannnot delete myself
        if (\Exment::getUserId() == $custom_value->id) {
            $tools->disableDelete();
        }
    }

    protected function getLoginUser($base_user_id)
    {
        if (!isset($base_user_id)) {
            return null;
        }

        $login_user = LoginUser::where('base_user_id', $base_user_id)->whereNull('login_provider')->where('login_type', LoginType::PURE)->first();
        return $login_user;
    }
}
