<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\System;

/**
 * Two-step sign-in (Login setting). Turned on by the administrator, who chooses the
 * way (mail, Google Authenticator) and can test it first: no fix button.
 */
class TwoFactorSecurity extends SecurityBase
{
    protected function key(): string
    {
        return 'two_factor';
    }

    public function scope(): string
    {
        return static::SCOPE_SITE;
    }

    public function checkResult(): string
    {
        try {
            return boolval(System::login_use_2factor()) ? SystemRequireResult::OK : SystemRequireResult::WARNING;
        } catch (\Throwable $e) {
            return SystemRequireResult::WARNING;
        }
    }

    public function getSettingUrl(): ?string
    {
        return admin_url('login_setting');
    }
}
