<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogConnection;
use Exceedone\Exment\Services\LogManagement\LogSize;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Services\SystemRequire\Security\LogSecurity;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Artisan;

/**
 * The log tables on a connection of their own (ISS02905, EXMENT_LOG_DB_CONNECTION), and the
 * warning when the logs are larger than the limit (EXMENT_LOG_SIZE_WARN_MB).
 */
class LogDbConnectionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const CONNECTION = 'exment_log_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        LogSize::reset();
        LogCatalog::reset();
    }

    protected function tearDown(): void
    {
        config(['exment.log_db_connection' => null, 'exment.log_size_warn_mb' => LogSize::DEFAULT_WARN_MB]);
        $this->resetTables();
        LogSize::reset();
        parent::tearDown();
    }

    protected function resetTables(): void
    {
        LoginLog::resetTableExists();
        PermissionChangeLog::resetTableExists();
        BrowserLog::resetTableExists();
        DataAccessLog::resetTableExists();
    }

    public function testTheLogTablesOnAConnectionOfTheirOwn(): void
    {
        // not set, or not a connection: the default
        $this->assertNull(LogConnection::name());
        config(['exment.log_db_connection' => 'no_such_connection']);
        $this->assertNull(LogConnection::name());
        $this->assertSame(1, Artisan::call('exment:log-db'));

        // another connection (here to the same database)
        $default = strval(config('database.default'));
        config(['database.connections.' . static::CONNECTION => config('database.connections.' . $default), 'exment.log_db_connection' => static::CONNECTION]);
        $this->resetTables();
        $this->assertSame(static::CONNECTION, LogConnection::name());
        foreach ([new LoginLog(), new PermissionChangeLog(), new BrowserLog(), new DataAccessLog(), new DataAccessDaily()] as $model) {
            $this->assertSame(static::CONNECTION, $model->getConnectionName(), get_class($model));
        }
        $this->assertTrue(LoginLog::tableExists());

        // a record goes there
        $mark = 'LogDbConnectionTest' . uniqid();
        try {
            LogWriter::insert(LoginLog::class, ['result' => LoginLog::RESULT_FAILURE, 'failure_reason' => $mark, 'created_at' => now()]);
            $this->assertSame(1, \DB::connection(static::CONNECTION)->table('login_logs')->where('failure_reason', $mark)->count());
        } finally {
            \DB::connection(static::CONNECTION)->table('login_logs')->where('failure_reason', $mark)->delete();
        }

        // the command: the tables are there; the same database is not copied into itself
        $this->assertSame(0, Artisan::call('exment:log-db', ['--copy' => true]));
        $this->assertStringContainsString('nothing to copy', Artisan::output());
    }

    public function testTheSizeOfTheLogs(): void
    {
        $this->assertGreaterThanOrEqual(0, LogSize::total());
        if (in_array(\DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->assertIsInt(LogSize::logTable('login_logs'), 'the database says the size of a table');
            $types = LogCatalog::types();
            $this->assertIsInt($types['login_log']['size_bytes']);
        }

        /** @var LoginUser $admin */
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($admin);
        System::clearRequestSession();

        // under the limit: no warning
        LogSize::$total = 1024 * 1024;
        config(['exment.log_size_warn_mb' => 2]);
        $this->assertNull(LogSize::warning());
        $this->assertNotContains('too_large', (new LogSecurity())->findings());
        $this->get(admin_url('log_management'))->assertOk()->assertDontSee('log-management-size-warning', false);

        // over it
        config(['exment.log_size_warn_mb' => 0.5]);
        $this->assertNotNull(LogSize::warning());
        $this->assertContains('too_large', (new LogSecurity())->findings());
        $this->get(admin_url('log_management'))->assertOk()->assertSee('log-management-size-warning', false)->assertSee('EXMENT_LOG_DB_CONNECTION', false);

        // 0: no warning
        config(['exment.log_size_warn_mb' => 0]);
        $this->assertNull(LogSize::warning());
    }
}
