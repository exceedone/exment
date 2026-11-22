<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * APP_KEY set and not a sample value. Not fixed from the screen: a new key makes what
 * was encrypted with the old one (passwords of the mail servers, tokens...) unreadable.
 */
class AppKeySecurity extends SecurityBase
{
    /** Values of samples and documents, never a real key. */
    public const SAMPLES = ['', 'SomeRandomString', 'base64:', 'changeme'];

    protected function key(): string
    {
        return 'app_key';
    }

    public function checkResult(): string
    {
        $key = trim(strval(config('app.key')));
        if (in_array($key, static::SAMPLES, true)) {
            return SystemRequireResult::NG;
        }
        // a key of 32 bytes (AES-256): base64 of 32 bytes, or 32 characters
        $raw = strpos($key, 'base64:') === 0 ? base64_decode(substr($key, 7), true) : $key;
        return is_string($raw) && strlen($raw) >= 32 ? SystemRequireResult::OK : SystemRequireResult::NG;
    }

    public function getFixText(): ?string
    {
        return exmtrans('security_check.item.app_key.fix');
    }
}
