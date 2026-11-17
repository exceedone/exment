<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Middleware\CheckLoggingEnabled;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\QueryLogger;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Http\Request;

/**
 * System performance log (ISS00155): only slow requests, SQL without the values, hidden URL values.
 */
class SystemPerformanceLogTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        QueryLogger::clear();
    }

    protected function tearDown(): void
    {
        QueryLogger::clear();
        System::clearCache();
        parent::tearDown();
    }

    public function testQueryLoggerKeepsSqlWithoutValues(): void
    {
        QueryLogger::add('select * from `users` where `email` = ?', 1.5);
        QueryLogger::add('select * from `users` where `id` = ?', 12.25);

        $this->assertSame(2, QueryLogger::count());
        $this->assertEqualsWithDelta(13.75, QueryLogger::totalTime(), 0.0001);
        $this->assertEqualsWithDelta(12.25, QueryLogger::maxTime(), 0.0001);
        $this->assertSame([
            '1. [1.5ms] select * from `users` where `email` = ?',
            '2. [12.25ms] select * from `users` where `id` = ?',
        ], QueryLogger::all());

        QueryLogger::clear();
        $this->assertSame(0, QueryLogger::count());
        $this->assertSame([], QueryLogger::all());
    }

    public function testQueryLoggerKeepsAtMostMaxQueries(): void
    {
        for ($i = 0; $i < QueryLogger::MAX_QUERIES + 10; $i++) {
            QueryLogger::add('select 1', 1);
        }
        $this->assertCount(QueryLogger::MAX_QUERIES, QueryLogger::all());
        $this->assertSame(QueryLogger::MAX_QUERIES + 10, QueryLogger::count());
    }

    public function testSqlValuesAreNotSaved(): void
    {
        System::logging_toggle_available(true);
        \Exceedone\Exment\Middleware\ExmentDebug::handleLog();

        \DB::select('select ? as secret_value', ['very-secret-1346']);

        $all = implode("\n", QueryLogger::all());
        $this->assertStringContainsString('select ? as secret_value', $all);
        $this->assertStringNotContainsString('very-secret-1346', $all);
    }

    /**
     * @dataProvider urlProvider
     */
    public function testMaskUrl(string $url, string $expected): void
    {
        $this->assertSame($expected, CheckLoggingEnabled::maskUrl($url));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function urlProvider(): array
    {
        return [
            'no query' => ['http://localhost/admin/data/user', 'http://localhost/admin/data/user'],
            'safe keys are kept' => ['http://localhost/admin/data/user?view=abc&page=2&per_page=20', 'http://localhost/admin/data/user?view=abc&page=2&per_page=20'],
            'search value is hidden' => ['http://localhost/admin/search?query=yamada%40example.com&page=1', 'http://localhost/admin/search?query=***&page=1'],
            'array key is hidden' => ['http://localhost/admin/data/user?value%5Bemail%5D=a%40b.c', 'http://localhost/admin/data/user?value%5Bemail%5D=***'],
            'key without value' => ['http://localhost/admin/data/user?_pjax&x', 'http://localhost/admin/data/user?_pjax&x'],
            'fragment' => ['http://localhost/admin/data/user?q=abc#top', 'http://localhost/admin/data/user?q=***#top'],
        ];
    }

    public function testOnlySlowRequestsAreSaved(): void
    {
        System::logging_toggle_available(true);
        System::logging_slow_request_seconds(3600);
        System::logging_slow_sql_seconds(1);

        // fast request, fast SQL: not saved
        QueryLogger::add('select 1', 5);
        $this->assertNull($this->makeLog());

        // a slow SQL: saved
        QueryLogger::add('select sleep(?)', 1500);
        $log = $this->makeLog();
        $this->assertNotNull($log);
        $this->assertStringContainsString('select sleep(?)', $log['sql']);
        $this->assertStringContainsString('SQL: 1', $log['sql']);

        // a slow request: saved
        System::logging_slow_request_seconds(0);
        System::logging_slow_sql_seconds(0);
        QueryLogger::add('select 1', 1);
        $this->assertNotNull($this->makeLog(), 'both 0: every request is saved');

        System::logging_slow_request_seconds(1);
        System::logging_slow_sql_seconds(0);
        $request = Request::create('/admin/data/user', 'GET', [], [], [], ['REQUEST_TIME_FLOAT' => microtime(true) - 2]);
        // LARAVEL_START (if defined) is the start of the process; the test process has run more than 1 second
        if (defined('LARAVEL_START') && microtime(true) - LARAVEL_START < 1) {
            $this->markTestSkipped('the process started less than 1 second ago');
        }
        $this->assertNotNull($this->makeLog($request));
    }

    /**
     * @param Request|null $request
     * @return array<string, mixed>|null
     * @phpstan-impure
     */
    protected function makeLog(?Request $request = null): ?array
    {
        $request = $request ?? Request::create('/admin/data/user?query=secret', 'GET', [], [], [], ['REQUEST_TIME_FLOAT' => microtime(true)]);
        $method = new \ReflectionMethod(CheckLoggingEnabled::class, 'makeLog');
        $method->setAccessible(true);
        return $method->invoke(null, $request);
    }
}
