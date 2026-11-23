<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Middleware\LogOperation;
use Exceedone\Exment\Model\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The operation log of a request is written after the response is sent: the user does not
 * wait for the insert (ISS02823).
 */
class OperationLogAfterResponseTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        parent::tearDown();
    }

    private function rows(string $path): int
    {
        return OperationLog::query()->where('path', $path)->count();
    }

    public function testTheLogIsWrittenOnceAfterTheResponse(): void
    {
        $path = 'operation_log_after_response_' . uniqid();
        $request = Request::create('/' . $path, 'GET', ['q' => 'x']);
        $middleware = new LogOperation();

        $inserts = 0;
        \DB::listen(function ($query) use (&$inserts) {
            if (stripos($query->sql, 'insert into') === 0) {
                $inserts++;
            }
        });

        $before = now()->subSecond();
        $response = $middleware->handle($request, function () {
            return new Response('page');
        });
        $this->assertSame('page', $response->getContent());
        $this->assertSame(0, $inserts, 'nothing is written while the page is made');
        $this->assertSame(0, $this->rows($path));

        $middleware->terminate($request, $response);
        $this->assertSame(1, $this->rows($path));
        $log = OperationLog::query()->where('path', $path)->first();
        $this->assertSame('GET', $log->method);
        $this->assertTrue($log->created_at->greaterThanOrEqualTo($before), 'the time of the request');

        // the middleware of a route can be gone through twice: one row
        $middleware->terminate($request, $response);
        (new LogOperation())->terminate($request, $response);
        $this->assertSame(1, $this->rows($path));
    }

    public function testARequestThatIsNotLoggedWritesNothing(): void
    {
        $request = Request::create('/operation_log_after_response_none', 'GET');
        $middleware = new LogOperation();
        $middleware->terminate($request, new Response('page'));
        $this->assertSame(0, $this->rows('operation_log_after_response_none'));
    }
}
