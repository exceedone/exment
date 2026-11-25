<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * APP_DEBUG=true collects details of every request and shows errors on the screen.
 */
class AppDebugTuning extends TuningBase
{
    protected function key(): string
    {
        return 'app_debug';
    }

    public function checkResult(): string
    {
        return boolval(config('app.debug')) ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getFixText(): ?string
    {
        return "# .env\nAPP_DEBUG=false";
    }
}
