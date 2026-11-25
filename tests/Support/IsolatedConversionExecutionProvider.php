<?php

namespace Exceedone\Exment\Tests\Support;

use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Illuminate\Support\ServiceProvider;

/**
 * TEST SUPPORT ONLY - enables hybrid storage conversion execution in an ISOLATED test host, so
 * that integration runs (PHPUnit, probes, `php artisan queue:work`) can exercise the storage
 * services. It is never a production activation mechanism:
 *
 *  - it lives in the tests namespace (autoload-dev) and is not registered by Exment; a test host
 *    registers it explicitly (e.g. in its own bootstrap/providers.php);
 *  - it binds execution only when EVERY guard passes, otherwise it does nothing:
 *      1. EXMENT_TEST_ENABLE_CONVERSION=1 in the process environment;
 *      2. the application environment is `testing` (never `production`);
 *      3. the default connection's host is a loopback address (or a SQL Server LocalDB pipe host);
 *      4. the default connection's database equals EXMENT_TEST_CONVERSION_DATABASE, and that
 *         name contains "test";
 *      5. when EXMENT_TEST_CONVERSION_PORT is set, the connection's port equals it.
 *
 * Production keeps UnavailableConversion (ExmentServiceProvider). The automatic index budget is
 * not touched here: tests set their own explicit budgets.
 */
class IsolatedConversionExecutionProvider extends ServiceProvider
{
    public const ENV_ENABLE = 'EXMENT_TEST_ENABLE_CONVERSION';
    public const ENV_DATABASE = 'EXMENT_TEST_CONVERSION_DATABASE';
    public const ENV_PORT = 'EXMENT_TEST_CONVERSION_PORT';

    public function boot(): void
    {
        if (static::guardsPass($this->app)) {
            $this->app->instance(ConversionAvailability::class, new IsolatedConversionAvailability());
        }
    }

    /**
     * Whether every guard passes for this application (see the class comment).
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public static function guardsPass($app): bool
    {
        if (getenv(self::ENV_ENABLE) !== '1') {
            return false;
        }
        if (!$app->environment('testing') || $app->environment('production')) {
            return false;
        }
        $connection = config('database.connections.' . config('database.default'));
        if (!is_array($connection)) {
            return false;
        }
        $host = strtolower((string)($connection['host'] ?? ''));
        // SQL Server LocalDB is reached over a local named pipe: host "(localdb)\<instance>" (this machine only)
        $isLocalDb = (bool)preg_match('/^\(localdb\)\\\\[A-Za-z0-9_#\-]+$/', $host);
        if (!$isLocalDb && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return false;
        }
        $expected = (string)getenv(self::ENV_DATABASE);
        if ($expected === '' || stripos($expected, 'test') === false || (string)($connection['database'] ?? '') !== $expected) {
            return false;
        }
        $port = getenv(self::ENV_PORT);
        if ($port !== false && $port !== '' && (string)($connection['port'] ?? '') !== (string)$port) {
            return false;
        }
        return true;
    }
}
