<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Form\Widgets\InfoBox;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Carbon\Carbon;

class SystemController extends AdminControllerBase
{
    use RoleForm, InitializeFormTrait;
    
    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("system.header"), exmtrans("system.header"), exmtrans("system.system_description"), 'fa-cogs');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $form = $this->getInitializeForm('system', false, true);
        $form->action(admin_url('system'));

        // Role Setting
        $this->addRoleForm($form, RoleType::SYSTEM);

        $content->row(new Box(trans('admin.edit'), $form));

        if (System::outside_api()) {
            // Version infomation
            $infoBox = $this->getVersionBox();
            $content->row(new Box(exmtrans("system.version_header"), $infoBox->render()));
        }

        if (boolval(config('exment.login_use_2factor', false))) {
            $box = $this->get2factorSettingBox();
            $content->row(new Box(exmtrans("2factor.2factor"), $box->render()));
        }

        return $content;
    }

    
    /**
     * get 2factor setting box.
     *
     * @return Content
     */
    protected function get2factorSettingBox()
    {
        $form = new WidgetForm(System::get_system_values(['2factor']));
        $form->action(admin_urls('system/2factor'));
        $form->disableReset();

        $form->description(exmtrans("2factor.message.description", getManualUrl('login_2factor_setting')));

        $form->switchbool('login_use_2factor', exmtrans("2factor.login_use_2factor"))
            ->help(exmtrans("2factor.help.login_use_2factor"))
            ->attribute(['data-filtertrigger' =>true]);

        $form->select('login_2factor_provider', exmtrans("2factor.login_2factor_provider"))
            ->options(Login2FactorProviderType::transKeyArray('2factor.2factor_provider_options'))
            ->config('allowClear', false)
            ->default(Login2FactorProviderType::EMAIL)
            ->help(exmtrans("2factor.help.login_2factor_provider"))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1'])]);

        $form->ajaxButton('login_2factor_verify_button', exmtrans("2factor.submit_verify_code"))
            ->help(exmtrans("2factor.help.submit_verify_code"))
            ->url(admin_urls('system', '2factor-verify'))
            ->button_class('btn-sm btn-info')
            ->button_label(exmtrans('2factor.submit_verify_code'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1'])]);

        $form->text('login_2factor_verify_code', exmtrans("2factor.login_2factor_verify_code"))
            ->required()
            ->help(exmtrans("2factor.help.login_2factor_verify_code"))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1'])]);

        return $form;
    }


    /**
     * get exment version infoBox.
     *
     * @return Content
     */
    protected function getVersionBox()
    {
        list($latest, $current) = getExmentVersion();
        $version = checkLatestVersion();
        $showLink = false;

        if ($version == SystemVersion::ERROR) {
            $message = exmtrans("system.version_error");
            $icon = 'warning';
            $bgColor = 'red';
            $current = '---';
        } elseif ($version == SystemVersion::DEV) {
            $message = exmtrans("system.version_develope");
            $icon = 'legal';
            $bgColor = 'olive';
        } elseif ($version == SystemVersion::LATEST) {
            $message = exmtrans("system.version_latest");
            $icon = 'check-square';
            $bgColor = 'blue';
        } else {
            $message = exmtrans("system.version_old") . '(' . $latest . ')';
            $showLink = true;
            $icon = 'arrow-circle-right';
            $bgColor = 'aqua';
        }
        
        // Version infomation
        $infoBox = new InfoBox(
            exmtrans("system.current_version") . $current,
            $icon,
            $bgColor,
            getManualUrl('update'),
            $message
        );
        $class = $infoBox->getAttributes()['class'];
        $infoBox
            ->class(isset($class)? $class . ' box-version': 'box-version')
            ->showLink($showLink)
            ->target('_blank');
        if ($showLink) {
            $infoBox->linkText(exmtrans("system.update_guide"));
        }

        return $infoBox;
    }

    /**
     * Send data
     * @param Request $request
     */
    public function post(Request $request)
    {
        DB::beginTransaction();
        try {
            // get permission_available before save
            $permission_available = System::permission_available();

            $result = $this->postInitializeForm($request, ['initialize', 'system']);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            // Set Role
            if ($permission_available) {
                Role::roleLoop(RoleType::SYSTEM, function ($role, $related_type) use ($request) {
                    $values = $request->input($role->getRoleName($related_type)) ?? [];
                    // array_filter
                    $values = array_filter($values, function ($k) {
                        return isset($k);
                    });
                    if (!isset($values)) {
                        $values = [];
                    }
    
                    // get DB system_authoritable values
                    $dbValues = DB::table(SystemTableName::SYSTEM_AUTHORITABLE)
                        ->where('related_type', $related_type)
                        ->where('morph_type', RoleType::SYSTEM()->lowerKey())
                        ->where('role_id', $role->id)
                        ->get(['related_id']);
                    foreach ($values as $value) {
                        if (!isset($value)) {
                            continue;
                        }
                        /// not exists db value, insert
                        if (!$dbValues->first(function ($dbValue, $k) use ($value) {
                            return $dbValue->related_id == $value;
                        })) {
                            DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->insert(
                                [
                                'related_id' => $value,
                                'related_type' => $related_type,
                                'morph_id' => null,
                                'morph_type' => RoleType::SYSTEM()->lowerKey(),
                                'role_id' => $role->id,
                            ]
                        );
                        }
                    }
    
                    ///// Delete if not exists value
                    foreach ($dbValues as $dbValue) {
                        if (!collect($values)->first(function ($value, $k) use ($dbValue) {
                            return $dbValue->related_id == $value;
                        })) {
                            DB::table(SystemTableName::SYSTEM_AUTHORITABLE)
                            ->where('related_id', $dbValue->related_id)
                            ->where('related_type', $related_type)
                            ->where('morph_type', RoleType::SYSTEM()->lowerKey())
                            ->where('role_id', $role->id)
                            ->delete();
                        }
                    }
                });
            }

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('system'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * Send data
     * @param Request $request
     */
    public function post2factor(Request $request)
    {
        $login_2factor_verify_code = $request->get('login_2factor_verify_code');
        if (boolval($request->get('login_use_2factor'))) {
            // check verifyCode
            if (!Auth2factorService::verifyCode('system', $login_2factor_verify_code)) {
                // error
                return back()->withInput()->withErrors([
                    'login_2factor_verify_code' => exmtrans('2factor.message.verify_failed')
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $inputs = $request->all(System::get_system_keys(['2factor']));
            
            // set system_key and value
            foreach ($inputs as $k => $input) {
                System::{$k}($input);
            }

            DB::commit();

            if (isset($login_2factor_verify_code)) {
                Auth2factorService::deleteCode('system', $login_2factor_verify_code);
            }

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('system'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * 2factor verify
     *
     * @return void
     */
    public function auth_2factor_verify()
    {
        $loginuser = \Admin::user();

        // set 2factor params
        $verify_code = random_int(100000, 999999);
        $valid_period_datetime = Carbon::now()->addMinute(60);
        
        // send verify
        try{
            if (!Auth2factorService::addAndSendVerify('system', $verify_code, $valid_period_datetime, MailKeyName::VERIFY_2FACTOR_SYSTEM, [
                'verify_code' => $verify_code,
                'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
            ])) {
                // show warning message
                return getAjaxResponse([
                    'result'  => false,
                    'toastr' => exmtrans('error.mailsend_failed'),
                    'reload' => false,
                ]);
            }    
        }catch(NoMailTemplateException $ex){
            // show warning message
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('error.no_mail_template'),
                'reload' => false,
            ]);
        }
        // throw mailsend Exception
        catch (\Swift_TransportException $ex) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('error.mailsend_failed'),
                'reload' => false,
            ]);
        }

        // set session for 2factor
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

        return getAjaxResponse([
            'result'  => true,
            'toastr' => exmtrans('common.message.sendmail_succeeded'),
            'reload' => false,
        ]);
    }
}
