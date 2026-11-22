<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\SystemRequireBase;

/**
 * One item of the security check (ISS02833): now / the good state / how to fix it,
 * and, only where Exment can do it safely, a fix.
 *
 * The wording says what to set, never how the weakness would be used.
 */
abstract class SecurityBase extends SystemRequireBase
{
    /** Who can fix it: the server's administrator, or anyone who runs the site (a tenant). */
    public const SCOPE_SERVER = 'server';
    public const SCOPE_SITE = 'site';

    abstract protected function key(): string;

    public function getKey(): string
    {
        return $this->key();
    }

    /** SCOPE_SERVER (php, .env, the web server) or SCOPE_SITE (the users and settings in Exment). */
    public function scope(): string
    {
        return static::SCOPE_SERVER;
    }

    public function getLabel(): string
    {
        return exmtrans('security_check.item.' . $this->key() . '.label');
    }

    public function getExplain(): string
    {
        return exmtrans('security_check.item.' . $this->key() . '.explain');
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('security_check.item.' . $this->key() . '.warning');
    }

    protected function getMessageNg(): ?string
    {
        return $this->getMessageWarning();
    }

    public function getResultText(): ?string
    {
        return $this->checkResult() == SystemRequireResult::OK
            ? exmtrans('security_check.result_ok')
            : exmtrans('security_check.result_ng');
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('security_check');
    }

    /** What to set, shown under the item (a line of .env, a command...). */
    public function getFixText(): ?string
    {
        return null;
    }

    /** Whether Exment can fix it from the screen now. */
    public function canFix(): bool
    {
        return false;
    }

    /** Fix it. Called only when canFix(). */
    public function fix(): void
    {
        throw new \LogicException('no fix for ' . $this->key());
    }

    /** Whether the check could be made (a page of the site that did not answer, say). */
    public function checked(): bool
    {
        return true;
    }
}
