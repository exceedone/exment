<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * APP_KEY set, not a sample value, and not the one of a download zip. Not fixed from the screen:
 * a new key makes what was encrypted with the old one (passwords of the mail servers, tokens...)
 * unreadable.
 *
 * The zips of Exment ship a .env with an APP_KEY. The web installer makes a new one; a site set up
 * without it keeps the one of the zip. Known by its SHA-256 only: the list of the zips published
 * (resources/security/shipped_app_key_sha256.txt), and the line EXMENT_SHIPPED_APP_KEY_SHA256 the
 * zip writes in its .env (config exment.shipped_app_key_sha256).
 */
class AppKeySecurity extends SecurityBase
{
    /** Values of samples and documents, never a real key. */
    public const SAMPLES = ['', 'SomeRandomString', 'base64:', 'changeme'];

    /** The SHA-256 of the APP_KEY of the zips published, one a line (the package path). */
    public const SHIPPED_LIST = 'resources/security/shipped_app_key_sha256.txt';

    /** @var array<string, true>|null the list, read once */
    protected static $shipped = null;

    protected function key(): string
    {
        return 'app_key';
    }

    public function checkResult(): string
    {
        $key = static::currentKey();
        if (in_array($key, static::SAMPLES, true) || static::isShipped($key)) {
            return SystemRequireResult::NG;
        }
        // a key of 32 bytes (AES-256): base64 of 32 bytes, or 32 characters
        $raw = strpos($key, 'base64:') === 0 ? base64_decode(substr($key, 7), true) : $key;
        return is_string($raw) && strlen($raw) >= 32 ? SystemRequireResult::OK : SystemRequireResult::NG;
    }

    /**
     * Whether APP_KEY is the one of a download zip: its SHA-256 (of the value as written after
     * "APP_KEY=") is the one the zip wrote in .env, or one of the list.
     */
    public static function isShipped(?string $key = null): bool
    {
        $key = $key ?? static::currentKey();
        if ($key === '') {
            return false;
        }
        $hash = hash('sha256', $key);
        $marked = strtolower(trim(strval(config('exment.shipped_app_key_sha256'))));
        if ($marked !== '' && hash_equals($marked, $hash)) {
            return true;
        }
        return isset(static::shippedHashes()[$hash]);
    }

    /**
     * @return array<string, true>
     */
    protected static function shippedHashes(): array
    {
        if (isset(static::$shipped)) {
            return static::$shipped;
        }
        $hashes = [];
        $file = exment_package_path(static::SHIPPED_LIST);
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
        foreach ($lines ?: [] as $line) {
            $line = strtolower(trim($line));
            if (preg_match('/^[0-9a-f]{64}$/', $line)) {
                $hashes[$line] = true;
            }
        }
        return static::$shipped = $hashes;
    }

    protected static function currentKey(): string
    {
        return trim(strval(config('app.key')));
    }

    /** The one of a download zip: not a key of this site only. */
    public function isHigh(): bool
    {
        return static::isShipped();
    }

    protected function getMessageNg(): ?string
    {
        return static::isShipped()
            ? exmtrans('security_check.item.app_key.shipped_warning')
            : parent::getMessageNg();
    }

    public function getFixText(): ?string
    {
        return static::isShipped()
            ? exmtrans('security_check.item.app_key.shipped_fix')
            : exmtrans('security_check.item.app_key.fix');
    }
}
