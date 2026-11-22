<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * APP_ENV production: Laravel and the packages run as on a live site.
 */
class AppEnvSecurity extends SecurityBase
{
    use EnvFixTrait;

    protected function key(): string
    {
        return 'app_env';
    }

    public function checkResult(): string
    {
        return config('app.env') === 'production' ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        return strval(config('app.env'));
    }

    public function getFixText(): ?string
    {
        return "# .env\nAPP_ENV=production";
    }

    public function canFix(): bool
    {
        return $this->checkResult() != SystemRequireResult::OK && $this->envWritable();
    }

    public function fix(): void
    {
        $this->writeEnv(['APP_ENV' => 'production']);
    }
}
