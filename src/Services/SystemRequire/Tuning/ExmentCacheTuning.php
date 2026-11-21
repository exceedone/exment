<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * EXMENT_USE_CACHE keeps the definitions (tables, columns, settings) in the cache
 * instead of reading them from the database on every request.
 */
class ExmentCacheTuning extends TuningBase
{
    protected function key(): string
    {
        return 'exment_cache';
    }

    public function checkResult(): string
    {
        return boolval(config('exment.use_cache')) ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getFixText(): ?string
    {
        return "# .env\nEXMENT_USE_CACHE=true";
    }
}
