<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Log;

/**
 * The settings of the log management that change how the logs are written (ISS02839), applied
 * when Exment boots:
 * - log_app_level: the lowest level the application log writes (debug ... error); empty: as the
 *   config of the site (LOG_LEVEL)
 * - log_app_daily: the application log in one file a day (laravel-YYYY-MM-DD.log), deleted after
 *   the days of LogCatalog's app_log
 * - operation_log_scope: what the operation log keeps: all the requests (as before), or only the
 *   ones that change something (not GET / HEAD / OPTIONS)
 */
class LogSettings
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

    public const SCOPE_ALL = 'all';
    public const SCOPE_CHANGES = 'changes';
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_CHANGES];

    /** The channels of Laravel's config/logging.php the settings change. */
    public const FILE_CHANNELS = ['single', 'daily'];

    public static function apply(): void
    {
        static::applyPrivacy();
        try {
            if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
                return;
            }
            $level = strval(System::log_app_level());
            $daily = boolval(System::log_app_daily());
        } catch (\Throwable $e) {
            return;
        }
        $changed = false;
        if (in_array($level, static::LEVELS, true)) {
            foreach (static::FILE_CHANNELS as $channel) {
                if (is_array(config("logging.channels.{$channel}"))) {
                    config(["logging.channels.{$channel}.level" => $level]);
                    $changed = true;
                }
            }
        }
        if ($daily && is_array(config('logging.channels.single')) && config('logging.channels.single.driver') === 'single') {
            // the same file name, with the date in it; nothing deleted by Monolog (days 0):
            // exment:log-maintenance deletes as the log management says
            config([
                'logging.channels.single.driver' => 'daily',
                'logging.channels.single.days' => 0,
            ]);
            $changed = true;
        }
        if ($changed) {
            foreach (array_merge(static::FILE_CHANNELS, ['stack', strval(config('logging.default'))]) as $channel) {
                try {
                    Log::forgetChannel($channel);
                } catch (\Throwable $e) {
                    // not made yet
                }
            }
        }
    }

    /**
     * The files of the application log: made with log_file_permission (0640), and tapped by
     * LogPrivacyTap (part 4). Without the database too.
     */
    public static function applyPrivacy(): void
    {
        $permission = strval(config('exment.log_file_permission', '0640'));
        $mode = preg_match('/^0?[0-7]{3}$/', $permission) === 1 ? intval(octdec($permission)) : 0640;
        $changed = false;
        foreach (static::FILE_CHANNELS as $channel) {
            if (!is_array(config("logging.channels.{$channel}"))) {
                continue;
            }
            $taps = (array) config("logging.channels.{$channel}.tap", []);
            if (!in_array(LogPrivacyTap::class, $taps, true)) {
                $taps[] = LogPrivacyTap::class;
            }
            config([
                "logging.channels.{$channel}.tap" => $taps,
                "logging.channels.{$channel}.permission" => $mode,
            ]);
            $changed = true;
        }
        if ($changed) {
            foreach (array_merge(static::FILE_CHANNELS, ['stack', strval(config('logging.default'))]) as $channel) {
                try {
                    Log::forgetChannel($channel);
                } catch (\Throwable $e) {
                    // not made yet
                }
            }
        }
    }

    public static function operationLogScope(): string
    {
        try {
            $scope = strval(System::operation_log_scope());
        } catch (\Throwable $e) {
            $scope = '';
        }
        return in_array($scope, static::SCOPES, true) ? $scope : static::SCOPE_ALL;
    }

    /** Whether the operation log keeps a request of this method. */
    public static function logsMethod(string $method): bool
    {
        if (static::operationLogScope() !== static::SCOPE_CHANGES) {
            return true;
        }
        return !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
