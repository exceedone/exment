<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\UnavailableConversion;
use Exceedone\Exment\Tests\Support\IsolatedConversionExecutionProvider as P;

/**
 * The isolated execution provider binds execution only when every guard passes; production
 * keeps UnavailableConversion. No database access: the guards read configuration only.
 */
class IsolatedConversionExecutionProviderTest extends UnitTestBase
{
    /** @var array<string, string|false> */
    private $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([P::ENV_ENABLE, P::ENV_DATABASE, P::ENV_PORT] as $name) {
            $this->saved[$name] = getenv($name);
        }
        $connection = config('database.default');
        config([
            "database.connections.$connection.host" => '127.0.0.1',
            "database.connections.$connection.database" => 'iso_test_db',
            "database.connections.$connection.port" => '3409',
        ]);
        putenv(P::ENV_ENABLE . '=1');
        putenv(P::ENV_DATABASE . '=iso_test_db');
        putenv(P::ENV_PORT . '=3409');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
        parent::tearDown();
    }

    /** Without the switch at boot (this process), the application has the production binding. */
    public function testProductionBindingIsUnavailable(): void
    {
        $this->assertInstanceOf(UnavailableConversion::class, app(ConversionAvailability::class));
        $this->assertFalse(app(ConversionAvailability::class)->isAvailable());
        $this->assertNull(config('exment.storage_auto_index_budget'), 'default index budget stays null');
    }

    public function testEveryGuardIsRequired(): void
    {
        $connection = config('database.default');
        $this->assertTrue(P::guardsPass($this->app), 'all guards pass');

        putenv(P::ENV_ENABLE . '=0');
        $this->assertFalse(P::guardsPass($this->app), 'switch off');
        putenv(P::ENV_ENABLE . '=1');

        $env = $this->app['env'];
        $this->app['env'] = 'production';
        $this->assertFalse(P::guardsPass($this->app), 'production');
        $this->app['env'] = 'local';
        $this->assertFalse(P::guardsPass($this->app), 'not testing');
        $this->app['env'] = $env;

        config(["database.connections.$connection.host" => '10.0.0.5']);
        $this->assertFalse(P::guardsPass($this->app), 'not loopback');
        config(["database.connections.$connection.host" => '127.0.0.1']);

        putenv(P::ENV_DATABASE . '=other_test_db');
        $this->assertFalse(P::guardsPass($this->app), 'database mismatch');
        config(["database.connections.$connection.database" => 'exment']);
        putenv(P::ENV_DATABASE . '=exment');
        $this->assertFalse(P::guardsPass($this->app), 'database name without "test"');
        config(["database.connections.$connection.database" => 'iso_test_db']);
        putenv(P::ENV_DATABASE . '=iso_test_db');

        putenv(P::ENV_PORT . '=3306');
        $this->assertFalse(P::guardsPass($this->app), 'port mismatch');
        putenv(P::ENV_PORT);
        $this->assertTrue(P::guardsPass($this->app), 'port guard optional');
    }

    /** SQL Server LocalDB is a local named pipe: that exact host form passes, a remote or malformed one does not. */
    public function testLocalDbPipeHostIsLocalButNothingLikeIt(): void
    {
        $connection = config('database.default');
        putenv(P::ENV_PORT);
        foreach (['(localdb)\\MSSQLLocalDB' => true, '(localdb)\\Inst_1' => true, 'server\\SQLEXPRESS' => false,
            '(localdb)' => false, '(localdb)\\' => false, '(localdb)\\a;b' => false, 'x(localdb)\\MSSQLLocalDB' => false, '10.0.0.5' => false] as $host => $expected) {
            config(["database.connections.$connection.host" => $host]);
            $this->assertSame($expected, P::guardsPass($this->app), $host);
        }
    }

    public function testBootBindsOnlyWhenGuardsPass(): void
    {
        $this->app->instance(ConversionAvailability::class, new UnavailableConversion());
        putenv(P::ENV_ENABLE . '=0');
        (new P($this->app))->boot();
        $this->assertFalse(app(ConversionAvailability::class)->isAvailable());

        putenv(P::ENV_ENABLE . '=1');
        (new P($this->app))->boot();
        $this->assertTrue(app(ConversionAvailability::class)->isAvailable());
    }
}
