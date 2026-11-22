<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * APP_DEBUG off: the details of an error are not shown to the visitor.
 */
class AppDebugSecurity extends SecurityBase
{
    use EnvFixTrait;

    protected function key(): string
    {
        return 'app_debug';
    }

    public function checkResult(): string
    {
        return boolval(config('app.debug')) ? SystemRequireResult::NG : SystemRequireResult::OK;
    }

    public function getFixText(): ?string
    {
        return "# .env\nAPP_DEBUG=false";
    }

    public function canFix(): bool
    {
        return $this->checkResult() != SystemRequireResult::OK && $this->envWritable();
    }

    public function fix(): void
    {
        $this->writeEnv(['APP_DEBUG' => 'false']);
    }
}
