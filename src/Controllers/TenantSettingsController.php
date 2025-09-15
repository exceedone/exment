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
        $tenant = TenantUsageService::getCurrentTenant();
        if (!$tenant) {
            return "";
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

        $planInfo = (array)($tenant ? $tenant->plan_info : []);
        $env = (array)($tenant ? $tenant->environment_settings : []);

        // Calculate current user count for progress display
        $usedUsers = getModelName(SystemTableName::USER)::query()->count();

        $usageResult = TenantUsageService::getCombinedUsage($tenant->subdomain);
        $usedSizeGb = $usageResult['success'] ? $usageResult['data']['total']['total_size_gb'] : 0;;

        $form = new WidgetForm();
        $form->disableReset();
        $form->action(\admin_url('tenant/settings'));

        // Plan information (display only)
        $form->exmheader(exmtrans('tenant.plan_information'))->hr();
        $form->display('subdomain', exmtrans('tenant.subdomain'))->default($tenant ? $tenant->subdomain : '');
        $form->display('plan_name', exmtrans('tenant.plan_name'))->default(\data_get($planInfo, 'name'));
        $form->display('expiration_date', exmtrans('tenant.expiration_date'))->default((new \Carbon\Carbon(\data_get($planInfo, 'expired_at')))->format(config('admin.date_format')));
        $limit = \data_get($planInfo, 'user_limit');
        $form->display('plan_user_limit', exmtrans('tenant.plan_user_limit'))
            ->displayText(function () use ($limit, $usedUsers) {
                $limit = (int)$limit;
                $used = (int)$usedUsers;
                $percent = $limit > 0 ? min(100, (int)round($used * 100 / $limit)) : 0;
                $label = "{$used} / {$limit} ユーザー";
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

        // S3 Storage usage display
        // $s3Limit = 1;
        // $form->display('plan_s3_size_gb', exmtrans('tenant.plan_s3_size_gb'))
        //     ->displayText(function () use ($s3Limit, $usedS3SizeGb) {
        //         $limit = (float)$s3Limit;
        //         $used = (float)$usedS3SizeGb;
        //         $percent = $limit > 0 ? min(100, (int)round($used * 100 / $limit)) : 0;
        //         $label = "{$used} / {$limit} GB";
        //         $barColor = $percent > 80 ? 'progress-bar-danger' : ($percent > 60 ? 'progress-bar-warning' : 'progress-bar-success');
        //         return "<div class=\"progress progress-aqua progress-input\" style=\"position: relative;margin-bottom: 0;\"><div class=\"progress-bar {$barColor}\" style=\"width: {$percent}%\"></div><div style=\"position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10; color: #333; font-weight: bold;\">{$label}</div></div>";
        //     })
        //     ->escape(false)
        //     ->default(\data_get($planInfo, 's3_size_gb', 0));

        // // S3 Usage refresh button
        // $form->display('s3_refresh_button', exmtrans('tenant.s3_refresh_button'))
        //     ->displayText(function () {
        //         $refreshUrl = \admin_url('tenant/settings/refresh-s3-usage');
        //         $ajaxUrl = \admin_url('tenant/settings/s3-usage');
        //         return '<div class="s3-refresh-section" style="margin: 10px 0;">
        //             <a href="' . $refreshUrl . '" class="btn btn-primary btn-sm">
        //                 <i class="fa fa-refresh"></i> ' . exmtrans('tenant.refresh_s3_usage') . '
        //             </a>
        //             <button type="button" class="btn btn-info btn-sm" onclick="refreshS3UsageAjax()" style="margin-left: 10px;">
        //                 <i class="fa fa-refresh"></i> ' . exmtrans('tenant.refresh_ajax') . '
        //             </button>
        //             <span id="s3-refresh-status" style="margin-left: 10px;"></span>
        //         </div>
        //         <script>
        //         function refreshS3UsageAjax() {
        //             var statusEl = document.getElementById("s3-refresh-status");
        //             statusEl.innerHTML = "<i class=\"fa fa-spinner fa-spin\"></i> Refreshing...";

        //             fetch("' . $ajaxUrl . '?refresh=1")
        //                 .then(response => response.json())
        //                 .then(data => {
        //                     if (data.success) {
        //                         statusEl.innerHTML = "<span style=\"color: green;\"><i class=\"fa fa-check\"></i> Refreshed successfully</span>";
        //                         setTimeout(() => {
        //                             location.reload();
        //                         }, 1000);
        //                     } else {
        //                         statusEl.innerHTML = "<span style=\"color: red;\"><i class=\"fa fa-times\"></i> Error: " + data.message + "</span>";
        //                     }
        //                 })
        //                 .catch(error => {
        //                     statusEl.innerHTML = "<span style=\"color: red;\"><i class=\"fa fa-times\"></i> Error: " + error.message + "</span>";
        //                 });
        //         }
        //         </script>';
        //     })
        //     ->escape(false);

        // // Detailed S3 usage breakdown
        // if ($s3UsageResult['success']) {
        //     $s3Data = $s3UsageResult['data']['s3_usage'];
        //     $form->display('s3_usage_breakdown', exmtrans('tenant.s3_usage_breakdown'))
        //         ->displayText(function () use ($s3Data) {
        //             $html = '<div class="s3-usage-breakdown" style="margin-top: 10px;">';
        //             $html .= '<table class="table table-striped table-condensed" style="margin-bottom: 0;">';
        //             $html .= '<thead><tr><th>Bucket</th><th>Size (MB)</th><th>Objects</th></tr></thead>';
        //             $html .= '<tbody>';

        //             foreach (['exment', 'backup', 'template', 'plugin'] as $type) {
        //                 if (isset($s3Data[$type]) && $s3Data[$type]['bucket']) {
        //                     $bucketData = $s3Data[$type];
        //                     $html .= '<tr>';
        //                     $html .= '<td>' . ucfirst($type) . ' (' . $bucketData['bucket'] . ')</td>';
        //                     $html .= '<td>' . $bucketData['total_size_mb'] . '</td>';
        //                     $html .= '<td>' . $bucketData['object_count'] . '</td>';
        //                     $html .= '</tr>';
        //                 }
        //             }

        //             $html .= '<tr style="font-weight: bold; background-color: #f5f5f5;">';
        //             $html .= '<td>Total</td>';
        //             $html .= '<td>' . $s3Data['total']['total_size_mb'] . '</td>';
        //             $html .= '<td>-</td>';
        //             $html .= '</tr>';
        //             $html .= '</tbody></table>';
        //             $html .= '</div>';
        //             return $html;
        //         })
        //         ->escape(false);
        // }

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
        $tenant = TenantUsageService::getCurrentTenant();
        if (!$tenant) {
            \abort(404);
        }

        DB::beginTransaction();
        try {
            $settings = [
                'language' => $request->get('language'),
                'timezone' => $request->get('timezone'),
            ];
            $tenant->environment_settings = $settings;
            $tenant->save();

            DB::commit();
            \admin_toastr(\trans('admin.save_succeeded'));
            return \redirect(\admin_url('tenant/settings'));
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // /**
    //  * Refresh S3 usage cache
    //  * GET /tenant/settings/refresh-s3-usage
    //  */
    // public function refreshS3Usage(Request $request)
    // {
    //     try {
    //         $result = TenantUsageService::setS3Usage();

    //         if ($result['success']) {
    //             \admin_toastr('S3 usage refreshed successfully', 'success');
    //         } else {
    //             \admin_toastr('Failed to refresh S3 usage: ' . $result['message'], 'error');
    //         }

    //         return \redirect(\admin_url('tenant/settings'));
    //     } catch (\Exception $e) {
    //         \admin_toastr('Error refreshing S3 usage: ' . $e->getMessage(), 'error');
    //         return \redirect(\admin_url('tenant/settings'));
    //     }
    // }

    // /**
    //  * Get S3 usage data via AJAX
    //  * GET /tenant/settings/s3-usage
    //  */
    // public function getS3Usage(Request $request)
    // {
    //     try {
    //         $forceRefresh = $request->get('refresh', false);
    //         $result = TenantUsageService::getS3Usage($forceRefresh);

    //         return response()->json($result);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'error' => 'GET_S3_USAGE_FAILED',
    //             'message' => 'Failed to get S3 usage: ' . $e->getMessage(),
    //             'status' => 500
    //         ], 500);
    //     }
    // }

}
