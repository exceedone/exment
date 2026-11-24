<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The webhooks of the notifications do not go to the addresses inside the server's
 * network (EXMENT_NOTIFY_WEBHOOK_DENY_PRIVATE). A site that sends to its own
 * network on purpose leaves it off.
 */
class WebhookSecurity extends SecurityBase
{
    use EnvFixTrait;

    protected function key(): string
    {
        return 'webhook';
    }

    public function checkResult(): string
    {
        return boolval(config('exment.notify_webhook_deny_private')) ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getFixText(): ?string
    {
        return "# .env\nEXMENT_NOTIFY_WEBHOOK_DENY_PRIVATE=true";
    }

    public function canFix(): bool
    {
        return $this->checkResult() != SystemRequireResult::OK && $this->envWritable();
    }

    public function fix(): void
    {
        $this->writeEnv(['EXMENT_NOTIFY_WEBHOOK_DENY_PRIVATE' => 'true']);
    }
}
