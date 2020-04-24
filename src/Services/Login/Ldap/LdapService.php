<?php
namespace Exceedone\Exment\Services\Login\Ldap;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Illuminate\Http\Request;

/**
 * LoginService
 */
class LdapService implements LoginServiceInterface
{
    public static function getLdapConfig(LoginSetting $login_setting)
    {
        return [
            'exment' => [
                'schema' => \Adldap\Schemas\ActiveDirectory::class,
                'hosts' => stringToArray($login_setting->getOption('ldap_hosts')),
                'port' => $login_setting->getOption('ldap_port') ?? 389,
                'timeout' => 10,
                'base_dn' => $login_setting->getOption('ldap_base_dn'),
                'follow_referrals' => false,
                'use_ssl' => boolval($login_setting->getOption('ldap_use_ssl')),
                'use_tls' => boolval($login_setting->getOption('ldap_use_tls')),
            ]
        ];
    }

    /**
     * Get login user name. append prefix and suffix.
     *
     * @param string $username User input name
     * @param LoginSetting $login_setting
     * @return string
     */
    public static function getLdapUserName($username, LoginSetting $login_setting) : string
    {
        $prefix = $login_setting->getOption('ldap_account_prefix');
        $suffix = $login_setting->getOption('ldap_account_suffix');

        return ((isset($prefix) && strpos($username, $prefix) !== 0) ? $prefix : '').
        $username .
        ((isset($suffix) && strripos($username, $suffix) !== (mb_strlen($username) - mb_strlen($suffix))) ? $suffix : '');
    }

    public static function syncLdapUserArray($provider, LoginSetting $login_setting, $username)
    {
        return $provider->search()->findBy($login_setting->getOption('ldap_search_key'), $username);
    }

    public static function getTestForm(LoginSetting $login_setting)
    {
        $form = new ModalForm(System::get_system_values());
        $form->action(route('exment.logintest_form', ['id' => $login_setting->id]));
        $form->disableReset();

        $form->description(exmtrans('login.message.login_test_description'));

        $form->text('username', trans('admin.username'))->required();
        $form->password('password', trans('admin.password'))->required();

        $form->textarea('resultarea', exmtrans('common.execute_result'))
            ->attribute(['readonly' => true])
            ->rows(4)
        ;

        $form->setWidth(10, 2);


        return $form;
    }
    
    /**
     * Execute login test
     *
     * @param Request $request
     * @return void
     */
    public static function loginTest(Request $request, $login_setting)
    {
        $message = null;
        $result = false;
        $credentials = $request->only(['username', 'password']);

        $username = static::getLdapUserName($credentials['username'], $login_setting);

        $custom_login_user = null;
        try {
            $ad = new \Adldap\Adldap(static::getLdapConfig($login_setting));
            $provider = $ad->getDefaultProvider();
            
            $provider->connect();
            if (!$provider->auth()->attempt($username, $credentials['password'], true)) {
                $message = static::getLoginTestResult(false, [exmtrans('error.login_failed')]);
            } else {
                $ldapUser = static::syncLdapUserArray($provider, $login_setting, $username);

                $custom_login_user = LdapUser::with($login_setting, $ldapUser);
                
                if(!is_nullorempty($custom_login_user->mapping_errors)){
                    $message = LoginService::getLoginTestResult(false, $custom_login_user->mapping_errors);
                }
                else{
                    $validator = LoginService::validateCustomLoginSync($custom_login_user->getValidateArray());
                    if ($validator->fails()) {
                        $message = LoginService::getLoginTestResult(false, $validator->errors());
                    } else {
                        $message = LoginService::getLoginTestResult(true, [], $custom_login_user);
                        $result = true;
                    }
                }
            }
            
            //return $this->executeLogin($request, $custom_login_user);
        } catch (\Adldap\Auth\BindException $ex) {
            \Log::error($ex);
            $message = LoginService::getLoginTestResult(false, [exmtrans('login.sso_provider_error')]);
        } catch (\Exception $ex) {
            \Log::error($ex);
            $message = LoginService::getLoginTestResult(false, [$ex]);
        }
        
        return getAjaxResponse([
            'result' => $result,
            'reload' => false,
            'keepModal' => true,
            'messages' => [
                'resultarea' => [
                    'type' => 'input',
                    'message' => $message,
                ],
            ],
        ]);
    }
}
