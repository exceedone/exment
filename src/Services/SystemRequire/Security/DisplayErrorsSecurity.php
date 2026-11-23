<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * PHP display_errors off: an error that happens before Exment can catch it (a parse
 * error, a fatal error) is not shown to the visitor with its file paths. Some rental
 * servers set it On in the php.ini of the domain.
 */
class DisplayErrorsSecurity extends SecurityBase
{
    /** @var \Closure|null fn(): string, the value of display_errors (tests) */
    public static $value;

    protected function key(): string
    {
        return 'display_errors';
    }

    public static function value(): string
    {
        if (static::$value) {
            return strval(call_user_func(static::$value));
        }
        return strval(ini_get('display_errors'));
    }

    public static function on(): bool
    {
        $value = strtolower(trim(static::value()));
        // "stderr" writes to the error output, not the page
        return !in_array($value, ['', '0', 'off', 'false', 'no', 'none', 'stderr'], true);
    }

    public function checkResult(): string
    {
        return static::on() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        return 'display_errors=' . (static::value() === '' ? 'Off' : static::value());
    }

    public function getFixText(): ?string
    {
        if (!static::on()) {
            return null;
        }
        return exmtrans('security_check.item.display_errors.fix', ['public' => public_path()]);
    }
}
