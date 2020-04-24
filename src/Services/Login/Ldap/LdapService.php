<?php
namespace Exceedone\Exment\Services\Login\Ldap;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Auth\CustomLoginUser;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Services\Login\Ldap\LdapService;
use Exceedone\Exment\Services\Login\Ldap\LdapUser;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Providers\CustomUserProvider;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request as Req;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

/**
 * LoginService
 */
class LdapService implements LoginServiceInterface
{
    
    public static function getLdapConfig(LoginSetting $login_setting){
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

        return ((isset($prefix) && strpos($username, $prefix) !== 0) ? $prefix : '' ).
        $username . 
        ((isset($suffix) && strripos($username, $suffix) !== (mb_strlen($username) - mb_strlen($suffix))) ? $suffix : '');
    }

    public static function syncLdapUserArray($provider, LoginSetting $login_setting, $username)
    {
        $ldapuser = $provider->search()->findBy($login_setting->getOption('ldap_search_key'), $username);

        if(!isset($ldapuser)){
            return false;
        }

        $keys = [
            'mapping_user_code' => 'user_code',
            'mapping_user_name' => 'user_name',
            'mapping_email' => 'email',
        ];
        $attrs = [];
        foreach($keys as $option_keyname => $local_attr){
            $ldap_attrs = $login_setting->getOption($option_keyname);

            foreach(stringToArray($ldap_attrs) as $ldap_attr){
                $method = 'get' . $ldap_attr;
                if (method_exists($ldapuser, $method)) {
                    $attrs[$local_attr] = $ldapuser->$method();
                    break;
                }
    
                if (!isset($ldapuser_attrs)) {
                    $ldapuser_attrs = self::accessProtected($ldapuser, 'attributes');
                }
    
                if (!isset($ldapuser_attrs[$ldap_attr])) {
                    // an exception could be thrown
                    continue;
                }
    
                if (!is_array($ldapuser_attrs[$ldap_attr])) {
                    $attrs[$local_attr] = $ldapuser_attrs[$ldap_attr];
                    break;
                }
    
                if (count($ldapuser_attrs[$ldap_attr]) == 0) {
                    // an exception could be thrown
                    continue;
                }
    
                // now it returns the first item, but it could return
                // a comma-separated string or any other thing that suits you better
                $attrs[$local_attr] = $ldapuser_attrs[$ldap_attr][0];
                break;
            }
        }

        return $attrs;
    }
    
    protected static function accessProtected($obj, $prop)
    {
        $reflection = new \ReflectionClass($obj);
        $property = $reflection->getProperty($prop);
        $property->setAccessible(true);
        return $property->getValue($obj);
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
    public static function loginTest(Request $request, $login_setting){
        $message = null;
        $result = false;
        $credentials = $request->only(['username', 'password']);

        $username = static::getLdapUserName($credentials['username'], $login_setting);

        $custom_login_user = null;
        try {
            $ad = new \Adldap\Adldap(static::getLdapConfig($login_setting));
            $provider = $ad->getDefaultProvider();
            
            $provider->connect();
            if(!$provider->auth()->attempt($username, $credentials['password'], true)){
                $message = static::getLoginTestResult(false, [exmtrans('error.login_failed')]);
            }
            else{
                $ldapUserArray = static::syncLdapUserArray($provider, $login_setting, $username);

                $custom_login_user = LdapUser::with($login_setting, $ldapUserArray);
                    
                $validator = LoginService::validateCustomLoginSync($custom_login_user->getValidateArray());
                if($validator->fails()){
                    $message = LoginService::getLoginTestResult(false, $validator->errors());
                }
                else{
                    $message = LoginService::getLoginTestResult(true, [], $custom_login_user);
                    $result = true;
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
