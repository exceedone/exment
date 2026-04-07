<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\TenantEnvService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class TenantSettingsController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('tenant.header'), exmtrans('tenant.settings_header'), exmtrans('tenant.settings_description'), 'fa-building');
    }

    /**
     * GET /tenant-settings
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);

        $form = $this->formBasic($request);
        /** @phpstan-ignore-next-line */
        $box = new Box(exmtrans('common.basic_setting'), $form);
        $content->row($box);

        return $content;
    }

    /**
     * Parse .tenant_info file written by job_quota.php.
     * Format per line: KEY=VALUE
     * Located at Laravel project root (base_path('.tenant_info')).
     *
     * @return array<string, string>
     */
    protected function parseTenantInfo(): array
    {
        $file = base_path('.tenant_info');
        if (!File::exists($file)) {
            return [];
        }

        $data = [];
        foreach (explode("\n", File::get($file)) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $data[trim($key)] = trim($value);
        }
        return $data;
    }

    protected function formBasic(Request $request): WidgetForm
    {
        $info = $this->parseTenantInfo();

        // --- Raw values from .tenant_info ---
        $quotaBytes   = (int)($info['QUOTA_BYTES'] ?? 0);
        $totalBytes   = (int)($info['TOTAL_BYTES'] ?? 0);
        $dbBytes      = (int)($info['DB_BYTES'] ?? 0);
        $fsBytes      = (int)($info['FS_BYTES'] ?? 0);
        $usagePercent = (int)($info['USAGE_PERCENT'] ?? 0);
        $expiredAt      = $info['EXPIRED_AT'] ?? '';
        $planName       = $info['PLAN_NAME'] ?? '';
        $planUserLimit  = $info['PLAN_USER_LIMIT'] ?? '';
        $updatedAt    = isset($info['UPDATED_AT'])
            ? date('Y-m-d H:i:s', (int)$info['UPDATED_AT'])
            : '';

        // --- Derived display values ---
        $quotaGb  = $quotaBytes > 0 ? round($quotaBytes / (1024 ** 3), 1) : 0;
        $usedGb   = round($totalBytes / (1024 ** 3), 2);
        $dbGb     = round($dbBytes / (1024 ** 3), 2);
        $fsGb     = round($fsBytes / (1024 ** 3), 2);

        $expiredAtFormatted = '';
        if ($expiredAt !== '') {
            try {
                $expiredAtFormatted = \Carbon\Carbon::createFromFormat('Ymd', $expiredAt)
                    ->format(config('admin.date_format', 'Y-m-d'));
            } catch (\Exception $e) {
                $expiredAtFormatted = $expiredAt;
            }
        }

        // Subdomain: from env (set per-tenant in .env)
        $subdomain = request()->getHost();

        // ---  Build form ---
        $form = new WidgetForm();
        $form->disableReset();
        $form->action(admin_url('tenant-settings'));

        // Plan information (display only)
        $form->exmheader(exmtrans('tenant.plan_information'))->hr();

        $form->display('subdomain', exmtrans('tenant.subdomain'))
            ->default($subdomain);

        $form->display('plan_name', exmtrans('tenant.plan_name'))
            ->default($planName);

        $form->display('expiration_date', exmtrans('tenant.expiration_date'))
            ->default($expiredAtFormatted);
            
        $usedUsers = getModelName(SystemTableName::USER)::query()->count();
        $form->display('plan_user_limit', exmtrans('tenant.plan_user_limit'))
            ->displayText(function () use ($planUserLimit, $usedUsers) {
                $limit = (int)$planUserLimit;
                $used = (int)$usedUsers;
                $percent = $limit > 0 ? min(100, (int)round($used * 100 / $limit)) : 0;
                $label = "{$used} / {$limit} " . exmtrans('user.default_table_name');
                return "<div class=\"progress progress-aqua progress-input\" style=\"position: relative;margin-bottom: 0;\"><div class=\"progress-bar progress-bar-aqua\" style=\"width: {$percent}%\"></div><div style=\"position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10; color: #333; font-weight: bold;\">{$label}</div></div>";
            })
            ->escape(false)
            ->default('');
        // DB usage progress bar
        $form->display('plan_db_size_gb', exmtrans('tenant.plan_db_size_gb'))
            ->displayText(function () use ($quotaGb, $usedGb, $usagePercent) {
                $label = "{$usedGb} / {$quotaGb} GB";
                return "<div class=\"progress progress-aqua progress-input\" style=\"position:relative;margin-bottom:0;\">"
                    . "<div class=\"progress-bar progress-bar-aqua\" style=\"width:{$usagePercent}%\"></div>"
                    . "<div style=\"position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;color:#333;font-weight:bold;\">{$label}</div>"
                    . "</div>";
            })
            ->escape(false)
            ->default('');

        // Last updated
        $form->display('updated_at', exmtrans('common.updated_at'))
            ->default($updatedAt);

        // Environment settings (editable)
        $form->exmheader(exmtrans('tenant.environment_settings'))->hr();

        $form->textarea('env_vars', exmtrans('tenant.env_vars'))
            ->default(TenantEnvService::readAllowedEnvText())
            ->rows(20)
            ->help(exmtrans('tenant.env_vars_help'));

        return $form;
    }

    /**
     * POST /tenant-settings
     */
    public function post(Request $request)
    {
        $text = $request->input('env_vars', '');

        if (!TenantEnvService::writeAllowedEnv($text)) {
            admin_toastr(exmtrans('common.message.error_request'), 'error');
            return back();
        }

        admin_toastr(trans('admin.save_succeeded'));
        return back();
    }
}
