<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Hash;

/**
 * No system administrator signs in with a password of the first setup or of a sample
 * (the user code itself, adminadmin...). Fixed by the administrator: the page links
 * to where the password is changed.
 */
class AdminPasswordSecurity extends SecurityBase
{
    /** passwords of samples and first setups; the user code is checked too */
    public const WEAK = ['adminadmin', 'admin', 'password', 'password1', '12345678', '123456789', 'exment', 'exmentexment'];

    /** @var array<string>|null */
    protected $weak;

    protected function key(): string
    {
        return 'admin_password';
    }

    public function scope(): string
    {
        return static::SCOPE_SITE;
    }

    /**
     * The user codes of the system administrators with such a password.
     *
     * @return array<string>
     */
    public function weakAdministrators(): array
    {
        if (isset($this->weak)) {
            return $this->weak;
        }
        $result = [];
        try {
            $ids = array_filter((array) System::system_admin_users());
            if (count($ids) === 0) {
                return $this->weak = [];
            }
            /** @var \Illuminate\Support\Collection<int, LoginUser> $logins */
            $logins = LoginUser::query()->whereIn('base_user_id', $ids)->whereNotNull('password')->get();
            foreach ($logins as $login) {
                $code = strval($login->base_user ? $login->base_user->getValue('user_code') : '');
                $candidates = array_unique(array_filter(array_merge(static::WEAK, [$code, $code . $code])));
                foreach ($candidates as $candidate) {
                    if (Hash::check($candidate, strval($login->password))) {
                        $result[] = $code !== '' ? $code : strval($login->id);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('security check: admin_password: ' . $e->getMessage());
        }
        return $this->weak = array_values(array_unique($result));
    }

    public function checkResult(): string
    {
        return count($this->weakAdministrators()) > 0 ? SystemRequireResult::NG : SystemRequireResult::OK;
    }

    public function isHigh(): bool
    {
        return $this->checkResult() == SystemRequireResult::NG;
    }

    public function getResultText(): ?string
    {
        $weak = $this->weakAdministrators();
        return count($weak) > 0 ? implode(', ', $weak) : exmtrans('security_check.result_ok');
    }

    public function getFixText(): ?string
    {
        return $this->checkResult() == SystemRequireResult::OK ? null : exmtrans('security_check.item.admin_password.fix');
    }

    public function getSettingUrl(): ?string
    {
        return admin_url('loginuser');
    }
}
