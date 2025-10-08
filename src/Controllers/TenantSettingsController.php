<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums\SystemLocale;
use Exceedone\Exment\Enums\Timezone;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\TenantUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantSettingsController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('tenant.header'), exmtrans('tenant.settings_header'), exmtrans('tenant.settings_description'), 'fa-building');
    }

    /**
     * GET /tenant/settings
     */
    public function index(Request $request, Content $content)
    {
        $tenant = tenant();
        if (!$tenant) {
            return response(exmtrans('tenant.404'), 404);
        }
        $this->AdminContent($content);

        $form = $this->formBasic($request, $tenant);
        /** @phpstan-ignore-next-line Encore\Admin\Widgets\Box constructor expects string, Encore\Admin\Widgets\Form given */
        $box = new Box(exmtrans('common.basic_setting'), $form);
        $content->row($box);

        return $content;
    }
    protected function formBasic(Request $request, $tenant): WidgetForm
    {

        $planInfo = (array)($tenant ? $tenant['plan_info'] : []);
        $env = (array)($tenant ? $tenant['environment_settings'] : []);

        // Calculate current user count for progress display
        $usedUsers = getModelName(SystemTableName::USER)::query()->count();

        $usageResult = TenantUsageService::getCombinedUsage($tenant['subdomain']);
        $usedSizeGb = $usageResult['success'] ? $usageResult['data']['total']['total_size_gb'] : 0;;

        $form = new WidgetForm();
        $form->disableReset();
        $form->action(\admin_url('tenant/settings'));

        // Plan information (display only)
        $form->exmheader(exmtrans('tenant.plan_information'))->hr();
        $form->display('subdomain', exmtrans('tenant.subdomain'))->default($tenant ? $tenant['subdomain'] : '');
        $form->display('plan_name', exmtrans('tenant.plan_name'))->default(\data_get($planInfo, 'name'));
        $form->display('expiration_date', exmtrans('tenant.expiration_date'))->default((new \Carbon\Carbon(\data_get($planInfo, 'expired_at')))->format(config('admin.date_format')));
        $limit = \data_get($planInfo, 'user_limit');
        $form->display('plan_user_limit', exmtrans('tenant.plan_user_limit'))
            ->displayText(function () use ($limit, $usedUsers) {
                $limit = (int)$limit;
                $used = (int)$usedUsers;
                $percent = $limit > 0 ? min(100, (int)round($used * 100 / $limit)) : 0;
                $label = "{$used} / {$limit} ". exmtrans('user.default_table_name');
                return "<div class=\"progress progress-aqua progress-input\" style=\"position: relative;margin-bottom: 0;\"><div class=\"progress-bar progress-bar-aqua\" style=\"width: {$percent}%\"></div><div style=\"position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10; color: #333; font-weight: bold;\">{$label}</div></div>";
            })
            ->escape(false)
            ->default(\data_get($planInfo, 'user_limit'));
        $dbLimit = \data_get($planInfo, 'db_size_gb');
        $form->display('plan_db_size_gb', exmtrans('tenant.plan_db_size_gb'))
            ->displayText(function () use ($dbLimit, $usedSizeGb) {
                $limit = (float)$dbLimit;
                $used = (float)$usedSizeGb;
                $percent = $limit > 0 ? min(100, (int)round($used * 100 / $limit)) : 0;
                $label = "{$used} / {$limit} GB";
                return "<div class=\"progress progress-aqua progress-input\" style=\"position: relative;margin-bottom: 0;\"><div class=\"progress-bar progress-bar-aqua\" style=\"width: {$percent}%\"></div><div style=\"position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10; color: #333; font-weight: bold;\">{$label}</div></div>";
            })
            ->escape(false)
            ->default(\data_get($planInfo, 'db_size_gb'));

        // Environment settings (editable)
        $form->exmheader(exmtrans('tenant.environment_settings'))->hr();
        $form->select('language', exmtrans('tenant.language'))
            ->options(SystemLocale::getLocaleOptions())
            ->disableClear()
            ->default(\data_get($env, 'language', config('exment.default_locale', 'ja')))
            ->help(exmtrans('tenant.help.language'));

        $form->select('timezone', exmtrans('tenant.timezone'))
            ->options(Timezone::TIMEZONE)
            ->disableClear()
            ->default(\data_get($env, 'timezone', config('exment.default_timezone', 'Asia_Tokyo')))
            ->help(exmtrans('tenant.help.timezone'));

        return $form;
    }

    /**
     * POST /tenant/settings
     */
    public function post(Request $request)
    {
        $tenantInfo = tenant();
        if (!$tenantInfo) {
            return response(exmtrans('tenant.404'), 404);
        }
        Config::set('database.default', config('database.central'));
        DB::beginTransaction();
        try {
            $tenant = Tenant::find($tenantInfo['id']);
            $settings = $tenant->environment_settings ?? [];
            data_set($settings, 'language', $request->get('language'));
            data_set($settings, 'timezone', $request->get('timezone'));

            $tenant->environment_settings = $settings;
            $tenant->save();

            DB::commit();
            \admin_toastr(\trans('admin.save_succeeded'));
            return response()->make('<script>window.location.href="' . \admin_url('tenant/settings'). '";</script>');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

}
