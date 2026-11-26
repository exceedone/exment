<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Middleware\MarkResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every answer of Exment carries X-Exment (ISS02935): the screen tells an answer of a firewall in
 * front of the site (without it) from Exment's own 403.
 */
class MarkResponseTest extends UnitTestBase
{
    public function testAnAnswerOfExmentIsMarked(): void
    {
        $response = (new MarkResponse())->handle(Request::create('/admin', 'POST'), function () {
            return new Response('no permission', 403);
        });
        $this->assertSame('1', $response->headers->get(MarkResponse::HEADER));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAJsonAnswerIsMarkedToo(): void
    {
        $response = (new MarkResponse())->handle(Request::create('/admin/webapi/x', 'POST'), function () {
            return response()->json(['ok' => false], 403);
        });
        $this->assertSame('1', $response->headers->get(MarkResponse::HEADER));
    }

    public function testAMarkAlreadyThereIsKept(): void
    {
        $response = (new MarkResponse())->handle(Request::create('/', 'GET'), function () {
            return new Response('', 200, [MarkResponse::HEADER => '1']);
        });
        $this->assertSame('1', $response->headers->get(MarkResponse::HEADER));
    }
}
