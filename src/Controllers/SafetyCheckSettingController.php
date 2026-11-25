<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;

/**
 * The settings of the safety check (the system settings of the group "safety_check"),
 * once on the detail page of the system settings (#2157).
 */
class SafetyCheckSettingController extends SystemSettingPageControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('system.safety_check'), exmtrans('system.safety_check'), exmtrans('system.safety_check_description'), 'fa-heartbeat');
    }

    protected function uri(): string
    {
        return 'safety_check_setting';
    }

    protected function group(): string
    {
        return 'safety_check';
    }

    protected function title(): string
    {
        return exmtrans('system.safety_check');
    }

    protected function setFields(WidgetForm $form): void
    {
        if (!Features::enabled(Features::SAFETY_CHECK)) {
            $form->descriptionHtml('<span class="text-warning">' . esc_html(exmtrans('safety.feature_off')) . '</span> <a href="' . esc_html(admin_url('feature')) . '">' . esc_html(exmtrans('feature.open_feature')) . '</a>');
        }

        $form->switchbool('safety_check_auto_enabled', exmtrans('system.safety_check_auto_enabled'))
            ->help(exmtrans('system.help.safety_check_auto_enabled'));

        $form->select('safety_check_min_scale', exmtrans('system.safety_check_min_scale'))
            ->help(exmtrans('system.help.safety_check_min_scale'))
            ->options(SafetyCheckDefine::scaleOptions())
            ->disableClear()
            ->default(45);

        $form->number('safety_check_cooldown_minutes', exmtrans('system.safety_check_cooldown_minutes'))
            ->help(exmtrans('system.help.safety_check_cooldown_minutes'))
            ->default(60)
            ->min(1);
        $form->number('safety_check_comment_window_minutes', exmtrans('system.safety_check_comment_window_minutes'))
            ->help(exmtrans('system.help.safety_check_comment_window_minutes'))
            ->default(60)
            ->min(1);
        $form->number('safety_check_max_bulletin_age_minutes', exmtrans('system.safety_check_max_bulletin_age_minutes'))
            ->help(exmtrans('system.help.safety_check_max_bulletin_age_minutes'))
            ->default(10)
            ->min(1);
        $form->number('safety_check_resend_throttle_minutes', exmtrans('system.safety_check_resend_throttle_minutes'))
            ->help(exmtrans('system.help.safety_check_resend_throttle_minutes'))
            ->default(5)
            ->min(0);
        $form->number('safety_check_answer_url_expire_days', exmtrans('system.safety_check_answer_url_expire_days'))
            ->help(exmtrans('system.help.safety_check_answer_url_expire_days'))
            ->default(7)
            ->required()
            ->min(0)
            ->max(SafetyCheckSender::ANSWER_URL_MAX_EXPIRE_DAYS);

        // 送信対象 (GitHub #2180): all empty is every user
        if (System::organization_available()) {
            $targetOrganizations = System::safety_check_target_organizations();
            $organizationTable = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
            $form->multipleSelect('safety_check_target_organizations', exmtrans('system.safety_check_target_organizations'))
                ->help(exmtrans('system.help.safety_check_target_organizations'))
                ->ajax($organizationTable->getOptionAjaxUrl())
                ->options(function ($option) use ($organizationTable, $targetOrganizations) {
                    return $organizationTable->getSelectOptions([
                        'selected_value' => $targetOrganizations,
                    ]);
                });
        }

        $form->select('safety_check_target_view', exmtrans('system.safety_check_target_view'))
            ->help(exmtrans('system.help.safety_check_target_view'))
            ->options(SafetyCheckSender::targetViewOptions());

        $excludedUsers = System::safety_check_excluded_users();
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $form->multipleSelect('safety_check_excluded_users', exmtrans('system.safety_check_excluded_users'))
            ->help(exmtrans('system.help.safety_check_excluded_users'))
            ->ajax($userTable->getOptionAjaxUrl())
            ->options(function ($option) use ($userTable, $excludedUsers) {
                return $userTable->getSelectOptions([
                    'selected_value' => $excludedUsers,
                ]);
            });
    }
}
