<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base for the DB-free dashboard unit tests: every SQL statement executed between
 * setUp and tearDown is recorded and FAILS the test — these tests must stay pure
 * (in-memory models + request state), so they never depend on the demo DB content
 * the DB-bound _evd/tests scripts need.
 */
abstract class DashboardUnitTestCase extends TestCase
{
    use DashboardUnitHelpers;

    /** @var string[] */
    protected $sqlSeen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqlSeen = [];
        // listen() attaches to the (lazy) connection object without opening a PDO
        DB::listen(function ($query) {
            $this->sqlSeen[] = $query->sql;
        });
    }

    protected function tearDown(): void
    {
        $seen = $this->sqlSeen;
        parent::tearDown();
        if (!empty($seen)) {
            $this->fail("unit test executed SQL:\n  " . implode("\n  ", $seen));
        }
    }
}
