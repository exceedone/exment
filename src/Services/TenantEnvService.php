<?php

namespace Exceedone\Exment\Services;

class TenantEnvService
{
    /**
     * Keys that must never be read or written through the tenant settings UI.
     * Grouped by risk category.
     */
    private const BLOCKED_KEYS = [
        // 1. Secrets / credentials
        'EXMENT_AI_SERVER_API_KEY',
        'AI_SERVER_API_KEY',
        'EXMENT_MARKETPLACE_APP_KEY',
        'EXMENT_MARKET_TENANT_UUID',

        // 2. External URL / service (SSRF risk)
        'EXMENT_AI_SERVER_HOST',
        'EXMENT_MANUAL_URL',
        'MARKETPLACE_URL',
        'EXMENT_TEMPLATE_SEARCH_URL',

        // 3. Storage / Driver / File system
        'EXMENT_DRIVER_EXMENT',
        'EXMENT_DRIVER_BACKUP',
        'EXMENT_DRIVER_PLUGIN',
        'EXMENT_DRIVER_TEMPLATE',
        'AWS_BUCKET_EXMENT',
        'AWS_BUCKET_BACKUP',
        'AWS_BUCKET_PLUGIN',
        'AWS_BUCKET_TEMPLATE',
        'AZURE_STORAGE_CONTAINER_EXMENT',
        'AZURE_STORAGE_CONTAINER_BACKUP',
        'AZURE_STORAGE_CONTAINER_PLUGIN',
        'AZURE_STORAGE_CONTAINER_TEMPLATE',
        'FTP_ROOT_EXMENT',
        'FTP_ROOT_BACKUP',
        'FTP_ROOT_PLUGIN',
        'FTP_ROOT_TEMPLATE',
        'SFTP_ROOT_EXMENT',
        'SFTP_ROOT_BACKUP',
        'SFTP_ROOT_PLUGIN',
        'SFTP_ROOT_TEMPLATE',

        // 4. System path / binary
        'EXMENT_COMPOSER_PATH',
        'EXMENT_MYSQL_BIN_DIR',
        'EXMENT_7ZIP_DIR',

        // 5. Debug / logging (data leak)
        'EXMENT_DEBUG_MODE_REQUEST',
        'EXMENT_DEBUG_MODE_SQL',
        'EXMENT_DEBUG_MODE_SQLFUNCTION',
        'EXMENT_DEBUG_MODE_SQLFUNCTION1',
        'EXMENT_DEBUG_MODE_SCHEDULE',

        // 6. Auth / security control
        'EXMENT_LOGIN_USE_2FACTOR',
        'EXMENT_LOGIN_2FACTOR_VALID_PERIOD',
        'EXMENT_THROTTLE',
        'EXMENT_MAX_ATTEMPTS',
        'EXMENT_DECAY_MINUTES',
        'EXMENT_SESSION_EXPIRE_ON_CLOSE',
        'EXMENT_DISABLE_IP_FILTER',

        // 7. Proxy / infra (critical)
        'ADMIN_TRUST_PROXY_IPS',
        'ADMIN_TRUST_PROXY_HEADERS',

        // 8. Import / export engine
        'EXMENT_EXPORT_LIBRARY',
        'EXMENT_IMPORT_LIBRARY',

        // 9. Marketplace / signature logic (HIGH RISK)
        'EXMENT_MARKET_RESIGN_SIGNED_DOWNLOAD_URL',
        'EXMENT_MARKET_FORCE_APPEND_TENANT_UUID_TO_SIGNED_URL',
        'EXMENT_MARKETPLACE_RESIGN_RELATIVE',

        // 10. System behavior
        'EXMENT_MAIL_SETTING_ENV_FORCE',
    ];

    /**
     * Whether a given env key is blocked from read/write via the UI.
     */
    public static function isBlocked(string $key): bool
    {
        return in_array(strtoupper(trim($key)), self::BLOCKED_KEYS, true);
    }

    /**
     * Read .env and return allowed KEY=VALUE pairs as a plain-text string
     * suitable for display in a textarea. Blocked keys, blank lines and
     * comment lines are omitted.
     */
    public static function readAllowedEnvText(): string
    {
        $file = base_path('.env');
        if (!file_exists($file)) {
            return '';
        }

        $sentinel = 'EXMENT_MARKET_TENANT_UUID';
        $past     = false;
        $lines    = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (!$past) {
                $trimmed = trim($line);
                if (strpos($trimmed, '=') !== false) {
                    [$key] = explode('=', $trimmed, 2);
                    if (strtoupper(trim($key)) === $sentinel) {
                        $past = true;
                    }
                }
                continue;
            }

            // After sentinel: skip blocked key lines, include everything else as-is
            $trimmed = trim($line);
            if (strpos($trimmed, '=') !== false && !str_starts_with($trimmed, '#')) {
                [$key] = explode('=', $trimmed, 2);
                if (self::isBlocked(trim($key))) {
                    continue;
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Parse a KEY=VALUE text block (from a textarea) and write allowed entries
     * to .env. Blocked keys are silently skipped. Existing keys are updated
     * in-place; new keys are appended at the end. Blocked keys already present
     * in .env are always preserved unchanged.
     *
     * @param  string  $text  Raw textarea content (one KEY=VALUE per line)
     * @return bool           true on success, false on write failure
     */
    public static function writeAllowedEnv(string $text): bool
    {
        $sentinel = 'EXMENT_MARKET_TENANT_UUID';

        // Build filtered replacement lines from textarea, preserving exact order.
        // Only blocked key=value lines are dropped; everything else (blanks, comments) kept.
        $replacement = [];
        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '=') !== false && !str_starts_with($trimmed, '#')) {
                [$key] = explode('=', $trimmed, 2);
                if (self::isBlocked(strtoupper(trim($key)))) {
                    continue; // silently drop blocked keys
                }
            }
            $replacement[] = $trimmed;
        }

        $file = base_path('.env');
        $currentLines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

        // Keep everything up to and including the sentinel line.
        $head          = [];
        $sentinelFound = false;

        foreach ($currentLines as $line) {
            $head[] = $line;
            $trimmed = trim($line);
            if (!$sentinelFound && strpos($trimmed, '=') !== false) {
                [$key] = explode('=', $trimmed, 2);
                if (strtoupper(trim($key)) === $sentinel) {
                    $sentinelFound = true;
                    break; // stop here; the rest is replaced
                }
            }
        }

        $output = array_merge($head, $replacement);

        return file_put_contents($file, implode("\n", $output) . "\n") !== false;
    }

    /**
     * Quote a value if it contains whitespace, quotes, hash or dollar signs.
     */
    private static function quoteValue(string $value): string
    {
        if (preg_match('/[\s"\'#$]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
