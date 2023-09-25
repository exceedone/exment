<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\JoinedMultiUserFilterType;
use Exceedone\Exment\Enums\ShowPositionType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Update\UpdateService;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\SystemRequire;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Enums\SystemRequireCalledType;
use Exceedone\Exment\Enums\SystemRequireResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
            return $this->formAdvancedBox($request, $content);
        }

        return $this->formBasicBox($request, $content);
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    protected function formBasicBox(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $form = $this->formBasic($request);

        $box = new Box(exmtrans('common.basic_setting'), $form);
        $box->tools(new Tools\SystemChangePageMenu());

        $content->row($box);

        if (System::outside_api()) {
            // Version infomation
            $infoBox = $this->getVersionBox();
            $content->row(new Box(exmtrans("system.version_header"), $infoBox->render()));
        }

        // Append system require box
        $box = $this->getSystemRequireBox();
        $content->row(new Box(exmtrans("install.system_require.header"), $box->render()));

        return $content;
    }

    /**
     * Index interface.
     *
     * @param Request $request
     * @return WidgetForm
     */
    protected function formBasic(Request $request): WidgetForm
    {
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
            })
            ->validationOptions(function ($option) use ($admin_users) {
                return CustomTable::getEloquent(SystemTableName::USER)->getSelectOptions([
                    'selected_value' => $admin_users,
                    'all' => true,
                    'notAjax' => true,
                ]);
            })
            ->default($admin_users);

        return $form;
    }

    /**
     * index advanced setting
     *
     * @param Request $request
     * @param Content $content
     * @return Content
     */
    protected function formAdvancedBox(Request $request, Content $content)
    {
        $this->AdminContent($content);

        $form = $this->formAdvanced($request);

        $box = new Box(exmtrans('common.detail_setting'), $form);

        $box->tools(new Tools\SystemChangePageMenu());

        $content->row($box);

        // sendmail test
        $content->row($this->getsendmailTestBox());

        return $content;
    }

    /**
     * index advanced setting
     *
     * @param Request $request
     * @return WidgetForm
     */
    protected function formAdvanced(Request $request): WidgetForm
    {
        $form = new WidgetForm(System::get_system_values(['advanced', 'notify']));
        $form->disableReset();
        $form->action(admin_url('system'));

        $form->progressTracker()->options($this->getProgressInfo(true));

        $form->hidden('advanced')->default(1);
        $form->ignore('advanced');

        $grid_per_pages = stringToArray(config('exment.grid_per_pages'));
        if (empty($grid_per_pages)) {
            $grid_per_pages = Define::PAGER_GRID_COUNTS;
        }

        $form->select('grid_pager_count', exmtrans("system.grid_pager_count"))
        ->options(getPagerOptions(false, $grid_per_pages))
        ->disableClear()
        ->default(20)
        ->help(exmtrans("system.help.grid_pager_count"));

        $form->select('datalist_pager_count', exmtrans("system.datalist_pager_count"))
            ->options(getPagerOptions(false, Define::PAGER_DATALIST_COUNTS))
            ->disableClear()
            ->default(5)
            ->help(exmtrans("system.help.datalist_pager_count"));

        $form->select('default_date_format', exmtrans("system.default_date_format"))
            ->options(getTransArray(Define::SYSTEM_DATE_FORMAT, "system.date_format_options"))
            ->disableClear()
            ->default('format_default')
            ->help(exmtrans("system.help.default_date_format"));

        $form->select('filter_search_type', exmtrans("system.filter_search_type"))
            ->default(FilterSearchType::FORWARD)
            ->options(FilterSearchType::transArray("system.filter_search_type_options"))
            ->disableClear()
            ->required()
            ->help(exmtrans("system.help.filter_search_type"));

        $form->checkbox('grid_filter_disable_flg', exmtrans("system.grid_filter_disable_flg"))
            ->options(function () {
                return collect(SystemColumn::transArray("common"))->filter(function ($value, $key) {
                    return boolval(array_get(SystemColumn::getOption(['name' => $key]), 'grid_filter', false));
                })->toArray();
            })
            ->help(exmtrans("system.help.grid_filter_disable_flg"));

        $form->select('system_values_pos', exmtrans("system.system_values_pos"))
            ->default(ShowPositionType::TOP)
            ->disableClear()
            ->options(ShowPositionType::transKeyArrayFilter("system.system_values_pos_options", ShowPositionType::SYSTEM_SETTINGS()))
            ->help(exmtrans("system.help.system_values_pos"));

        $form->select('data_submit_redirect', exmtrans("system.data_submit_redirect"))
            ->options(Enums\DataSubmitRedirect::transKeyArray("admin", false))
            ->default(Enums\DataSubmitRedirect::LIST)
            ->disableClear()
            ->help(exmtrans("system.help.data_submit_redirect"));

        $form->multipleSelect('header_user_info', exmtrans('system.header_user_info'))
            ->help(exmtrans('system.help.header_user_info'))
            ->config('maximumSelectionLength', 2)
            ->options(function ($option) {
                $options = CustomTable::getEloquent(SystemTableName::USER)->getColumnsSelectOptions([
                    'include_system' => false,
                    'ignore_attachment' => true
                ]);
                $options[SystemColumn::CREATED_AT] = exmtrans('common.created_at');
                return $options;
            });

        $form->exmheader(exmtrans('system.publicform'))->hr();
        $form->switchbool('publicform_available', exmtrans("system.publicform_available"))
            ->default(0)
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("system.help.publicform_available"));

        $form->radio('recaptcha_type', exmtrans('system.recaptcha_type'))
            ->attribute(['data-filter' => json_encode(['key' => 'publicform_available', 'value' => '1'])])
            ->help(exmtrans("system.help.recaptcha_type"))
            ->options([
                '' => exmtrans('common.no_use'),
                'v2' => 'V2',
                'v3' => 'V3',
            ]);

        $form->password('recaptcha_site_key', exmtrans('system.recaptcha_site_key'))
            ->attribute([
                'data-filter' => json_encode([
                    ['key' => 'publicform_available', 'value' => "1"],
                    ['key' => 'recaptcha_type', 'hasValue' => "1"],
                ]),
            ])
            ->help(exmtrans("system.help.recaptcha_site_key"));

        $form->password('recaptcha_secret_key', exmtrans('system.recaptcha_secret_key'))
        ->attribute([
            'data-filter' => json_encode([
                ['key' => 'publicform_available', 'value' => "1"],
                ['key' => 'recaptcha_type', 'hasValue' => "1"],
            ]),
        ])
            ->help(exmtrans("system.help.recaptcha_secret_key"));




        if (boolval(System::organization_available())) {
            $form->exmheader(exmtrans('system.organization_header'))->hr();

            $manualUrl = getManualUrl('organization');
            $form->select('org_joined_type_role_group', exmtrans("system.org_joined_type_role_group"))
                ->help(exmtrans("system.help.org_joined_type_role_group") . exmtrans("common.help.more_help_here", $manualUrl))
                ->options(JoinedOrgFilterType::transKeyArray('system.joined_org_filter_role_group_options'))
                ->disableClear()
                ->default(JoinedOrgFilterType::ALL)
            ;

            $form->select('org_joined_type_custom_value', exmtrans("system.org_joined_type_custom_value"))
                ->help(exmtrans("system.help.org_joined_type_custom_value") . exmtrans("common.help.more_help_here", $manualUrl))
                ->options(JoinedOrgFilterType::transKeyArray('system.joined_org_filter_custom_value_options'))
                ->disableClear()
                ->default(JoinedOrgFilterType::ONLY_JOIN)
            ;

            $form->select('custom_value_save_autoshare', exmtrans("system.custom_value_save_autoshare"))
                ->help(exmtrans("system.help.custom_value_save_autoshare") . exmtrans("common.help.more_help_here", $manualUrl))
                ->options(CustomValueAutoShare::transKeyArray('system.custom_value_save_autoshare_options'))
                ->disableClear()
                ->default(CustomValueAutoShare::USER_ONLY)
            ;
        }

        $manualUrl = getManualUrl('multiuser');
        $form->select('filter_multi_user', exmtrans(boolval(System::organization_available()) ? "system.filter_multi_orguser" : "system.filter_multi_user"))
            ->help(exmtrans("system.help.filter_multi_orguser") . exmtrans("common.help.more_help_here", $manualUrl))
            ->options(JoinedMultiUserFilterType::getOptions())
            ->disableClear()
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
        $this->setNotifyForm($form);

        $form->exmheader(exmtrans('system.ip_filter'))->hr();
        $form->descriptionHtml(exmtrans("system.help.ip_filter"));

        $form->textarea('web_ip_filters', exmtrans('system.web_ip_filters'))->rows(3);
        $form->textarea('api_ip_filters', exmtrans('system.api_ip_filters'))->rows(3);

        return $form;
    }

    /**
     * get exment version infoBox.
     *
     * @return WidgetForm
     */
    protected function getVersionBox()
    {
        list($latest, $current) = \Exment::getExmentVersion();
        $version = \Exment::checkLatestVersion();
        $showLink = false;

        $form = new WidgetForm();
        $form->disableReset()->disableSubmit();

        $form->display('version_current', exmtrans('system.version_current_label'))
            ->default($current);
        $form->display('version_latest', exmtrans('system.version_latest_label'))
            ->default($latest);

        $updateButton = false;
        if ($version == SystemVersion::ERROR) {
            $message = exmtrans("system.version_error");
        } elseif ($version == SystemVersion::DEV) {
            $message = exmtrans("system.version_develope");
        } elseif ($version == SystemVersion::LATEST) {
            $message = exmtrans("system.version_latest");
        } else {
            $message = exmtrans("system.version_old") . '(' . $latest . ')';
            $updateButton = true;
        }
        $form->display('version_compare', exmtrans('system.version_compare_label'))
            ->default($message);

        if ($updateButton) {
            //TODO: System update display : Remove comment. Alter update laravel 6.x, Uncomment this.
            //if disable update button, showing only update link
            //if(boolval(config('exment.system_update_display_disabled', false))){
            $manualUrl = exmtrans('common.message.label_link', [
                        'label' => exmtrans('system.call_update_howto'),
                        'link' => \Exment::getManualUrl('update'),
                    ]);
            $form->display(exmtrans('system.call_update_howto'))
                        ->displayText($manualUrl)
                        ->escape(false);
            // } else {
            //     $this->setUpdatePartialForm($form, $latest);
            // }
        }

        return $form;
    }


    //TODO: System update display : Remove comment. Alter update laravel 6.x, Uncomment this.
    /**
     * Set UpdatePartialForm
     *
     * @param WidgetForm $form
     * @param string $latest
     * @return void
     */
    // protected function setUpdatePartialForm(WidgetForm $form, $latest)
    // {
    //     $form->exmheader(exmtrans('system.call_update_header'))->hr();

    //     // check require. If conatains not OK, showing error message.
    //     $checkObjs = [new SystemRequire\Composer, new SystemRequire\FilePermissionInstaller, new SystemRequire\TimeoutTime];
    //     $errorObjs = [];
    //     foreach ($checkObjs as $checkObj) {
    //         $checkObj->systemRequireCalledType(SystemRequireCalledType::WEB);

    //         $checkResult = $checkObj->checkResult();
    //         if (!isMatchString($checkResult, SystemRequireResult::OK)) {
    //             $errorObjs[] = $checkObj;
    //         }
    //     }

    //     // if has error, set button and return
    //     if (!is_nullorempty($errorObjs)) {
    //         $form->display(exmtrans('system.call_update_cannot'))->displayText(exmtrans('system.call_update_cannot_description'));

    //         $buttons = collect($errorObjs)->map(function ($errorObj) {
    //             return view('exment::tools.button-simple', [
    //                 'href' => $errorObj->getSettingUrl(),
    //                 'label' => $errorObj->getLabel(),
    //                 'target' => '_blank',
    //                 'btn_class' => 'btn-primary',
    //             ])->render();
    //         });
    //         $form->description($buttons->implode(''))->escape(false);

    //         return;
    //     }

    //     $form->description(exmtrans('system.call_update_description', $latest))->escape(false);;

    //     $manualUrl = exmtrans('common.message.label_link', [
    //         'label' => exmtrans('system.release_note'),
    //         'link' => \Exment::getManualUrl('release_note'),
    //     ]);
    //     $form->description($manualUrl)->escape(false);

    //     $form->ajaxButton('call_update', exmtrans("system.call_update"))
    //         ->url(admin_urls('system', 'call_update'))
    //         ->button_class('btn-sm btn-info')
    //         ->button_label(exmtrans('system.call_update'))
    //         ->confirm(true)
    //         ->confirm_title(trans('admin.confirm'))
    //         ->confirm_text(exmtrans('system.call_update_modal_confirm', $latest) . exmtrans('common.message.modal_confirm', 'yes'))
    //         ->confirm_error(exmtrans('custom_table.help.delete_confirm_error'));
    // }

    /**
     * get system require box.
     *
     * @return bool|\Illuminate\Auth\Access\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|mixed
     */
    protected function getSystemRequireBox()
    {
        $checkResult = SystemRequireList::make(SystemRequireCalledType::WEB);
        $view = view('exment::widgets.system-require', [
            'checkResult' => $checkResult,
        ]);
        return $view;
    }

    /**
     * Send data
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|true
     * @throws \Throwable
     */
    public function post(Request $request)
    {
        $advanced = $request->has('advanced');

        // validation
        $form = $advanced ? $this->formAdvanced($request) : $this->formBasic($request);
        if (($response = $form->validateRedirect($request)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request, ($advanced ? ['advanced', 'notify'] : ['initialize', 'system']), false, !$advanced);
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
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
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

        \Exment::setTimeLimitLong();
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
        catch (TransportExceptionInterface $ex) {
            \Log::error($ex);

            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('error.mailsend_failed'),
                'reload' => false,
            ]);
        }
    }

    /**
     * call update
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callUpdate(Request $request)
    {
        UpdateService::update([
            'backup' => false,
        ]);

        return getAjaxResponse([
            'result'  => true,
            'logoutAsync' => true,
            'swal' => exmtrans('system.call_update_success'),
            'swaltext' => exmtrans('system.call_update_success_text'),
        ]);
    }
}
