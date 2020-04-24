<?php
namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Auth\CustomLoginUser;
use Exceedone\Exment\Form\Widgets\ModalForm;
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
class LoginService
{
    public static function setToken(CustomLoginUserBase $custom_login_user)
    {
        if($custom_login_user != LoginType::OAUTH){
            return;
        }

        // set session access key
        session([Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN => [
            'access_token' => $custom_login_user->token,
            'refresh_token' => $custom_login_user->refreshToken,
            'provider' => $custom_login_user->provider_name,
            'expiresIn' =>  $custom_login_user->expiresIn,
        ]]);
    }

    /**
     * Get access and refresh token
     *
     * @return void
     */
    public static function getToken()
    {
        $session = session(Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN);
        return [
            'access_token' => array_get($session, 'access_token'),
            'refresh_token' => array_get($session, 'refresh_token'),
            'provider' => array_get($session, 'provider')
        ];
    }

    /**
     * Get access token
     *
     * @return void
     */
    public static function getAccessToken()
    {
        return static::getToken()['access_token'];
    }
    
    /**
     * Get refresh token
     *
     * @return void
     */
    public static function getRefreshToken()
    {
        return static::getToken()['refresh_token'];
    }

    /**
     * Get custom login validator for synced user.
     *
     * @param array $array
     * @return void
     */
    public static function validateCustomLoginSync(array $data){
       return \Validator::make($data, [
            'user_code' => 'required',
            'user_name' => 'required',
            'email' => 'required|email',
            'id' => 'required',
        ]);
    }
    

    public static function getLoginTestResult(bool $success, $messages, $custom_login_user = null){
        $message = [];

        $message[] = $success ? exmtrans('common.message.success_execute') : exmtrans('common.message.error_execute');

        if(is_array($messages)){
            $message = array_merge($message, $messages);
        }
        elseif($messages instanceof \Illuminate\Support\MessageBag){
            $message = array_merge($message, collect($messages->messages())->map(function($m){
                return implode(" ", $m);
            })->toArray());
        }

        if($custom_login_user){
            $keys = [
                'user_code',
                'user_name',
                'email',
                'id',
            ];
    
            foreach($keys as $key){
                $message[] = exmtrans("user.$key") . ' : ' . $custom_login_user->{$key};
            }
        }

        return implode("\r\n", $message);
    }

    /**
     * Get test form for sso
     *
     * @param LoginSetting $login_setting
     * @return void
     */
    public static function getTestFormSso(LoginSetting $login_setting)
    {
        $form = new ModalForm();
        $form->action(route('exment.logintest_form', ['id' => $login_setting->id]));
        $form->disableReset();
        $form->disableSubmit();

        $form->description(exmtrans('login.message.login_test_description'));

        $form->url('login_test_redirect', exmtrans('login.login_test_redirect'))
        ->readonly()
        ->setElementClass(['copyScript'])
        ->default($login_setting->exment_callback_url_test)
        ->help(exmtrans('login.help.login_test_sso'));


        // get message from session
        $message = session()->pull(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);
        $form->textarea('resultarea', exmtrans('common.execute_result'))
            ->attribute(['readonly' => true])
            ->default($message)
            ->rows(4)
        ;

        $url = route('exment.logintest_sso', ['id' => $login_setting->id]);
        $form->html("<a href='{$url}' data-nopjax data-modalclose='false' class='btn btn-primary'>" . trans('admin.login') . "</a>");

        $form->setWidth(10, 2);


        return $form;
    }
    
}
