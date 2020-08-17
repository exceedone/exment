<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\JoinedMultiUserFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Widgets\InfoBox;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Validator;

class SystemController extends AdminControllerBase
{
    use InitializeFormTrait;
    
    public function __construct()
    {
        $this->setPageInfo(exmtrans("system.header"), exmtrans("system.system_header"), exmtrans("system.system_description"), 'fa-cogs');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        if ($request->has('advanced')) {
            return $this->formAdvanced($request, $content);
        }

        return $this->formBasic($request, $content);
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    protected function formBasic(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $form = $this->getInitializeForm('system', false);
        $form->action(admin_url('system'));

        $admin_users = System::system_admin_users();
        $form->multipleSelect('system_admin_users', exmtrans('system.system_admin_users'))
            ->help(exmtrans('system.help.system_admin_users'))
            ->required()
            ->ajax(CustomTable::getEloquent(SystemTableName::USER)->getOptionAjaxUrl())
            ->options(function ($option) use ($admin_users) {
                return CustomTable::getEloquent(SystemTableName::USER)->getSelectOptions([
                    'selected_value' => $admin_users,
                ]);
            })->default($admin_users);

        $box = new Box(trans('admin.edit'), $form);
        
        $box->tools(new Tools\SystemChangePageMenu());

        $content->row($box);

        if (System::outside_api()) {
            // Version infomation
            $infoBox = $this->getVersionBox();
            $content->row(new Box(exmtrans("system.version_header"), $infoBox->render()));
        }

        return $content;
    }

    /**
     * index advanced setting
     *
     * @param Request $request
     * @param Content $content
     * @return Content
     */
    protected function formAdvanced(Request $request, Content $content)
    {
        $this->AdminContent($content);

        $form = new WidgetForm(System::get_system_values(['advanced']));
        $form->disableReset();
        $form->action(admin_url('system'));
        
        $form->hidden('advanced')->default(1);
        $form->ignore('advanced');

        $form->select('grid_pager_count', exmtrans("system.grid_pager_count"))
        ->options(getPagerOptions())
        ->config('allowClear', false)
        ->default(20)
        ->help(exmtrans("system.help.grid_pager_count"));
            
        $form->select('datalist_pager_count', exmtrans("system.datalist_pager_count"))
            ->options(getPagerOptions(false, Define::PAGER_DATALIST_COUNTS))
            ->config('allowClear', false)
            ->default(5)
            ->help(exmtrans("system.help.datalist_pager_count"));
        
        $form->select('default_date_format', exmtrans("system.default_date_format"))
            ->options(getTransArray(Define::SYSTEM_DATE_FORMAT, "system.date_format_options"))
            ->config('allowClear', false)
            ->default('format_default')
            ->help(exmtrans("system.help.default_date_format"));

        $form->select('filter_search_type', exmtrans("system.filter_search_type"))
            ->default(FilterSearchType::FORWARD)
            ->options(FilterSearchType::transArray("system.filter_search_type_options"))
            ->config('allowClear', false)
            ->required()
            ->help(exmtrans("system.help.filter_search_type"));

        $form->checkbox('grid_filter_disable_flg', exmtrans("system.grid_filter_disable_flg"))
            ->options(function () {
                return collect(SystemColumn::transArray("common"))->filter(function ($value, $key) {
                    return boolval(array_get(SystemColumn::getOption(['name' => $key]), 'grid_filter', false));
                })->toArray();
            })
            ->help(exmtrans("system.help.grid_filter_disable_flg"));

        $form->display('max_file_size', exmtrans("common.max_file_size"))
        ->default(Define::FILE_OPTION()['maxFileSizeHuman'])
        ->help(exmtrans("common.help.max_file_size", getManualUrl('quickstart_more#' . exmtrans('common.help.max_file_size_link'))));
        
        if (boolval(System::organization_available())) {
            $form->exmheader(exmtrans('system.organization_header'))->hr();

            $manualUrl = getManualUrl('organization');
            $form->select('org_joined_type_role_group', exmtrans("system.org_joined_type_role_group"))
                ->help(exmtrans("system.help.org_joined_type_role_group") . exmtrans("common.help.more_help_here", $manualUrl))
                ->options(JoinedOrgFilterType::transKeyArray('system.joined_org_filter_role_group_options'))
                ->config('allowClear', false)
                ->default(JoinedOrgFilterType::ALL)
                ;

            $form->select('org_joined_type_custom_value', exmtrans("system.org_joined_type_custom_value"))
                ->help(exmtrans("system.help.org_joined_type_custom_value") . exmtrans("common.help.more_help_here", $manualUrl))
                ->options(JoinedOrgFilterType::transKeyArray('system.joined_org_filter_custom_value_options'))
                ->config('allowClear', false)
                ->default(JoinedOrgFilterType::ONLY_JOIN)
                ;

            $form->select('custom_value_save_autoshare', exmtrans("system.custom_value_save_autoshare"))
                ->help(exmtrans("system.help.custom_value_save_autoshare") . exmtrans("common.help.more_help_here", $manualUrl))
                ->options(CustomValueAutoShare::transKeyArray('system.custom_value_save_autoshare_options'))
                ->config('allowClear', false)
                ->default(CustomValueAutoShare::USER_ONLY)
                ;
        }

        $manualUrl = getManualUrl('multiuser');
        $form->select('filter_multi_user', exmtrans(boolval(System::organization_available()) ? "system.filter_multi_orguser" : "system.filter_multi_user"))
            ->help(exmtrans("system.help.filter_multi_orguser") . exmtrans("common.help.more_help_here", $manualUrl))
            ->options(JoinedMultiUserFilterType::getOptions())
            ->config('allowClear', false)
            ->default(JoinedMultiUserFilterType::NOT_FILTER)
        ;

        

        // View and dashbaord ----------------------------------------------------
        $form->exmheader(exmtrans('system.view_dashboard_header'))->hr();

        $form->switchbool('userdashboard_available', exmtrans("system.userdashboard_available"))
            ->default(0)
            ->help(exmtrans("system.help.userdashboard_available"));

        $form->switchbool('userview_available', exmtrans("system.userview_available"))
            ->default(0)
            ->help(exmtrans("system.help.userview_available"));


        // use mail setting
        if (!boolval(config('exment.mail_setting_env_force', false))) {
            $form->exmheader(exmtrans('system.system_mail'))->hr();

            $form->description(exmtrans("system.help.system_mail"));

            $form->text('system_mail_host', exmtrans("system.system_mail_host"));

            $form->text('system_mail_port', exmtrans("system.system_mail_port"));

            $form->text('system_mail_encryption', exmtrans("system.system_mail_encryption"))
                ->help(exmtrans("system.help.system_mail_encryption"));
                
            $form->text('system_mail_username', exmtrans("system.system_mail_username"));

            $form->password('system_mail_password', exmtrans("system.system_mail_password"));
            
            $form->email('system_mail_from', exmtrans("system.system_mail_from"))
                ->help(exmtrans("system.help.system_mail_from"));
        }
       
        $form->exmheader(exmtrans('system.ip_filter'))->hr();
        $form->description(exmtrans("system.help.ip_filter"));

        $form->textarea('web_ip_filters', exmtrans('system.web_ip_filters'))->rows(3);
        $form->textarea('api_ip_filters', exmtrans('system.api_ip_filters'))->rows(3);

        $box = new Box(exmtrans('common.detail_setting'), $form);

        $box->tools(new Tools\SystemChangePageMenu());

        $content->row($box);

        // sendmail test
        $box = $this->getsendmailTestBox();
        $content->row(new Box(exmtrans("system.submit_test_mail"), $box->render()));

        return $content;
    }

    /**
     * get sendmail test box.
     *
     * @return Content
     */
    protected function getsendmailTestBox()
    {
        $form = new WidgetForm();
        $form->action(admin_urls('system/2factor'));
        $form->disableReset();
        $form->disableSubmit();

        $form->description(exmtrans('system.help.test_mail'));

        $form->email('test_mail_to', exmtrans("system.test_mail_to"));

        $form->ajaxButton('test_mail_send_button', exmtrans("system.submit_test_mail"))
            ->url(admin_urls('system', 'send_testmail'))
            ->button_class('btn-sm btn-info')
            ->attribute(['data-senddata' => json_encode(['test_mail_to'])])
            ->button_label(exmtrans('system.submit_test_mail'))
            ->send_params('test_mail_to');

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
            $advanced = $request->has('advanced');

            $result = $this->postInitializeForm($request, ($advanced ? ['advanced'] : ['initialize', 'system']), false, !$advanced);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            // Set Role
            if (!$advanced) {
                System::system_admin_users($request->get('system_admin_users'));
            }

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('system') . ($advanced ? '?advanced=1' : ''));
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * send test mail
     *
     * @return void
     */
    public function sendTestMail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'test_mail_to' => 'required|email',
        ]);
        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        setTimeLimitLong();
        $test_mail_to = $request->get('test_mail_to');

        try {
            NotifyService::executeTestNotify([
                'type' => 'mail',
                'to' => $test_mail_to,
            ]);

            return getAjaxResponse([
                'result'  => true,
                'toastr' => exmtrans('common.message.sendmail_succeeded'),
                'reload' => false,
            ]);
        }
        // throw mailsend Exception
        catch (\Swift_TransportException $ex) {
            \Log::error($ex);

            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('error.mailsend_failed'),
                'reload' => false,
            ]);
        }
    }
}
