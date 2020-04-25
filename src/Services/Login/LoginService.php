<?php
namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\LoginSetting;
use Encore\Admin\Form;

/**
 * LoginService
 */
class LoginService
{
    public static function setToken(CustomLoginUserBase $custom_login_user)
    {
        if ($custom_login_user != LoginType::OAUTH) {
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
    public static function validateCustomLoginSync(array $data)
    {
        return \Validator::make($data, [
            'user_code' => 'required',
            'user_name' => 'required',
            'email' => 'required|email',
        ]);
    }
    

    public static function getLoginTestResult(bool $success, $messages, $custom_login_user = null)
    {
        $message = [];

        $message[] = $success ? exmtrans('common.message.success_execute') : exmtrans('common.message.error_execute');

        if (is_array($messages)) {
            $message = array_merge($message, $messages);
        } elseif ($messages instanceof \Illuminate\Support\MessageBag) {
            $message = array_merge($message, collect($messages->messages())->map(function ($m) {
                return implode(" ", $m);
            })->toArray());
        }

        if ($custom_login_user) {
            $keys = [
                'user_code',
                'user_name',
                'email',
                'id',
            ];
    
            foreach ($keys as $key) {
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
        ->help(exmtrans('login.help.login_test_sso', ['login_type' => LoginType::getEnum($login_setting->login_type)->transKey('login.login_type_options')]));


        // get message from session
        $message = session()->pull(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);
        $form->textarea('resultarea', exmtrans('common.execute_result'))
            ->attribute(['readonly' => true])
            ->default($message)
            ->rows(4)
        ;

        $url = route('exment.logintest_sso', ['id' => $login_setting->id]);
        $form->html("<a href='{$url}' data-nopjax data-modalclose='false' class='btn btn-primary click_disabled'>" . trans('admin.login') . "</a>");

        $form->setWidth(10, 2);


        return $form;
    }
    
    public static function appendActivateSwalButtonSso($tools, LoginSetting $login_setting){
        if (!$login_setting->active_flg) {
            $tools->append(new Tools\SwalInputButton([
                'url' => route('exment.login_activate', ['id' => $login_setting->id]),
                'label' => exmtrans('common.activate'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-success',
                'title' => exmtrans('common.activate'),
                'text' => exmtrans('login.help.activate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("login_setting", $login_setting->id, "edit"),
            ]));
        } else {
            $tools->append(new Tools\SwalInputButton([
                'url' => route('exment.login_deactivate', ['id' => $login_setting->id]),
                'label' => exmtrans('common.deactivate'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-default',
                'title' => exmtrans('common.deactivate'),
                'text' => exmtrans('login.help.deactivate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("login_setting", $login_setting->id, "edit"),
            ]));
        }
    }
}
