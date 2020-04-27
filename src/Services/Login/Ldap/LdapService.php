<?php
namespace Exceedone\Exment\Services\Login\Ldap;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Form\Tools;
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

    public static function syncLdapUser($provider, LoginSetting $login_setting, $username)
    {
        return $provider->search()->users()->findBy($login_setting->getOption('ldap_search_key'), $username);
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

    

    public static function setLdapForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::LDAP)) {
            $form->description($errors[LoginType::LDAP])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
            return;
        }

        
        if (!isset($login_setting)) {
            $form->text('ldap_name', exmtrans('login.ldap_name'))
            ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'))
            ->required()
            ->rules(["max:30", "regex:/".Define::RULES_REGEX_SYSTEM_NAME."/", new \Exceedone\Exment\Validator\SamlNameUniqueRule])
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        } else {
            $form->display('ldap_name_text', exmtrans('login.ldap_name'))->default(function () use ($login_setting) {
                return $login_setting->getOption('ldap_name');
            });
            $form->hidden('ldap_name');
        }

        $form->text('ldap_hosts', exmtrans('login.ldap_hosts'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_port', exmtrans('login.ldap_port'))
        ->required()
        ->rules('numeric|nullable')
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_base_dn', exmtrans('login.ldap_base_dn'))
        ->help(exmtrans('login.help.ldap_base_dn'))
        ->default('dc=example,dc=co,dc=jp')
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_search_key', exmtrans('login.ldap_search_key'))
        ->help(exmtrans('login.help.ldap_search_key'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_account_prefix', exmtrans('login.ldap_account_prefix'))
        ->help(exmtrans('login.help.ldap_account_prefix'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_account_suffix', exmtrans('login.ldap_account_suffix'))
        ->help(exmtrans('login.help.ldap_account_suffix'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->switchbool('ldap_use_ssl', exmtrans('login.ldap_use_ssl'))
        ->default(false);

        $form->switchbool('ldap_use_tls', exmtrans('login.ldap_use_tls'))
        ->default(false);
    }


    
    /**
     * Execute login test
     *
     * @param Request $request
     * @return void
     */
    public static function loginTest(Request $request, $login_setting)
    {
        list($result, $message) = static::getLoginResultAndMessage($request, $login_setting);
        
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

    protected static function getLoginResultAndMessage(Request $request, $login_setting){
        $credentials = $request->only(['username', 'password']);

        $username = static::getLdapUserName($credentials['username'], $login_setting);

        $custom_login_user = null;
        try {
            $ad = new \Adldap\Adldap(static::getLdapConfig($login_setting));
            $provider = $ad->getDefaultProvider();
            
            $provider->connect();
            if (!$provider->auth()->attempt($username, $credentials['password'], true)) {
                return [false, LoginService::getLoginTestResult(false, [exmtrans('error.login_failed')])];
            }

            $ldapUser = static::syncLdapUser($provider, $login_setting, $username);

            if(!$ldapUser){
                return [false, LoginService::getLoginTestResult(false, [exmtrans('error.login_failed')])];
            }

            $custom_login_user = LdapUser::with($login_setting, $ldapUser);
            
            if(!is_nullorempty($custom_login_user->mapping_errors)){
                return [false, LoginService::getLoginTestResult(false, $custom_login_user->mapping_errors)];
            }
            
            $validator = LoginService::validateCustomLoginSync($custom_login_user->mapping_values);
            if ($validator->fails()) {
                return [false, LoginService::getLoginTestResult(false, $validator->errors(), $custom_login_user)];
            }

            return [true, LoginService::getLoginTestResult(true, [], $custom_login_user)];
        } catch (\Adldap\Auth\BindException $ex) {
            \Log::error($ex);

            return [false, LoginService::getLoginTestResult(false, [exmtrans('login.sso_provider_error')])];
        } catch (\Exception $ex) {
            \Log::error($ex);

            return [false, LoginService::getLoginTestResult(false, [$ex])];
        }
    }
    
    public static function appendActivateSwalButton($tools, LoginSetting $login_setting){
        return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
        // if (!$login_setting->active_flg) {
        //     // Show an error if other LDAP services are enabled.
        //     $ldap_setting = LoginSetting::getLdapSetting();
        //     if($ldap_setting && $ldap_setting->id != $login_setting->id){
        //         $tools->append(new Tools\SwalInputButton([
        //             'label' => exmtrans('common.activate'),
        //             'type' => 'error',
        //             'icon' => 'fa-check-circle',
        //             'btn_class' => 'btn-success',
        //             'showCancelButton' => false,
        //             'title' => exmtrans('common.activate'),
        //             'text' => exmtrans('login.help.activate_ldap_error'),
        //         ]));
        //         return;
        //     }
        //     $tools->append(new Tools\SwalInputButton([
        //         'url' => route('exment.login_activate', ['id' => $login_setting->id]),
        //         'label' => exmtrans('common.activate'),
        //         'icon' => 'fa-check-circle',
        //         'btn_class' => 'btn-success',
        //         'title' => exmtrans('common.activate'),
        //         'text' => exmtrans('login.help.activate'),
        //         'method' => 'post',
        //         'redirectUrl' => admin_urls("login_setting", $login_setting->id, "edit"),
        //     ]));
        // } else {
        //     return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
        // }
    }
}
