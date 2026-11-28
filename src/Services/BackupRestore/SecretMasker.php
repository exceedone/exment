<?php

namespace Exceedone\Exment\Services\BackupRestore;

/**
 * Hides the secrets in what the log of a backup or a restore keeps (ISS02943): the log is shown on
 * the screen and downloaded, so it never holds a password, a key or the connection string of a
 * database, whatever wrote the line (a message of an exception, the output of a command).
 *
 * - the values of the settings that are secrets (the passwords of the databases, the key of the
 *   application, the keys of the disks, the mail, redis), wherever they appear
 * - what looks like one: "--password=...", "password: ...", "user:pass@host", a DSN, "base64:..."
 */
class SecretMasker
{
    public const MASK = '********';

    /** The shortest value hidden as it is (a shorter one would hide ordinary words). */
    public const MIN_LENGTH = 4;

    /**
     * @var array<int, string>|null
     */
    protected static ?array $secrets = null;

    /**
     * The secrets to hide, read again (a test that changes the config).
     */
    public static function flush(): void
    {
        static::$secrets = null;
    }

    public static function mask(?string $text): string
    {
        $text = strval($text);
        if ($text === '') {
            return '';
        }

        $patterns = [
            // a DSN: mysql:host=...;dbname=..., sqlsrv:Server=...
            // (only with what follows the colon: "mysql: ERROR 2026 ...", the name of a client, stays)
            '/\b(?:mysql|mariadb|pgsql|sqlsrv|dblib|odbc|sqlite):[^\s\'"]+/i' => static::MASK,
            // user:password@ in a URL (redis://, smtp://, https://)
            '#\b([a-z][a-z0-9+.\-]*://)[^\s/@:\'"]*:[^\s/@\'"]*@#i' => '$1' . static::MASK . '@',
            // --password=..., --password ..., -p'...'
            '/(--password[=\s]+)(\'[^\']*\'|"[^"]*"|\S+)/i' => '$1' . static::MASK,
            // a key of the application or of the encryption
            '/\bbase64:[A-Za-z0-9+\/=]{16,}/' => static::MASK,
            // password=..., "secret": "...", api_key: ...
            '/((?:pass(?:word|wd)?|pwd|secret|token|api[_\-]?key|app[_\-]?key|access[_\-]?key|private[_\-]?key|credential)s?["\']?\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&\'"}]+)/i' => '$1' . static::MASK,
        ];
        foreach ($patterns as $pattern => $replace) {
            $text = strval(preg_replace($pattern, $replace, $text));
        }

        // the values of the settings, after the patterns (a value equal to a word of a pattern,
        // as "secret", would hide the pattern itself)
        foreach (static::secrets() as $secret) {
            $text = str_replace($secret, static::MASK, $text);
        }

        return $text;
    }

    /**
     * Mask each string of an array (the context of a line of the log).
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public static function maskArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|key|dsn|credential/i', $key)) {
                $result[$key] = static::MASK;
                continue;
            }
            if (is_array($value)) {
                $result[$key] = static::maskArray($value);
            } elseif (is_string($value)) {
                $result[$key] = static::mask($value);
            } elseif (is_scalar($value) || is_null($value)) {
                $result[$key] = $value;
            } else {
                $result[$key] = is_object($value) ? get_class($value) : gettype($value);
            }
        }
        return $result;
    }

    /**
     * The values of the settings that are secrets, the longest first (a value inside another
     * is hidden with it).
     *
     * @return array<int, string>
     */
    public static function secrets(): array
    {
        if (static::$secrets !== null) {
            return static::$secrets;
        }

        $values = [config('app.key')];
        foreach ((array) config('app.previous_keys', []) as $key) {
            $values[] = $key;
        }
        foreach ((array) config('database.connections', []) as $connection) {
            if (!is_array($connection)) {
                continue;
            }
            $values[] = $connection['password'] ?? null;
            $values[] = $connection['url'] ?? null;
        }
        foreach ((array) config('database.redis', []) as $connection) {
            if (is_array($connection)) {
                $values[] = $connection['password'] ?? null;
                $values[] = $connection['url'] ?? null;
            }
        }
        foreach ((array) config('filesystems.disks', []) as $disk) {
            if (is_array($disk)) {
                $values[] = $disk['secret'] ?? null;
                $values[] = $disk['key'] ?? null;
                $values[] = $disk['password'] ?? null;
                $values[] = $disk['connection_string'] ?? null;
            }
        }
        foreach ((array) config('mail.mailers', []) as $mailer) {
            if (is_array($mailer)) {
                $values[] = $mailer['password'] ?? null;
            }
        }
        // the variables of the environment that are named as secrets
        foreach (array_merge($_ENV, $_SERVER) as $name => $value) {
            if (is_string($name) && is_string($value) && preg_match('/PASSWORD|SECRET|TOKEN|_KEY$|^APP_KEY|_KEYS$|DSN|CREDENTIAL/i', $name)) {
                $values[] = $value;
            }
        }

        $secrets = [];
        foreach ($values as $value) {
            if (!is_string($value) || mb_strlen($value) < static::MIN_LENGTH) {
                continue;
            }
            $secrets[] = $value;
            // the key without "base64:" is a secret too
            if (str_starts_with($value, 'base64:') && strlen($value) > 7 + static::MIN_LENGTH) {
                $secrets[] = substr($value, 7);
            }
            // a list of keys (DATA_ENCRYPTION_KEYS=a,b)
            if (str_contains($value, ',')) {
                foreach (explode(',', $value) as $part) {
                    $part = trim($part);
                    if (mb_strlen($part) >= static::MIN_LENGTH) {
                        $secrets[] = $part;
                    }
                }
            }
        }
        $secrets = array_values(array_unique($secrets));
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));

        return static::$secrets = $secrets;
    }
}
