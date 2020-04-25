<?php
namespace Exceedone\Exment\Services\Login\Saml;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Illuminate\Http\Request;

/**
 * LoginService
 */
class SamlService implements LoginServiceInterface
{
    public static function getTestForm(LoginSetting $login_setting)
    {
        return LoginService::getTestFormSso($login_setting);
    }
    

    
    public static function setSamlForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::SAML)) {
            $form->description($errors[LoginType::SAML])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

            return;
        }
        
        if (!isset($login_setting)) {
            $form->text('saml_name', exmtrans('login.saml_name'))
            ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'))
            ->required()
            ->rules(["max:30", "regex:/".Define::RULES_REGEX_SYSTEM_NAME."/", new \Exceedone\Exment\Validator\SamlNameUniqueRule])
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        } else {
            $form->display('saml_name_text', exmtrans('login.saml_name'))->default(function () use ($login_setting) {
                return $login_setting->getOption('saml_name');
            });
            $form->hidden('saml_name');
        }

        $form->exmheader(exmtrans('login.saml_idp'))->hr()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->text('saml_idp_entityid', exmtrans('login.saml_idp_entityid'))
        ->help(exmtrans('login.help.saml_idp_entityid'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->url('saml_idp_sso_url', exmtrans('login.saml_idp_sso_url'))
        ->help(exmtrans('login.help.saml_idp_sso_url'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->url('saml_idp_ssout_url', exmtrans('login.saml_idp_ssout_url'))
        ->help(exmtrans('login.help.saml_idp_ssout_url'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->textarea('saml_idp_x509', exmtrans('login.saml_idp_x509'))
        ->help(exmtrans('login.help.saml_idp_x509'))
        ->rows(4)
        ->customFormat(function($value){
            return trydecrypt($value);
        })
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        

        $form->exmheader(exmtrans('login.saml_sp'))->hr()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->select('saml_sp_name_id_format', exmtrans('login.saml_sp_name_id_format'))
        ->help(exmtrans('login.help.saml_sp_name_id_format'))
        ->required()
        ->options(Define::SAML_NAME_ID_FORMATS)
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->text('saml_sp_entityid', exmtrans('login.saml_sp_entityid'))
        ->help(exmtrans('login.help.saml_sp_entityid'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->textarea('saml_sp_x509', exmtrans('login.saml_sp_x509'))
        ->help(exmtrans('login.help.saml_sp_x509'))
        ->rows(4)
        ->customFormat(function($value){
            return trydecrypt($value);
        })
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->textarea('saml_sp_privatekey', exmtrans('login.saml_sp_privatekey'))
        ->help(exmtrans('login.help.saml_privatekey'))
        ->rows(4)
        ->customFormat(function($value){
            return trydecrypt($value);
        })
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        

        $form->exmheader(exmtrans('login.saml_option'))->hr()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->switchbool('saml_option_name_id_encrypted', exmtrans("login.saml_option_name_id_encrypted"))
        ->help(exmtrans("login.help.saml_option_name_id_encrypted"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->switchbool('saml_option_authn_request_signed', exmtrans("login.saml_option_authn_request_signed"))
        ->help(exmtrans("login.help.saml_option_authn_request_signed"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->switchbool('saml_option_logout_request_signed', exmtrans("login.saml_option_logout_request_signed"))
        ->help(exmtrans("login.help.saml_option_logout_request_signed"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->switchbool('saml_option_logout_response_signed', exmtrans("login.saml_option_logout_response_signed"))
        ->help(exmtrans("login.help.saml_option_logout_response_signed"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
    }


    /**
     * Execute login test
     *
     * @param Request $request
     * @return void
     */
    public static function loginTest(Request $request, $login_setting)
    {
        $saml2Auth = LoginSetting::getSamlAuth($login_setting, true);
        $saml2Auth->login();    
    }

    
    /**
     * Execute login test callback
     *
     * @param Request $request
     * @return void
     */
    public static function loginTestCallback(Request $request, $login_setting)
    {
        $saml2Auth = LoginSetting::getSamlAuth($login_setting, true);

        $errors = $saml2Auth->acs();
        if (!empty($errors)) {
            return LoginService::getLoginTestResult(false, array_get($errors, 'last_error_reason', array_get($errors, 'error')));
        }

        $custom_login_user = SamlUser::with($login_setting->provider_name, $saml2Auth->getSaml2User());
        
        if(!is_nullorempty($custom_login_user->mapping_errors)){
            return LoginService::getLoginTestResult(false, $custom_login_user->mapping_errors);
        }

        $validator = LoginService::validateCustomLoginSync($custom_login_user->mapping_values);
        if ($validator->fails()) {
            return LoginService::getLoginTestResult(false, $validator->errors());
        }
        
        return LoginService::getLoginTestResult(true, [], $custom_login_user);
    }

    public static function appendActivateSwalButton($tools, LoginSetting $login_setting){
        return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
    }
}
