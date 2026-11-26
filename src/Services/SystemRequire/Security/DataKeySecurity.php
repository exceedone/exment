<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviders;

/**
 * The service of the keys of the encrypted columns is reached (ISS02929): with a provider other
 * than env (aws_kms, aws_secrets_manager, vault), the keys can be read now. Not reached, the
 * encrypted columns cannot be read or saved. The keys in .env (the default): nothing to check.
 */
class DataKeySecurity extends SecurityBase
{
    protected function key(): string
    {
        return 'data_key';
    }

    public function checkResult(): string
    {
        if (KeyProviders::isEnv()) {
            return SystemRequireResult::OK;
        }
        DataKeys::reset();
        if (DataKeys::unavailable() !== null) {
            return SystemRequireResult::NG;
        }
        return DataKeys::hasKey() ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    protected function getMessageNg(): ?string
    {
        return exmtrans('security_check.item.data_key.ng', ['provider' => KeyProviders::name(), 'reason' => strval(DataKeys::unavailable())]);
    }

    public function getFixText(): ?string
    {
        return KeyProviders::isEnv() ? null : 'php artisan exment:data-key status';
    }
}
